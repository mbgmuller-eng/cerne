<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\IsPersonalHealthData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A pessoa mudou um item previsto de dia numa semana. As outras semanas seguem como estavam. */
#[Fillable(['profile_id', 'member_id', 'planned_key', 'week_start', 'weekday'])]
class ActivityWeekMove extends Model
{
    use BelongsToProfile, HasUuids, IsPersonalHealthData;

    protected function casts(): array
    {
        return ['week_start' => 'date', 'weekday' => 'integer'];
    }
}
