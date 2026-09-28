<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\IsPersonalHealthData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um exercício DENTRO de um treino, com a meta daquele treino (séries,
 * faixa de repetições, pausa). O exercício em si mora em GymExercise.
 */
#[Fillable([
    'profile_id', 'member_id', 'workout_id', 'exercise_id', 'position', 'target_sets',
    'target_reps_min', 'target_reps_max', 'target_duration_seconds', 'rest_seconds',
    'default_equipment_id', 'notes',
])]
class GymWorkoutExercise extends Model
{
    use BelongsToProfile, HasUuids, IsPersonalHealthData;

    public function workout(): BelongsTo
    {
        return $this->belongsTo(GymWorkout::class, 'workout_id');
    }

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(GymExercise::class, 'exercise_id');
    }

    public function defaultEquipment(): BelongsTo
    {
        return $this->belongsTo(GymEquipment::class, 'default_equipment_id');
    }
}
