<?php

namespace App\Services;

use App\Enums\GymMeasureType;
use App\Enums\GymMuscleGroup;
use App\Models\GymEquipment;
use App\Models\GymExercise;
use App\Models\GymExerciseCatalog;
use App\Models\GymExerciseSuggestion;
use App\Models\GymPlan;
use App\Models\GymSession;
use App\Models\GymWorkout;
use App\Models\GymWorkoutExercise;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

    /**
     * `$sugerirAoCatalogo` só é ligado pela tela de montar o treino: exercício
     * NOVO que não existe no catálogo compartilhado entra na fila anônima do
     * admin (GymExerciseSuggestion). A importação de histórico não liga —
     * uma planilha com dezenas de nomes soltos inundaria a fila.
     */
    public function findOrCreateExercise(string $name, GymMuscleGroup $group, GymMeasureType $type, bool $sugerirAoCatalogo = false): GymExercise
    {
        $name = trim($name);

        // O nome é único por pessoa (índice); reaproveitar em vez de falhar
        // faz "Supino reto" digitado de novo apontar pro mesmo exercício.
        $existente = GymExercise::query()->where('name', $name)->first();
        if ($existente !== null) {
            return $existente;
        }

        $exercicio = GymExercise::create(['name' => $name, 'muscle_group' => $group, 'measure_type' => $type]);

        if ($sugerirAoCatalogo) {
            GymExerciseSuggestion::record($name, $group, $type);
        }

        return $exercicio;
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

    /**
     * Troca (ou remove) a foto do exercício. A foto é do CATÁLOGO, não do
     * item do treino: vale em todo treino e toda fase que usarem esse
     * exercício. Sempre apaga o arquivo antigo do disco — nunca deixa
     * lixo órfão pra trás.
     *
     * `$file2` é o segundo quadro (início/fim do movimento) — só o vínculo
     * automático de referência (cerne:gym-link-images) usa; o upload feito
     * à mão na tela manda só `$file`. `$remove` apaga os dois quadros.
     */
    public function setExerciseImage(GymExercise $exercise, ?UploadedFile $file, ?UploadedFile $file2 = null, bool $remove = false): GymExercise
    {
        // Trocar só o quadro 1 (upload manual) descarta um quadro 2 que
        // tivesse ficado de um vínculo automático anterior — os dois
        // quadros são um PAR, um quadro 1 novo sem par não pode ficar
        // animando contra um quadro 2 de outra foto.
        $exercise->update([
            'image_path' => $this->storeImage($exercise, $exercise->image_path, $file, $remove),
            'image_path_2' => $this->storeImage($exercise, $exercise->image_path_2, $file2, $remove || ($file !== null && $file2 === null)),
        ]);

        return $exercise;
    }

    /**
     * Um quadro da foto: apaga o antigo (se está sendo trocado ou removido)
     * e grava o novo, se veio um. `$dropExisting` cobre o caso do segundo
     * quadro sumir quando SÓ o primeiro é trocado (uma foto nova de um
     * upload manual não tem par — não faz sentido manter o quadro 2 antigo
     * pendurado nela).
     */
    private function storeImage(GymExercise $exercise, ?string $atual, ?UploadedFile $file, bool $dropExisting): ?string
    {
        $disco = Storage::disk(config('cerne.gym_images.disk'));

        if ($atual !== null && ($file !== null || $dropExisting)) {
            $disco->delete($atual);
        }

        if ($file === null) {
            return $dropExisting ? null : $atual;
        }

        // Nome aleatório: não expõe o nome original do arquivo e não colide
        // entre pessoas diferentes, mesmo com o mesmo nome de exercício.
        return $file->storeAs(
            config('cerne.gym_images.path').'/'.$exercise->member_id,
            Str::uuid().'.'.$file->extension(),
            config('cerne.gym_images.disk'),
        );
    }

    /**
     * Copia a foto do CATÁLOGO compartilhado pro exercício recém-criado —
     * só quando ele ainda não tem foto própria (nunca sobrescreve o que
     * a pessoa já tinha) e o catálogo tem o que copiar. É cópia de
     * verdade no disco: a foto da pessoa fica independente da entrada do
     * catálogo dali em diante (editar/remover uma nunca afeta a outra).
     */
    public function copyCatalogImage(GymExercise $exercise, GymExerciseCatalog $catalogo): GymExercise
    {
        if ($exercise->image_path !== null || $catalogo->image_path === null) {
            return $exercise;
        }

        $disco = Storage::disk(config('cerne.gym_images.disk'));
        $pasta = config('cerne.gym_images.path').'/'.$exercise->member_id;

        $caminho1 = $pasta.'/'.Str::uuid().'.'.pathinfo($catalogo->image_path, PATHINFO_EXTENSION);
        $disco->copy($catalogo->image_path, $caminho1);

        $caminho2 = null;
        if ($catalogo->image_path_2 !== null) {
            $caminho2 = $pasta.'/'.Str::uuid().'.'.pathinfo($catalogo->image_path_2, PATHINFO_EXTENSION);
            $disco->copy($catalogo->image_path_2, $caminho2);
        }

        $exercise->update(['image_path' => $caminho1, 'image_path_2' => $caminho2]);

        return $exercise;
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
