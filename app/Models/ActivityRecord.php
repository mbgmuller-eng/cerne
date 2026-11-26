<?php

namespace App\Models;

use App\Casts\Sensitive;
use App\Enums\ActivityRecordStatus;
use App\Enums\ActivityType;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\IsPersonalHealthData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma linha do diário: o que foi feito (ou marcado, ou não feito), com duração, esforço e, se a pessoa quiser, dor e distância. */
#[Fillable([
    'profile_id', 'member_id', 'activity', 'custom_name', 'done_on', 'status', 'minutes', 'distance_meters', 'effort', 'pain', 'heart_rate', 'note',
    'planned_key', 'gym_session_id',
])]
class ActivityRecord extends Model
{
    use BelongsToProfile, HasUuids, IsPersonalHealthData;

    protected function casts(): array
    {
        return [
            'activity' => ActivityType::class, 'custom_name' => Sensitive::class, 'done_on' => 'date', 'status' => ActivityRecordStatus::class,
            'minutes' => 'integer', 'distance_meters' => 'integer', 'effort' => 'integer', 'pain' => 'integer', 'heart_rate' => 'integer', 'note' => Sensitive::class,
        ];
    }

    public function gymSession(): BelongsTo
    {
        return $this->belongsTo(GymSession::class, 'gym_session_id');
    }

    public function name(): string
    {
        return $this->activity === ActivityType::Other && $this->custom_name ? $this->custom_name : $this->activity->label();
    }

    /** Minutos × esforço (0 a 10). Sem esforço informado não entra na conta. */
    public function sessionLoad(): ?int
    {
        return $this->effort === null ? null : $this->minutes * $this->effort;
    }
}
