<?php

namespace App\Services\Activities;

use App\Enums\ActivityRecordStatus;
use App\Enums\ActivityType;
use App\Enums\LimitationStatus;
use App\Models\ActivityRecord;
use App\Models\PhysicalLimitation;
use App\Models\RunningProgram;
use App\Models\RunningProgramWeek;
use App\Support\Activities\RunningLadder;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * O programa de caminhada e corrida. Avança só com a semana completa e sem aumento de dor; falta repete o passo; dor maior volta um. Com uma
 * limitação que recomende aguardar, o Cerne diz isso, e se a pessoa escolher começar assim mesmo a escolha fica registrada.
 *
 * As perguntas de prontidão vêm antes e não têm "montar assim mesmo": com algum "sim" e sem liberação médica, o programa não começa nem avança.
 */
class RunningService
{
    public const OUTCOMES = ['advance' => 'Avançar um passo', 'repeat' => 'Repetir o passo', 'back' => 'Voltar um passo'];

    public function __construct(private readonly PlanReviewService $review, private readonly ReadinessService $readiness) {}

    public function active(): ?RunningProgram
    {
        return RunningProgram::query()->where('is_active', true)->latest('created_at')->first();
    }

    /** Por que o Cerne recomenda aguardar, ou nulo. */
    public function block(): ?string
    {
        foreach ($this->review->considered() as $l) {
            /** @var PhysicalLimitation $l */
            if ($l->runningAnswer() === 'yes') {
                continue;
            }

            if ($l->runningAnswer() === 'not_yet') {
                return 'O profissional respondeu que ainda não é hora de correr ('.mb_strtolower($l->regionLabel()).'). Recomendo aguardar.';
            }

            if ($l->status === LimitationStatus::Investigating) {
                return 'Há uma limitação em investigação ('.mb_strtolower($l->regionLabel()).'). Recomendo aguardar a liberação do profissional antes de começar a correr.';
            }
        }

        return null;
    }

    /**
     * @param  list<int>  $diasDaSemana  um para cada sessão
     */
    public function start(int $teste, int $vezes, array $diasDaSemana, int $dorDeHoje, bool $mesmoAssim = false, ?CarbonImmutable $hoje = null): RunningProgram
    {
        $hoje ??= CarbonImmutable::today();
        $vezes = max(1, min(3, $vezes));
        $dias = collect($diasDaSemana)->map(fn ($d) => (int) $d)->filter(fn (int $d) => $d >= 0 && $d <= 6)->unique()->sort()->values()->all();

        if (count($dias) !== $vezes) {
            throw new DomainException('Escolha '.$vezes.' dias da semana, um para cada sessão.');
        }

        if (($porta = $this->readiness->gate()) !== null) {
            throw new DomainException($porta);
        }

        $bloqueio = $this->block();

        if ($bloqueio !== null && ! $mesmoAssim) {
            throw new DomainException($bloqueio);
        }

        return DB::transaction(function () use ($teste, $vezes, $dias, $dorDeHoje, $bloqueio, $hoje): RunningProgram {
            RunningProgram::query()->where('is_active', true)->update(['is_active' => false, 'ended_on' => $hoje->toDateString()]);

            return RunningProgram::create([
                'is_active' => true,
                'step' => RunningLadder::startFor($teste),
                'times_per_week' => $vezes,
                'weekdays' => $dias,
                'baseline_pain' => max(0, min(10, $dorDeHoje)),
                'chose_despite_limitation_at' => $bloqueio !== null ? now() : null,
                'started_on' => $hoje->toDateString(),
            ]);
        });
    }

    public function stop(RunningProgram $programa): void
    {
        $programa->update(['is_active' => false, 'ended_on' => CarbonImmutable::today()->toDateString()]);
    }

