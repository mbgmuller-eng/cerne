<?php

namespace App\Services\Activities;

use App\Enums\GymGoal;
use App\Enums\GymLevel;
use App\Enums\GymMeasureType;
use App\Models\ActivityPlan;
use App\Models\GymPlan;
use App\Models\GymPlanAdjustment;
use App\Models\GymSession;
use App\Models\GymWorkout;
use App\Models\GymWorkoutExercise;
use App\Services\GymPlanService;
use App\Services\GymWorkoutGenerator;
use App\Support\GymExerciseClassifier;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * "Atualizar meu programa": em vez de começar do zero, mantém os exercícios (e o histórico de cada um) e propõe o plano da semana, a mudança
 * de séries e repetições do objetivo e o que as limitações pedem para pausar. A pessoa aprova item por item, e tudo pode ser desfeito.
 */
class ProgramUpdateService
{
    public function __construct(
        private readonly WeekPlannerService $planner,
        private readonly ReadinessService $readiness,
        private readonly PlanReviewService $review,
        private readonly ActivityService $activities,
        private readonly GymPlanService $gym,
        private readonly GymWorkoutGenerator $generator,
    ) {}

    /**
     * As mudanças de séries, repetições e descanso do objetivo, por treino. Só no objetivo de perder peso, nunca no plano leve, e nunca no
     * que o profissional indicou. O exercício continua o mesmo.
     *
     * @return array<string, array{workout_id: string, name: string, items: list<array{item_id: string, name: string, from: string, to: string, meta: array<string, mixed>}>}>
     */
    public function prescriptionChanges(string $objetivo, bool $leve): array
    {
        $fase = $this->gym->activePlan();

        if ($fase === null || $objetivo !== 'weight_loss' || $leve || $fase->goal === GymGoal::WeightLoss) {
            return [];
        }

        // A mesma regra do treino automático, para a fase como ela é (nível, equipamento, esporte), com o objetivo de emagrecer.
        $comoSeria = new GymPlan([
            'goal' => GymGoal::WeightLoss, 'level' => $fase->level ?? GymLevel::Intermediate, 'days_per_week' => $fase->days_per_week,
            'session_minutes' => $fase->session_minutes, 'equipment_access' => $fase->equipment_access, 'sport' => $fase->sport,
            'sport_days' => $fase->sport_days, 'season' => $fase->season,
        ]);
        $indicados = GymPlanAdjustment::query()->where('plan_id', $fase->id)->where('kind', GymPlanAdjustment::ADDED)->whereNull('reverted_at')->get()
            ->map(fn (GymPlanAdjustment $a) => $a->workout_id.'|'.$a->exercise_id)->all();
        $saida = [];

        foreach (GymWorkout::query()->where('plan_id', $fase->id)->with('workoutExercises.exercise')->orderBy('position')->get() as $treino) {
            $itens = [];

            foreach ($treino->workoutExercises as $item) {
                $e = $item->exercise;

                if ($e->measure_type !== GymMeasureType::LoadReps || in_array($e->muscle_group->value, ['cardio', 'core'], true)
                    || GymExerciseClassifier::isConditioning($e->name) || in_array($treino->id.'|'.$item->exercise_id, $indicados, true)) {
                    continue;
                }

                $nova = $this->generator->prescriptionFor($e, $comoSeria);
                $meta = ['target_sets' => $nova['sets'], 'target_reps_min' => $nova['reps_min'], 'target_reps_max' => $nova['reps_max'], 'rest_seconds' => $nova['rest']];

                if ((int) $item->target_sets === (int) $meta['target_sets'] && (int) $item->target_reps_min === (int) $meta['target_reps_min']
                    && (int) $item->target_reps_max === (int) $meta['target_reps_max'] && (int) $item->rest_seconds === (int) $meta['rest_seconds']) {
                    continue;
                }

                $itens[] = [
                    'item_id' => $item->id, 'name' => $e->name,
                    'from' => self::describe((int) $item->target_sets, $item->target_reps_min, $item->target_reps_max, $item->rest_seconds),
                    'to' => self::describe((int) $meta['target_sets'], $meta['target_reps_min'], $meta['target_reps_max'], $meta['rest_seconds']),
                    'meta' => $meta,
                ];
            }

            if ($itens !== []) {
                $saida[$treino->id] = ['workout_id' => $treino->id, 'name' => $treino->name, 'items' => $itens];
            }
        }

        return $saida;
    }

