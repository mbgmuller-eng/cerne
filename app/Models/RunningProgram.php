<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\IsPersonalHealthData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** O programa de caminhada e corrida da pessoa. Um ativo por vez. */
#[Fillable(['profile_id', 'member_id', 'is_active', 'step', 'times_per_week', 'weekdays', 'baseline_pain', 'chose_despite_limitation_at', 'started_on', 'ended_on'])]
class RunningProgram extends Model
{
    use BelongsToProfile, HasUuids, IsPersonalHealthData;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean', 'step' => 'integer', 'times_per_week' => 'integer', 'weekdays' => 'array', 'baseline_pain' => 'integer',
            'chose_despite_limitation_at' => 'datetime', 'started_on' => 'date', 'ended_on' => 'date',
        ];
    }

    public function weeks(): HasMany
    {
        return $this->hasMany(RunningProgramWeek::class, 'program_id')->orderByDesc('week_start');
    }
}
