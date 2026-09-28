<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\IsPersonalHealthData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Uma ida à academia. `finished_at` nulo = sessão em andamento, que dá pra retomar. */
#[Fillable(['profile_id', 'member_id', 'workout_id', 'performed_on', 'started_at', 'finished_at', 'notes'])]
class GymSession extends Model
{
    use BelongsToProfile, HasUuids, IsPersonalHealthData;

    protected function casts(): array
    {
        return [
            'performed_on' => 'date',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function workout(): BelongsTo
    {
        return $this->belongsTo(GymWorkout::class, 'workout_id');
    }

    public function setLogs(): HasMany
    {
        return $this->hasMany(GymSetLog::class, 'session_id');
    }

    public function scopeInProgress(Builder $query): Builder
    {
        return $query->whereNull('finished_at');
    }

    public function isInProgress(): bool
    {
        return $this->finished_at === null;
    }
}
