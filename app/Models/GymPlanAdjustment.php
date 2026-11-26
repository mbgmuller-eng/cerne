<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\IsPersonalHealthData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma mudança feita no treino pela revisão: exercício pausado ou trocado, exercício indicado acrescentado, ou uma série a menos para caber no
 * tempo. Guarda como o item estava, para poder voltar. As séries já registradas não mudam.
 */
#[Fillable([
    'profile_id', 'member_id', 'plan_id', 'workout_id', 'limitation_id', 'kind', 'exercise_id', 'replacement_exercise_id', 'snapshot',
    'source_exercise_id', 'reverted_at',
])]
class GymPlanAdjustment extends Model
{
    use BelongsToProfile, HasUuids, IsPersonalHealthData;

    public const PAUSED = 'paused';

    public const SWAPPED = 'swapped';

    public const ADDED = 'added';

    public const FEWER_SETS = 'fewer_sets';

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'reverted_at' => 'datetime'];
    }

    public function workout(): BelongsTo
    {
        return $this->belongsTo(GymWorkout::class, 'workout_id');
    }

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(GymExercise::class, 'exercise_id');
    }

    public function replacement(): BelongsTo
    {
        return $this->belongsTo(GymExercise::class, 'replacement_exercise_id');
    }
}
