<?php

namespace App\Services;

use App\Enums\HealthAppointmentStatus;
use App\Enums\HealthRoutineStatus;
use App\Models\HealthAppointment;
use App\Models\HealthExamRequest;
use App\Models\HealthRoutine;
use App\Models\ProfileMember;
use App\Models\User;
use App\Notifications\HealthRoutineDue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Rotinas preventivas (check-up). A "última vez" é a mais recente entre a informada à mão e a última consulta ou exame REALIZADO ligado à rotina;
 * a próxima é a última mais o intervalo, gravada para a rotina agendada achar o que vence por índice. O histórico é agrupado em ciclos do
 * tamanho do intervalo (ano, semestre, trimestre ou blocos de 2 e 3 anos), para a pessoa ver se está fazendo sempre.
 */
class HealthRoutineService
{
    /** Quantos dias antes da próxima data a rotina passa a "Vencendo" (e sai o primeiro aviso). */
    public const DUE_SOON_DAYS = 60;

    /** Quantos ciclos a tela mostra (os mais recentes). */
    private const MAX_CYCLES = 6;

    /** @param  array{name: string, interval_months: int, last_done_on?: ?string}  $dados */
    public function create(ProfileMember $membro, array $dados, ?ProfileMember $autor): HealthRoutine
    {
        $rotina = HealthRoutine::create([
            'member_id' => $membro->id,
            'name' => trim($dados['name']),
            'interval_months' => $this->interval($dados['interval_months']),
            'last_done_on' => ($dados['last_done_on'] ?? null) ?: null,
            'is_active' => true,
            'created_by_member_id' => $autor?->id,
        ]);

        $this->refresh($rotina->id);

        return $rotina->refresh();
    }

    /** @param  array{name: string, interval_months: int, last_done_on?: ?string}  $dados */
    public function update(HealthRoutine $rotina, array $dados): HealthRoutine
    {
        $rotina->update([
            'name' => trim($dados['name']),
            'interval_months' => $this->interval($dados['interval_months']),
            'last_done_on' => ($dados['last_done_on'] ?? null) ?: null,
        ]);

        $this->refresh($rotina->id);

        return $rotina->refresh();
    }

    /** Os compromissos e pedidos ficam (a ligação se desfaz): apagar a rotina não apaga histórico de saúde. */
    public function delete(HealthRoutine $rotina): void
    {
        $rotina->delete();
    }

    /** Liga um compromisso que já existe. Só da mesma pessoa. */
    public function link(HealthRoutine $rotina, HealthAppointment $consulta): void
    {
        if ($consulta->member_id !== $rotina->member_id) {
            throw new InvalidArgumentException('Este compromisso é de outra pessoa.');
        }

        $consulta->update(['routine_id' => $rotina->id]);
    }

    public function unlink(HealthAppointment $consulta): void
    {
        $consulta->update(['routine_id' => null]);
    }

    /** @param  list<?string>  $ids */
    public function refreshMany(array $ids): void
    {
        foreach (array_unique(array_filter($ids)) as $id) {
            $this->refresh($id);
        }
    }

    /**
     * Refaz a próxima data. Grava por consulta direta, sem os escopos nem os eventos do model: quem dispara é a mudança num compromisso (que já
     * passou pela trava de quem pode escrever), e a rotina agendada não tem perfil ativo.
     */
    public function refresh(string $rotinaId): void
    {
        $rotina = HealthRoutine::withoutGlobalScopes()->find($rotinaId);

        if ($rotina === null) {
            return;
        }

        $ultima = $this->lastDone($rotina);
        $proxima = $ultima?->addMonthsNoOverflow($rotina->interval_months)->toDateString();

        HealthRoutine::withoutGlobalScopes()->whereKey($rotina->id)->update(['next_due_on' => $proxima]);
    }

    public function lastDone(HealthRoutine $rotina): ?CarbonImmutable
    {
        $realizada = HealthAppointment::withoutGlobalScopes()
            ->where('routine_id', $rotina->id)
            ->where('status', HealthAppointmentStatus::Done->value)
            ->max('scheduled_at');

        $datas = array_filter([
            $rotina->last_done_on !== null ? CarbonImmutable::parse($rotina->last_done_on->toDateString()) : null,
            $realizada !== null ? CarbonImmutable::parse($realizada)->startOfDay() : null,
        ]);

        return $datas === [] ? null : collect($datas)->sortDesc()->first();
    }

    public function status(HealthRoutine $rotina, ?CarbonImmutable $hoje = null): HealthRoutineStatus
    {
        $hoje ??= CarbonImmutable::today();

        if ($rotina->next_due_on === null) {
            return HealthRoutineStatus::Never;
        }

        $proxima = CarbonImmutable::parse($rotina->next_due_on->toDateString());

        return match (true) {
            $proxima->lt($hoje->startOfDay()) => HealthRoutineStatus::Late,
            $proxima->lte($hoje->addDays(self::DUE_SOON_DAYS)) => HealthRoutineStatus::Due,
            default => HealthRoutineStatus::Ok,
        };
    }