    /**
     * O plano em vigor cujas séries e repetições de perder peso continuam valendo numa nova atualização (mesma fase, mesmo objetivo), ou nulo.
     * Sem isto, atualizar de novo devolveria as séries antigas sem a pessoa pedir.
     */
    public function keptPrescription(string $objetivo, bool $leve): ?ActivityPlan
    {
        $atual = $this->planner->active();

        if ($atual === null || $leve || $objetivo !== 'weight_loss' || $atual->goal !== 'weight_loss'
            || $atual->gym_plan_id === null || $atual->gym_plan_id !== $this->gym->activePlan()?->id) {
            return null;
        }

        return GymPlanAdjustment::query()->where('activity_plan_id', $atual->id)->where('kind', GymPlanAdjustment::REPS)->whereNull('reverted_at')->exists() ? $atual : null;
    }

    /** @return array<string, array<string, mixed>> as pausas que as limitações pedem (as mesmas da revisão do treino) */
    public function pauses(): array
    {
        return array_filter($this->review->suggestions()['items'], fn (array $i) => $i['default'] === 'pause');
    }

    /**
     * Aplica o que a pessoa aprovou. Tudo ou nada.
     *
     * @param  list<int>  $dias  dias disponíveis
     * @param  list<string>  $tipos  aeróbicos escolhidos
     * @param  list<string>  $treinosComAjuste  ids dos treinos em que as séries e repetições mudam
     * @param  list<string>  $pausas  chaves das sugestões de pausa aprovadas
     */
    public function apply(string $objetivo, array $dias, array $tipos, array $treinosComAjuste = [], array $pausas = []): ActivityPlan
    {
        if (! isset(WeekPlannerService::GOALS[$objetivo])) {
            throw new DomainException('Escolha o objetivo.');
        }

        if (! $this->readiness->answered()) {
            throw new DomainException((string) $this->readiness->gate());
        }

        $leve = ! $this->readiness->allowsProgression();
        $proposta = $this->planner->proposal($objetivo, $dias, $tipos, $leve);

        if ($proposta['layout']['strength'] === [] && $proposta['layout']['aerobic'] === []) {
            throw new DomainException('Marque ao menos um dia livre, sem esporte fixo.');
        }

        $this->assertNoSessionInProgress();

        return DB::transaction(function () use ($objetivo, $tipos, $treinosComAjuste, $pausas, $leve, $proposta): ActivityPlan {
            // Uma atualização por vez. As séries da anterior voltam antes de calcular as novas (a não ser que continuem valendo), mas o que foi
            // pausado por uma limitação CONTINUA pausado; e o "antes" que o desfazer usa é sempre o de antes da primeira.
            $anterior = $this->planner->active();
            $mantemSeries = $this->keptPrescription($objetivo, $leve) !== null;

            if ($anterior !== null) {
                if (! $mantemSeries) {
                    $this->restorePrescription($anterior);
                }

                $this->close($anterior);
            }

            $fase = $this->gym->activePlan();
            $perfil = $this->activities->profile();
            $diasDeAntes = $anterior !== null ? (array) $anterior->previous_gym_days : (array) $perfil->gym_days;
            $mudancas = array_intersect_key($this->prescriptionChanges($objetivo, $leve), array_flip($treinosComAjuste));

            $plano = ActivityPlan::create([
                'is_active' => true, 'goal' => $objetivo,
                'strength_days' => $proposta['layout']['strength'], 'aerobic_days' => $proposta['layout']['aerobic'], 'aerobic_types' => array_values($tipos),
                'sport_minutes' => $proposta['baseline']['sport'], 'base_minutes' => $proposta['baseline']['total'], 'target_minutes' => $proposta['target'],
                'is_light' => $leve, 'previous_gym_days' => $diasDeAntes,
                'gym_plan_id' => $fase?->id, 'previous_gym_goal' => $mantemSeries ? $anterior->previous_gym_goal : $fase?->goal?->value,
                'started_on' => CarbonImmutable::today()->toDateString(),
            ]);

            if ($anterior !== null) {
                GymPlanAdjustment::query()->where('activity_plan_id', $anterior->id)->whereNull('reverted_at')->update(['activity_plan_id' => $plano->id]);
            }

            $this->activities->saveProfile((array) $perfil->goals, $proposta['layout']['strength'] ?: (array) $perfil->gym_days, (int) $perfil->session_minutes, (bool) $perfil->consider_limitations);

            if ($pausas !== []) {
                $antes = GymPlanAdjustment::query()->pluck('id')->all();
                $sugestoes = $this->review->suggestions();
                $decisoes = [];

                foreach (array_keys($sugestoes['items']) as $chave) {
                    $decisoes[$chave] = ['action' => in_array($chave, $pausas, true) ? 'pause' : 'skip'];
                }

                foreach (array_keys($sugestoes['restores']) as $chave) {
                    $decisoes[$chave] = ['action' => 'skip'];
                }

                foreach (array_keys($sugestoes['additions']) as $chave) {
                    $decisoes[$chave] = ['workouts' => []];
                }

                $this->review->apply($decisoes, false);
                GymPlanAdjustment::query()->whereNotIn('id', $antes)->update(['activity_plan_id' => $plano->id]);
            }

            if ($mudancas !== []) {
                foreach ($mudancas as $treino) {
                    foreach ($treino['items'] as $m) {
                        // Pode ter sido pausado agora há pouco.
                        $item = GymWorkoutExercise::query()->find($m['item_id']);

                        if ($item === null) {
                            continue;
                        }

                        GymPlanAdjustment::create([
                            'plan_id' => $fase->id, 'workout_id' => $item->workout_id, 'kind' => GymPlanAdjustment::REPS, 'exercise_id' => $item->exercise_id, 'activity_plan_id' => $plano->id,
                            'snapshot' => $item->only(['target_sets', 'target_reps_min', 'target_reps_max', 'rest_seconds']),
                        ]);
                        $item->update($m['meta']);
                    }
                }

                $fase->update(['goal' => GymGoal::WeightLoss]);
            }

            return $plano;
        });
    }

