<?php

namespace App\Models;

use App\Enums\GymEquipmentAccess;
use App\Enums\GymGoal;
use App\Enums\GymLevel;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\IsPersonalHealthData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma fase de treino ("Fase 1"), com sua rotação de treinos. As colunas de objetivo (`goal`, `level`...) guardam o que a pessoa
 * respondeu ao pedir o treino automático, ou o objetivo que ela definiu depois; planos montados à mão podem não ter.
 */
#[Fillable([
    'profile_id', 'member_id', 'name', 'goal', 'level', 'days_per_week', 'session_minutes', 'equipment_access', 'restrictions',
    'is_active', 'started_on', 'ended_on', 'notes',
])]
class GymPlan extends Model
{
    use BelongsToProfile, HasUuids, IsPersonalHealthData;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'started_on' => 'date',
            'ended_on' => 'date',
            'goal' => GymGoal::class,
            'level' => GymLevel::class,
            'equipment_access' => GymEquipmentAccess::class,
            'days_per_week' => 'integer',
            'session_minutes' => 'integer',
            'restrictions' => 'array',
        ];
    }

    public function workouts(): HasMany
    {
        return $this->hasMany(GymWorkout::class, 'plan_id')->orderBy('position');
    }
}
