<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\IsPersonalHealthData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Só por hoje": nesta sessão, o item do treino é feito com outro exercício. O plano não muda. Dado de saúde pessoal,
 * como o resto da Academia (só o dono vê).
 */
#[Fillable(['profile_id', 'member_id', 'session_id', 'workout_exercise_id', 'exercise_id',
    'target_sets', 'target_reps_min', 'target_reps_max', 'target_duration_seconds', 'rest_seconds', 'notes', 'default_equipment_id'])]
class GymSessionSwap extends Model
{
    use BelongsToProfile, HasUuids, IsPersonalHealthData;

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(GymExercise::class, 'exercise_id');
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(GymEquipment::class, 'default_equipment_id');
    }
}
