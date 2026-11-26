<?php

namespace App\Services\Activities;

use App\Enums\ActivityRecordStatus;
use App\Enums\ActivityType;
use App\Models\ActivityPlan;
use App\Models\ActivityRecord;
use App\Models\GymWorkout;
use App\Models\RunningProgram;
use App\Services\GymPlanService;
use App\Support\Activities\PlanRules;
use App\Support\Activities\RunningLadder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * O planejador da semana: musculação, aeróbico e descanso, partindo do que a pessoa REALMENTE fez. Regras fixas (App\Support\Activities\
 * PlanRules), sem IA. Não grava nada: quem aplica é o ProgramUpdateService, com a aprovação da pessoa. A progressão anda pelo que foi feito,
 * e não pelo calendário: semana sem o aeróbico planejado não aumenta a seguinte.
 */
class WeekPlannerService
{
    public const GOALS = ['weight_loss' => 'Perder peso', 'health' => 'Saúde e condicionamento'];

    public function __construct(
        private readonly ActivityService $activities,
        private readonly GymPlanService $plans,
    ) {}

    /** Os aeróbicos que o plano sabe encaixar. Corrida tem programa próprio, com as regras dela. */
    public static function aerobicTypes(): array
    {
        return [ActivityType::Walking, ActivityType::Cycling, ActivityType::Swimming, ActivityType::Elliptical];
    }

    /**
     * O ponto de partida: a média semanal das 4 semanas encerradas, no diário; sem diário, o que a pessoa disse que costuma fazer.
     *
     * @return array{sport: int, other: int, total: int, source: string, weeks: int}
     */
    public function baseline(?CarbonImmutable $hoje = null): array
    {
        $hoje ??= CarbonImmutable::today();
        $atual = ActivityWeekService::weekStart($hoje);
        $this->activities->syncGymSessions($atual->subWeeks(4), $hoje);

        $registros = ActivityRecord::query()->where('status', ActivityRecordStatus::Done->value)
            ->whereDate('done_on', '>=', $atual->subWeeks(4)->toDateString())->whereDate('done_on', '<', $atual->toDateString())->get();

        if ($registros->isNotEmpty()) {
            // Conta só as semanas desde o primeiro registro: quem começou há duas semanas não tem a média dividida por quatro.
            $primeira = ActivityWeekService::weekStart(CarbonImmutable::parse($registros->min('done_on')));
            $semanas = max(1, (int) round($primeira->diffInDays($atual) / 7));
            $esporte = $registros->filter(fn (ActivityRecord $r) => $r->activity->isSport())->sum('minutes') / $semanas;
            $outro = $registros->filter(fn (ActivityRecord $r) => $r->activity->isAerobic() && ! $r->activity->isSport())->sum('minutes') / $semanas;

            return ['sport' => self::round5($esporte), 'other' => self::round5($outro), 'total' => self::round5($esporte) + self::round5($outro), 'source' => 'diary', 'weeks' => $semanas];
        }

        $esporte = 0;
        $outro = 0;

        foreach ($this->activities->commitments() as $c) {
            if (! $c->activity->isAerobic()) {
                continue;
            }

            $minutos = $c->minutes * ($c->isFixed() ? 1 : (int) $c->times_per_week);
            $c->activity->isSport() ? $esporte += $minutos : $outro += $minutos;
        }

        return ['sport' => self::round5($esporte), 'other' => self::round5($outro), 'total' => self::round5($esporte) + self::round5($outro), 'source' => 'profile', 'weeks' => 0];
    }

    public function target(string $objetivo, int $base, bool $leve): int
    {
        if ($leve) {
            return $base > 0 ? $base : PlanRules::LIGHT_MINUTES;
        }

        // Quem já passa do teto não aumenta.
        if ($base >= PlanRules::CEILING) {
            return $base;
        }

        $alvo = $objetivo === 'weight_loss' ? max(PlanRules::WHO_MINIMUM, $base + PlanRules::WEIGHT_LOSS_EXTRA) : max(PlanRules::WHO_MINIMUM, $base);

        return self::round5(min(PlanRules::CEILING, $alvo));
    }

