<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\IsPersonalHealthData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * O plano da semana em vigor: dias de musculação e de aeróbico, de onde partiu e aonde quer chegar. Os minutos de cada semana são calculados
 * (a progressão anda pelo que foi feito), e o que havia antes fica guardado para a atualização poder ser desfeita. Um ativo por vez.
 */
#[Fillable([
    'profile_id', 'member_id', 'is_active', 'goal', 'strength_days', 'aerobic_days', 'aerobic_types', 'sport_minutes', 'base_minutes', 'target_minutes',
    'is_light', 'previous_gym_days', 'gym_plan_id', 'previous_gym_goal', 'started_on', 'ended_on',
])]
class ActivityPlan extends Model
{
    use BelongsToProfile, HasUuids, IsPersonalHealthData;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean', 'is_light' => 'boolean', 'strength_days' => 'array', 'aerobic_days' => 'array', 'aerobic_types' => 'array',
            'previous_gym_days' => 'array', 'sport_minutes' => 'integer', 'base_minutes' => 'integer', 'target_minutes' => 'integer',
            'started_on' => 'date', 'ended_on' => 'date',
        ];
    }
}