    /** Desfaz a atualização: os exercícios, as séries, os dias da academia e o objetivo voltam a ser os de antes. O histórico não muda. */
    public function undo(ActivityPlan $plano): void
    {
        $this->assertNoSessionInProgress();

        DB::transaction(fn () => $this->revert($plano));
    }

    private function revert(ActivityPlan $plano): void
    {
        // As séries voltam antes das pausas: o item pausado volta já como era.
        $this->restorePrescription($plano);

        GymPlanAdjustment::query()->where('activity_plan_id', $plano->id)->whereNull('reverted_at')->get()
            ->sortBy(fn (GymPlanAdjustment $a) => [$a->workout_id, (int) ($a->snapshot['position'] ?? 99)])
            ->each(fn (GymPlanAdjustment $a) => $this->review->revert($a->id));

        $perfil = $this->activities->profile();
        $this->activities->saveProfile((array) $perfil->goals, (array) $plano->previous_gym_days, (int) $perfil->session_minutes, (bool) $perfil->consider_limitations);
        $this->close($plano);
    }

    /** Séries, repetições e objetivo da fase voltam ao que eram antes da atualização. */
    private function restorePrescription(ActivityPlan $plano): void
    {
        foreach (GymPlanAdjustment::query()->where('activity_plan_id', $plano->id)->where('kind', GymPlanAdjustment::REPS)->whereNull('reverted_at')->get() as $a) {
            GymWorkoutExercise::query()->where('workout_id', $a->workout_id)->where('exercise_id', $a->exercise_id)->first()?->update((array) $a->snapshot);
            $a->update(['reverted_at' => now()]);
        }

        if ($plano->gym_plan_id !== null && ($fase = GymPlan::query()->find($plano->gym_plan_id)) !== null) {
            $fase->update(['goal' => $plano->previous_gym_goal !== null ? GymGoal::from($plano->previous_gym_goal) : null]);
        }
    }

    private function close(ActivityPlan $plano): void
    {
        $plano->update(['is_active' => false, 'ended_on' => CarbonImmutable::today()->toDateString()]);
    }

    private function assertNoSessionInProgress(): void
    {
        $fase = $this->gym->activePlan();

        if ($fase !== null && GymSession::query()->inProgress()->whereIn('workout_id', $fase->workouts()->select('id'))->exists()) {
            throw new DomainException('Há um treino em andamento. Termine ou descarte o treino antes.');
        }
    }

    private static function describe(int $series, ?int $min, ?int $max, ?int $descanso): string
    {
        $reps = collect([$min, $max])->filter()->unique()->implode(' a ');

        return $series.($reps !== '' ? ' × '.$reps : ' séries').($descanso !== null ? ', '.$descanso.' s de descanso' : '');
    }
}