    /** Os minutos de aeróbico do N-ésimo passo (1 = a primeira semana). */
    public function minutesAt(int $passo, int $base, int $alvo, bool $leve): int
    {
        if ($leve) {
            return $alvo;
        }

        $atual = $base;
        $semana = $base;

        for ($s = 1; $s <= $passo; $s++) {
            if ($s % PlanRules::DELOAD_EVERY === 0) {
                $semana = self::round5($atual * (100 - PlanRules::DELOAD_PERCENT) / 100);

                continue;
            }

            $atual = min($alvo, $atual + PlanRules::MAX_WEEKLY_INCREASE);
            $semana = $atual;
        }

        return $semana;
    }

    /** @return list<array{week: int, minutes: int, deload: bool}> */
    public function progression(int $base, int $alvo, bool $leve): array
    {
        return array_map(fn (int $s) => ['week' => $s, 'minutes' => $this->minutesAt($s, $base, $alvo, $leve), 'deload' => ! $leve && $s % PlanRules::DELOAD_EVERY === 0], range(1, PlanRules::WEEKS_SHOWN));
    }

    /** @return array<int, int> dia da semana => minutos de esporte com dia fixo */
    public function sportDays(): array
    {
        $dias = [];

        foreach ($this->activities->commitments() as $c) {
            if ($c->isFixed() && $c->activity->isSport()) {
                $dias[$c->weekday] = ($dias[$c->weekday] ?? 0) + $c->minutes;
            }
        }

        return $dias;
    }

    /** @return array<int, int> dia da semana => minutos do programa de caminhada e corrida em andamento (ele tem as regras dele) */
    public function runningDays(): array
    {
        $programa = RunningProgram::query()->where('is_active', true)->latest('created_at')->first();

        return $programa === null ? [] : collect((array) $programa->weekdays)->mapWithKeys(fn ($d) => [(int) $d => RunningLadder::minutes($programa->step)])->all();
    }

    /**
     * Onde entram a musculação e o aeróbico entre os dias disponíveis. Os treinos de pernas fogem da véspera do esporte, e sempre sobra ao menos
     * um dia sem nada.
     *
     * @param  list<int>  $disponiveis  0 (segunda) a 6 (domingo)
     * @return array{strength: list<int>, aerobic: list<int>, leg_eve: int}
     */
    public function layout(array $disponiveis, bool $leve = false): array
    {
        $esportes = array_keys($this->sportDays());
        $livres = array_values(array_diff(array_unique(array_map('intval', $disponiveis)), $esportes));
        sort($livres);

        $corrida = array_keys($this->runningDays());

        // Deixa um dia de descanso na semana.
        $maximoDeDias = max(0, 6 - count(array_unique([...$esportes, ...$corrida])));
        $fase = $this->plans->activePlan();
        $treinos = $fase !== null ? GymWorkout::query()->where('plan_id', $fase->id)->with('workoutExercises.exercise')->orderBy('position')->get() : collect();
        $quantos = $treinos->isEmpty() ? 0 : max(PlanRules::STRENGTH_DAYS_MIN, min(PlanRules::STRENGTH_DAYS_MAX, $treinos->count()));
        $quantos = min($quantos, count($livres), $maximoDeDias);

        $melhor = [];
        $custo = null;
        $vesperas = 0;
        $primeiro = $fase !== null && $treinos->isNotEmpty() ? max(0, (int) $treinos->values()->search(fn (GymWorkout $t) => $t->id === $this->plans->nextWorkout($fase)?->id)) : 0;

        foreach (self::combinations($livres, $quantos) as $combinacao) {
            $v = 0;

            foreach ($combinacao as $i => $dia) {
                if ($this->isLegWorkout($treinos[($primeiro + $i) % max(1, $treinos->count())] ?? null) && in_array($dia + 1, $esportes, true)) {
                    $v++;
                }
            }

            $colados = count(array_filter($combinacao, fn (int $d) => in_array($d + 1, $combinacao, true)));
            $c = $v * 10 + $colados * 2;

            if ($custo === null || $c < $custo) {
                [$melhor, $custo, $vesperas] = [$combinacao, $c, $v];
            }
        }

        $aerobico = array_values(array_diff($livres, $melhor, $corrida));

        // No plano leve bastam dois dias de aeróbico.
        $limite = $leve ? min(2, max(0, $maximoDeDias - count($melhor))) : max(0, $maximoDeDias - count($melhor));
        $aerobico = array_slice($aerobico, 0, $limite);

        return ['strength' => $melhor, 'aerobic' => $aerobico, 'leg_eve' => $vesperas];
    }

