<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\IsPersonalHealthData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** O fechamento de uma semana do programa de corrida: avançou, repetiu ou voltou um passo. */
#[Fillable(['profile_id', 'member_id', 'program_id', 'week_start', 'outcome', 'step_before', 'step_after'])]
class RunningProgramWeek extends Model
{
    use BelongsToProfile, HasUuids, IsPersonalHealthData;

    protected function casts(): array
    {
        return ['week_start' => 'date', 'step_before' => 'integer', 'step_after' => 'integer'];
    }
}
