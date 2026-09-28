<?php

namespace App\Services;

use App\Enums\GymMeasureType;
use App\Enums\GymMuscleGroup;
use App\Models\GymEquipment;
use App\Models\GymExercise;
use App\Models\GymPlan;
use App\Models\GymSession;
use App\Models\GymWorkout;
use App\Models\GymWorkoutExercise;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Montagem do plano de academia: fase, treinos da rotação, exercícios de
 * cada treino. Tudo roda sob o ProfileContext da pessoa (o escopo
 * PersonalHealthScope filtra e a trait carimba o dono) — este serviço não
 * escolhe de quem é o dado.
 */
class GymPlanService
{
    public function activePlan(): ?GymPlan
    {
        return GymPlan::query()->where('is_active', true)->latest('created_at')->first();
    }

    /** Uma fase ativa por vez: abrir a nova encerra a anterior (o histórico fica). */
    public function createPlan(string $name): GymPlan
    {
        return DB::transaction(function () use ($name): GymPlan {
            GymPlan::query()->where('is_active', true)->update([
                'is_active' => false,
                'ended_on' => now()->toDateString(),
            ]);

            return GymPlan::create([
                'name' => trim($name),
                'is_active' => true,
                'started_on' => now()->toDateString(),
            ]);
        });
    }

    public function addWorkout(GymPlan $plan, string $name, ?string $focus): GymWorkout
    {
        $proxima = (int) GymWorkout::query()->where('plan_id', $plan->id)->max('position') + 1;

        return GymWorkout::create([
            'plan_id' => $plan->id,
            'name' => trim($name),
            'focus' => $focus !== null && trim($focus) !== '' ? trim($focus) : null,
            'position' => $proxima,
        ]);
    }

    /**
     * Treino que já tem sessão registrada não some: excluir apagaria o
     * histórico junto (as sessões dependem dele). Fica pra uma futura
     * opção de arquivar.
     */
    public function deleteWorkout(GymWorkout $workout): void
    {
        if (GymSession::query()->where('workout_id', $workout->id)->exists()) {
            throw new DomainException('Este treino já tem sessões registradas e não pode ser excluído.');
        }

        DB::transaction(function () use ($workout): void {
            $planId = $workout->plan_id;
            $workout->delete();
            $this->renumber(GymWorkout::query()->where('plan_id', $planId));
        });
    }

    public function moveWorkout(GymWorkout $workout, int $direction): void
    {
        $this->move($workout, GymWorkout::query()->where('plan_id', $workout->plan_id), $direction);
    }

    public function findOrCreateExercise(string $name, GymMuscleGroup $group, GymMeasureType $type): GymExercise
    {
        $name = trim($name);

        // O nome é único por pessoa (índice); reaproveitar em vez de falhar
        // faz "Supino reto" digitado de novo apontar pro mesmo exercício.
        return GymExercise::query()->where('name', $name)->first()
            ?? GymExercise::create(['name' => $name, 'muscle_group' => $group, 'measure_type' => $type]);
    }

    public function findOrCreateEquipment(?string $name): ?GymEquipment
    {
        $name = $name === null ? '' : trim($name);

        if ($name === '') {
            return null;
        }

        return GymEquipment::query()->where('name', $name)->first()
            ?? GymEquipment::create(['name' => $name]);
    }

    /** @param  array<string, mixed>  $meta  campos target_*, rest_seconds, default_equipment_id, notes */
    public function addExercise(GymWorkout $workout, GymExercise $exercise, array $meta): GymWorkoutExercise
    {
        $proxima = (int) GymWorkoutExercise::query()->where('workout_id', $workout->id)->max('position') + 1;

        return GymWorkoutExercise::create($this->sanitize($exercise, $meta) + [
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
            'position' => $proxima,
        ]);
    }

    /** @param  array<string, mixed>  $meta */
    public function updateExercise(GymWorkoutExercise $item, array $meta): GymWorkoutExercise
    {
        $item->update($this->sanitize($item->exercise, $meta));

        return $item;
    }

    public function removeExercise(GymWorkoutExercise $item): void
    {
        DB::transaction(function () use ($item): void {
            $workoutId = $item->workout_id;
            $item->delete();
            $this->renumber(GymWorkoutExercise::query()->where('workout_id', $workoutId));
        });
    }

    public function moveExercise(GymWorkoutExercise $item, int $direction): void
    {
        $this->move($item, GymWorkoutExercise::query()->where('workout_id', $item->workout_id), $direction);
    }

    /**
     * Próximo treino da rotação: o seguinte ao do último treino CONCLUÍDO,
     * voltando ao primeiro depois do último. Sem nenhum concluído, o
     * primeiro.
     */
    public function nextWorkout(GymPlan $plan): ?GymWorkout
    {
        $treinos = $plan->workouts()->get();

        if ($treinos->isEmpty()) {
            return null;
        }

        $ultima = GymSession::query()
            ->whereIn('workout_id', $treinos->pluck('id'))
            ->whereNotNull('finished_at')
            ->latest('finished_at')
            ->first();

        if ($ultima === null) {
            return $treinos->first();
        }

        $indice = $treinos->search(fn (GymWorkout $t) => $t->id === $ultima->workout_id);

        return $treinos[(($indice === false ? -1 : $indice) + 1) % $treinos->count()];
    }

    /**
     * Exercício medido em tempo (prancha, esteira) usa cronômetro, não
     * pausa entre séries — então não guarda repetições nem descanso.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function sanitize(GymExercise $exercise, array $meta): array
    {
        $timed = $exercise->measure_type->usesStopwatch();

        return [
            'target_sets' => (int) ($meta['target_sets'] ?? 3),
            'target_reps_min' => $timed ? null : ($meta['target_reps_min'] ?? null),
            'target_reps_max' => $timed ? null : ($meta['target_reps_max'] ?? null),
            'target_duration_seconds' => $timed ? ($meta['target_duration_seconds'] ?? null) : null,
            'rest_seconds' => $timed ? null : ($meta['rest_seconds'] ?? null),
            'default_equipment_id' => $meta['default_equipment_id'] ?? null,
            'notes' => isset($meta['notes']) && trim((string) $meta['notes']) !== '' ? trim((string) $meta['notes']) : null,
        ];
    }

    /**
     * Troca `position` com o vizinho. O índice único (pai, position)
     * proíbe dois com o mesmo valor, nem por um instante — daí o 0
     * intermediário (posições reais começam em 1).
     */
    private function move(Model $item, $siblings, int $direction): void
    {
        $vizinho = (clone $siblings)
            ->where('position', $direction < 0 ? '<' : '>', $item->position)
            ->orderBy('position', $direction < 0 ? 'desc' : 'asc')
            ->first();

        if ($vizinho === null) {
            return;
        }

        DB::transaction(function () use ($item, $vizinho): void {
            $a = $item->position;
            $b = $vizinho->position;

            $item->update(['position' => 0]);
            $vizinho->update(['position' => $a]);
            $item->update(['position' => $b]);
        });
    }

    /** Fecha o buraco depois de uma exclusão: 1, 2, 3... em ordem crescente (nunca colide). */
    private function renumber($query): void
    {
        $posicao = 1;

        foreach ($query->orderBy('position')->get() as $linha) {
            if ($linha->position !== $posicao) {
                $linha->update(['position' => $posicao]);
            }
            $posicao++;
        }
    }
}
