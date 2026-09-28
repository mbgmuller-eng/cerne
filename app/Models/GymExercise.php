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
#[Fillable(['profile_id', 'member_id', 'name', 'muscle_group', 'measure_type', 'is_active'])]
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
}