    /**
     * A semana que falta fechar: a anterior, se o programa já rodava nela e ela não foi fechada; senão a atual, quando todas as sessões já
     * estão feitas.
     *
     * @return array{week_start: CarbonImmutable, done: int, expected: int, pain: ?int, suggestion: string, reason: string}|null
     */
    public function weekToClose(RunningProgram $programa, ?CarbonImmutable $hoje = null): ?array
    {
        $hoje ??= CarbonImmutable::today();
        $atual = ActivityWeekService::weekStart($hoje);
        $inicioDoPrograma = ActivityWeekService::weekStart($programa->started_on->toImmutable());
        $fechadas = $programa->weeks()->pluck('week_start')->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())->all();
        $anterior = $atual->subWeek();

        if ($anterior->gte($inicioDoPrograma) && ! in_array($anterior->toDateString(), $fechadas, true)) {
            $resumo = $this->weekSummary($programa, $anterior);

            // A semana em que o programa começou no meio pode não ter nenhuma sessão: não há o que avaliar.
            if ($resumo['done'] > 0 || $anterior->gt($inicioDoPrograma)) {
                return $resumo;
            }
        }

        if (in_array($atual->toDateString(), $fechadas, true)) {
            return null;
        }

        $resumo = $this->weekSummary($programa, $atual);

        return $resumo['done'] >= $resumo['expected'] ? $resumo : null;
    }

    /** @return array{week_start: CarbonImmutable, done: int, expected: int, pain: ?int, suggestion: string, reason: string} */
    public function weekSummary(RunningProgram $programa, CarbonImmutable $semana): array
    {
        $registros = ActivityRecord::query()->where('status', ActivityRecordStatus::Done->value)
            ->whereDate('done_on', '>=', $semana->toDateString())->whereDate('done_on', '<=', $semana->addDays(6)->toDateString())
            ->where(fn ($q) => $q->where('activity', ActivityType::Running->value)->orWhere('planned_key', 'like', 'k:'.$programa->id.':%'))
            ->get();

        $feitas = $registros->count();
        $dor = $registros->whereNotNull('pain')->max('pain');

        [$sugestao, $motivo] = match (true) {
            $dor !== null && $dor > $programa->baseline_pain => ['back', 'A dor passou da que você tinha ao começar ('.$programa->baseline_pain.'). Se ela continuar, procure o profissional.'],
            $feitas < $programa->times_per_week => ['repeat', 'A semana ficou com '.$feitas.' de '.$programa->times_per_week.' sessões.'],
            $dor === null => ['repeat', 'Sem nota de dor não dá para saber como o corpo respondeu. Informe a dor nas sessões ou escolha o que fazer.'],
            default => ['advance', 'Semana completa, sem aumento de dor.'],
        };

        if ($sugestao === 'advance' && ($porta = $this->readiness->gate()) !== null) {
            [$sugestao, $motivo] = ['repeat', $porta];
        }

        return ['week_start' => $semana, 'done' => $feitas, 'expected' => $programa->times_per_week, 'pain' => $dor === null ? null : (int) $dor, 'suggestion' => $sugestao, 'reason' => $motivo];
    }

    /** Fecha a semana que está esperando, com a escolha da pessoa. Uma vez só por semana. */
    public function closeWeek(RunningProgram $programa, string $resultado, ?CarbonImmutable $hoje = null): void
    {
        $semana = $this->weekToClose($programa, $hoje);

        if ($semana === null || ! isset(self::OUTCOMES[$resultado])) {
            throw new DomainException('Não há semana para fechar agora.');
        }

        if ($resultado === 'advance' && ($porta = $this->readiness->gate()) !== null) {
            throw new DomainException($porta);
        }

        $antes = $programa->step;
        $depois = RunningLadder::clamp($antes + ['advance' => 1, 'repeat' => 0, 'back' => -1][$resultado]);

        try {
            DB::transaction(function () use ($programa, $semana, $resultado, $antes, $depois): void {
                RunningProgramWeek::create([
                    'program_id' => $programa->id, 'week_start' => $semana['week_start']->toDateString(), 'outcome' => $resultado,
                    'step_before' => $antes, 'step_after' => $depois,
                ]);
                $programa->update(['step' => $depois]);
            });
        } catch (UniqueConstraintViolationException) {
            // Fechada em outra aba: não anda dois passos.
        }
    }
}