    /**
     * O histórico por ciclo, do mais recente para o mais antigo.
     *
     * @param  Collection<int, HealthAppointment>  $consultas  as ligadas à rotina, já lidas sob o escopo de quem olha
     * @param  Collection<int, HealthExamRequest>  $pedidos  os pedidos sem data ligados à rotina
     * @return list<array{label: string, state: string, summary: string}>  state: done, progress, open, missed
     */
    public function cycles(HealthRoutine $rotina, Collection $consultas, Collection $pedidos, ?CarbonImmutable $hoje = null): array
    {
        $hoje ??= CarbonImmutable::today();
        $tamanho = max(3, $rotina->interval_months);

        $datas = $consultas->map(fn (HealthAppointment $c) => CarbonImmutable::instance($c->scheduled_at))
            ->when($rotina->last_done_on !== null, fn (Collection $d) => $d->push(CarbonImmutable::parse($rotina->last_done_on->toDateString())));

        $primeira = $datas->sort()->first() ?? $hoje;
        // Os blocos contam a partir de janeiro do ano do registro mais antigo: "2026", "1º semestre 2026", "2025 e 2026".
        $ancora = CarbonImmutable::create($primeira->year, 1, 1);
        $indice = fn (CarbonImmutable $d): int => intdiv(($d->year - $ancora->year) * 12 + $d->month - 1, $tamanho);

        $atual = $indice($hoje);
        $ultimo = max($atual, $datas->isEmpty() ? $atual : $indice($datas->sort()->last()));
        $ciclos = [];

        for ($i = $ultimo; $i >= max(0, $ultimo - self::MAX_CYCLES + 1); $i--) {
            $inicio = $ancora->addMonthsNoOverflow($i * $tamanho);
            $noCiclo = $consultas->filter(fn (HealthAppointment $c) => $indice(CarbonImmutable::instance($c->scheduled_at)) === $i);
            $manual = $rotina->last_done_on !== null && $indice(CarbonImmutable::parse($rotina->last_done_on->toDateString())) === $i;
            $abertos = $i === $atual ? $pedidos->whereNull('appointment_id')->count() : 0;
            $agendados = $noCiclo->where('status', HealthAppointmentStatus::Scheduled)->count();
            $realizados = $noCiclo->where('status', HealthAppointmentStatus::Done)->count();

            $estado = match (true) {
                $abertos > 0 || ($i >= $atual && $agendados > 0) => 'progress',
                $realizados > 0 || $manual => 'done',
                $i >= $atual => 'open',
                default => 'missed',
            };

            $partes = array_filter([
                $realizados > 0 ? $realizados.($realizados === 1 ? ' realizado' : ' realizados') : null,
                $agendados > 0 ? $agendados.($agendados === 1 ? ' marcado' : ' marcados') : null,
                $abertos > 0 ? $abertos.($abertos === 1 ? ' pedido sem data' : ' pedidos sem data') : null,
                $realizados === 0 && $manual ? 'feito (informado por você)' : null,
            ]);

            $ciclos[] = [
                'label' => $this->cycleLabel($inicio, $tamanho),
                'state' => $estado,
                'summary' => $partes !== [] ? implode(', ', $partes) : 'Nada ligado a este ciclo',
            ];
        }

        return $ciclos;
    }

    private function cycleLabel(CarbonImmutable $inicio, int $meses): string
    {
        return match ($meses) {
            3 => (intdiv($inicio->month - 1, 3) + 1).'º trimestre de '.$inicio->year,
            6 => ($inicio->month <= 6 ? '1º' : '2º').' semestre de '.$inicio->year,
            12 => (string) $inicio->year,
            24 => $inicio->year.' e '.($inicio->year + 1),
            36 => $inicio->year.' a '.($inicio->year + 2),
            default => $inicio->format('m/Y').' a '.$inicio->addMonthsNoOverflow($meses - 1)->format('m/Y'),
        };
    }

    /**
     * Avisa 60 dias antes e no dia em que a rotina vence (roda no cron, sem ProfileContext). Quem já tem um compromisso marcado ligado à rotina não é
     * incomodado. Idempotente: um aviso por pessoa, por rotina, por dia.
     */
    public function notifyDue(?CarbonImmutable $hoje = null): int
    {
        $hoje ??= CarbonImmutable::today();
        $datas = [$hoje->toDateString(), $hoje->addDays(self::DUE_SOON_DAYS)->toDateString()];
        $notificados = 0;

        HealthRoutine::withoutGlobalScopes()
            ->where('is_active', true)
            ->whereIn('next_due_on', $datas)
            ->with('member')
            ->chunkById(200, function (Collection $rotinas) use (&$notificados, $hoje): void {
                foreach ($rotinas as $rotina) {
                    $marcado = HealthAppointment::withoutGlobalScopes()
                        ->where('routine_id', $rotina->id)
                        ->where('status', HealthAppointmentStatus::Scheduled->value)
                        ->where('scheduled_at', '>=', $hoje->toDateString())
                        ->exists();

                    if ($marcado) {
                        continue;
                    }

                    foreach (app(HealthReminderRecipients::class)->forProfile($rotina->profile_id, 'appointments', $rotina->member_id) as $user) {
                        if ($this->alreadyNotifiedToday($user, $rotina->id)) {
                            continue;
                        }

                        $user->notify(HealthRoutineDue::forRoutine($rotina, $hoje));
                        $notificados++;
                    }
                }
            });

        return $notificados;
    }

    private function alreadyNotifiedToday(User $user, string $rotinaId): bool
    {
        return $user->notifications()
            ->where('type', HealthRoutineDue::class)
            ->whereJsonContains('data->health_routine_id', $rotinaId)
            ->whereDate('created_at', today())
            ->exists();
    }

    private function interval(int|string $meses): int
    {
        $meses = (int) $meses;

        if (! array_key_exists($meses, HealthRoutine::INTERVALS)) {
            throw new InvalidArgumentException('Escolha de quanto em quanto tempo a rotina se repete.');
        }

        return $meses;
    }
}
