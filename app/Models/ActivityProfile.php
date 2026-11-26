<?php

namespace App\Models;

use App\Casts\Sensitive;
use App\Casts\SensitiveArray;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\IsPersonalHealthData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** O perfil de atividades da pessoa: objetivos em ordem, dias preferidos da academia e quanto tempo tem por treino. Só o dono vê. */
#[Fillable(['profile_id', 'member_id', 'goals', 'gym_days', 'session_minutes', 'consider_limitations', 'weight_goal', 'weight_goal_start', 'weight_goal_set_on'])]
class ActivityProfile extends Model
{
    use BelongsToProfile, HasUuids, IsPersonalHealthData;

    protected function casts(): array
    {
        return [
            'goals' => SensitiveArray::class, 'gym_days' => 'array', 'session_minutes' => 'integer', 'consider_limitations' => 'boolean',
            'weight_goal' => Sensitive::class, 'weight_goal_start' => Sensitive::class, 'weight_goal_set_on' => 'date',
        ];
    }
}