    /**
     * Distribui os minutos que não vêm do esporte nem da corrida: um pouco no fim da musculação e o resto nos dias de aeróbico.
     *
     * @param  list<int>  $forca
     * @param  list<int>  $aerobico
     * @param  list<string>  $tipos
     * @return list<array{weekday: int, type: ActivityType, minutes: int, finisher: bool}>
     */
    public function sessions(int $minutosDaSemana, int $minutosDeEsporte, array $forca, array $aerobico, array $tipos, bool $leve): array
    {
        $resto = max(0, $minutosDaSemana - $minutosDeEsporte);
        $tipos = array_values(array_filter(array_map(fn (string $t) => ActivityType::tryFrom($t), $tipos), fn (?ActivityType $t) => $t !== null && in_array($t, self::aerobicTypes(), true)))
            ?: [ActivityType::Walking];
        $saida = [];

        if (! $leve && $aerobico !== []) {
            foreach ($forca as $dia) {
                $m = min(PlanRules::FINISHER_MINUTES, $resto);

                if ($m > 0) {
                    $saida[] = ['weekday' => (int) $dia, 'type' => $tipos[0], 'minutes' => $m, 'finisher' => true];
                    $resto -= $m;
                }
            }
        }

        // Sem dia só de aeróbico, tudo vai para o fim da musculação.
        $destino = $aerobico !== [] ? $aerobico : $forca;
        $quantos = count($destino);

        foreach (array_values($destino) as $i => $dia) {
            $m = min(PlanRules::SESSION_MAX, (int) round($resto / ($quantos - $i)));
            $resto -= $m;

            if ($m > 0) {
                $saida[] = ['weekday' => (int) $dia, 'type' => $tipos[$i % count($tipos)], 'minutes' => $m, 'finisher' => $aerobico === []];
            }
        }

        usort($saida, fn (array $a, array $b) => $a['weekday'] <=> $b['weekday']);

        return $saida;
    }

    /**
     * A proposta completa, antes de qualquer coisa ser gravada.
     *
     * @param  list<int>  $disponiveis
     * @param  list<string>  $tipos
     * @return array{goal: string, light: bool, baseline: array<string, mixed>, target: int, layout: array<string, mixed>, week: list<array<string, mixed>>, progression: list<array<string, mixed>>, sport_days: array<int, int>, missing_minutes: int}
     */
    public function proposal(string $objetivo, array $disponiveis, array $tipos, bool $leve): array
    {
        $base = $this->baseline();
        $alvo = $this->target($objetivo, $base['total'], $leve);
        $divisao = $this->layout($disponiveis, $leve);
        $primeira = $this->minutesAt(1, $base['total'], $alvo, $leve);
        $corrida = $this->runningDays();
        $foraDoPlano = $base['sport'] + array_sum($corrida);
        $sessoes = $this->sessions($primeira, $foraDoPlano, $divisao['strength'], $divisao['aerobic'], $tipos, $leve);

        return [
            'goal' => $objetivo,
            'light' => $leve,
            'baseline' => $base,
            'target' => $alvo,
            'layout' => $divisao,
            'week' => $sessoes,
            'progression' => $this->progression($base['total'], $alvo, $leve),
            'sport_days' => $this->sportDays(),
            'running_days' => $corrida,
            // O que não coube nos dias marcados.
            'missing_minutes' => max(0, $primeira - $foraDoPlano - array_sum(array_column($sessoes, 'minutes'))),
        ];
    }

