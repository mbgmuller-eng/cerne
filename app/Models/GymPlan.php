<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\IsPersonalHealthData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Uma fase de treino ("Fase 1"), com sua rotação de treinos. */
#[Fillable(['profile_id', 'member_id', 'name', 'is_active', 'started_on', 'ended_on', 'notes'])]
class GymPlan extends Model
{
    use BelongsToProfile, HasUuids, IsPersonalHealthData;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'started_on' => 'date',
            'ended_on' => 'date',
        ];
    }

    public function workouts(): HasMany
    {
        return $this->hasMany(GymWorkout::class, 'plan_id')->orderBy('position');
    }
}
