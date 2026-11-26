<?php

namespace App\Models;

use App\Casts\Sensitive;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\HasActorUser;
use App\Models\Concerns\IsCoupleHealthData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Presença (ou falta) numa atividade, com a nota de dor de 0 a 10. */
#[Fillable(['profile_id', 'member_id', 'activity_id', 'done_on', 'attended', 'pain', 'note', 'gym_session_id', 'created_by_member_id', 'created_by_user_id', 'changed_by_user_id'])]
class HealthActivitySession extends Model
{
    use BelongsToProfile, HasActorUser, HasUuids, IsCoupleHealthData;

    protected function casts(): array
    {
        return ['done_on' => 'date', 'attended' => 'boolean', 'pain' => 'integer', 'note' => Sensitive::class];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(HealthActivity::class, 'activity_id');
    }
}
