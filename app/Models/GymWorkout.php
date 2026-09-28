<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\IsPersonalHealthData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Um treino da rotação ("Treino A — peito, ombros e core"). `position` é a ordem A → B → C. */
#[Fillable(['profile_id', 'member_id', 'plan_id', 'name', 'focus', 'position'])]
class GymWorkout extends Model
{
    use BelongsToProfile, HasUuids, IsPersonalHealthData;

    public function plan(): BelongsTo
    {
        return $this->belongsTo(GymPlan::class, 'plan_id');
    }

    public function workoutExercises(): HasMany
    {
        return $this->hasMany(GymWorkoutExercise::class, 'workout_id')->orderBy('position');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(GymSession::class, 'workout_id');
    }
}