    public function active(): ?ActivityPlan
    {
        return ActivityPlan::query()->where('is_active', true)->latest('created_at')->first();
    }

    /**
     * Em que passo da progressão o plano está na semana atual: anda um a cada semana encerrada que teve ao menos 80% do aeróbico planejado.
     *
     * @return array{step: int, history: list<array{week_start: CarbonImmutable, planned: int, done: int, counted: bool}>}
     */
    public function progress(ActivityPlan $plano, ?CarbonImmutable $hoje = null): array
    {
        $hoje ??= CarbonImmutable::today();
        $atual = ActivityWeekService::weekStart($hoje);
        $semana = ActivityWeekService::weekStart($plano->started_on->toImmutable());
        $passo = 1;
        $historico = [];

        for ($n = 0; $n < 104 && $semana->lt($atual); $n++, $semana = $semana->addWeek()) {
            $planejado = $this->minutesAt($passo, $plano->base_minutes, $plano->target_minutes, $plano->is_light);
            $feito = (int) $this->aerobicRecords($semana)->sum('minutes');
            $contou = $feito * 100 >= $planejado * PlanRules::ADHERENCE_PERCENT;
            $historico[] = ['week_start' => $semana, 'planned' => $planejado, 'done' => $feito, 'counted' => $contou];

            if ($contou) {
                $passo++;
            }
        }

        return ['step' => $passo, 'history' => array_reverse($historico)];
    }

    /** As sessões do plano em uma semana (a atual ou futura). Semana futura não supõe que a progressão vai andar. */
    public function sessionsFor(ActivityPlan $plano, CarbonImmutable $inicio): array
    {
        $passo = $this->progress($plano)['step'];
        $minutos = $this->minutesAt($passo, $plano->base_minutes, $plano->target_minutes, $plano->is_light);

        $corrida = $this->runningDays();
        $aerobico = array_values(array_diff((array) $plano->aerobic_days, array_keys($corrida)));

        return $this->sessions($minutos, $plano->sport_minutes + array_sum($corrida), (array) $plano->strength_days, $aerobico, (array) $plano->aerobic_types, $plano->is_light);
    }

    /** @return Collection<int, ActivityRecord> */
    public function aerobicRecords(CarbonImmutable $inicio): Collection
    {
        return ActivityRecord::query()->where('status', ActivityRecordStatus::Done->value)
            ->whereDate('done_on', '>=', $inicio->toDateString())->whereDate('done_on', '<=', $inicio->addDays(6)->toDateString())->get()
            ->filter(fn (ActivityRecord $r) => $r->activity->isAerobic())->values();
    }

    private function isLegWorkout(?GymWorkout $treino): bool
    {
        if ($treino === null || $treino->workoutExercises->isEmpty()) {
            return false;
        }

        return $treino->workoutExercises->filter(fn ($i) => in_array($i->exercise?->muscle_group?->value, ['legs', 'glutes'], true))->count() * 2 >= $treino->workoutExercises->count();
    }

    /**
     * @param  list<int>  $itens
     * @return list<list<int>> as combinações de $n itens, na ordem
     */
    private static function combinations(array $itens, int $n): array
    {
        if ($n <= 0) {
            return [[]];
        }

        $saida = [];

        foreach ($itens as $i => $item) {
            foreach (self::combinations(array_slice($itens, $i + 1), $n - 1) as $resto) {
                $saida[] = [$item, ...$resto];
            }
        }

        return $saida;
    }

    private static function round5(float|int $v): int
    {
        return (int) (round($v / 5) * 5);
    }
}
