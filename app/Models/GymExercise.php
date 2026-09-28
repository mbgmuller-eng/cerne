<?php

namespace App\Models;

use App\Enums\GymMeasureType;
use App\Enums\GymMuscleGroup;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\IsPersonalHealthData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catálogo de exercícios da pessoa. Um registro por exercício, independente
 * de plano ou de sessão — é isso que faz o histórico sobreviver à troca de
 * fase de treino.
 */
#[Fillable(['profile_id', 'member_id', 'name', 'muscle_group', 'measure_type', 'is_active', 'image_path', 'image_path_2'])]
class GymExercise extends Model
{
    use BelongsToProfile, HasUuids, IsPersonalHealthData;

    protected function casts(): array
    {
        return [
            'muscle_group' => GymMuscleGroup::class,
            'measure_type' => GymMeasureType::class,
            'is_active' => 'boolean',
        ];
    }

    public function setLogs(): HasMany
    {
        return $this->hasMany(GymSetLog::class, 'exercise_id');
    }

    /**
     * URL autenticada e escopada (GymExerciseImageController) — nunca a
     * URL do disco: o arquivo fica em disco PRIVADO, mesma regra dos
     * documentos importados. `v=` derruba o cache do navegador quando a
     * foto é trocada (o caminho no disco muda a cada upload).
     *
     * `$frame` 2 pede o segundo quadro (início/fim do movimento) — só
     * existe em foto vinda do vínculo automático (cerne:gym-link-images);
     * uma foto enviada à mão nunca tem o segundo quadro.
     */
    public function imageUrl(int $frame = 1): ?string
    {
        $existe = $frame === 2 ? $this->image_path_2 !== null : $this->image_path !== null;

        if (! $existe) {
            return null;
        }

        $url = route('health.gym.exercise-image', $this).'?v='.$this->updated_at?->timestamp;

        return $frame === 2 ? $url.'&f=2' : $url;
    }

    /** Tem os dois quadros: a tela pode alternar entre eles (efeito de GIF sem gerar arquivo animado). */
    public function hasAnimatedImage(): bool
    {
        return $this->image_path !== null && $this->image_path_2 !== null;
    }
}
