<?php

namespace App\Models;

use App\Casts\Sensitive;
use App\Enums\HealthAppointmentKind;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\HasActorUser;
use App\Models\Concerns\IsCoupleHealthData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Exame (ou consulta) pedido e ainda sem data. Ao marcar a data vira um compromisso da Agenda e fica ligado a ele. */
#[Fillable(['profile_id', 'member_id', 'kind', 'title', 'requested_on', 'requested_by', 'routine_id', 'followup_id', 'appointment_id', 'created_by_member_id', 'created_by_user_id', 'changed_by_user_id'])]
class HealthExamRequest extends Model
{
    use BelongsToProfile, HasActorUser, HasUuids, IsCoupleHealthData;

    protected function casts(): array
    {
        return ['kind' => HealthAppointmentKind::class, 'title' => Sensitive::class, 'requested_on' => 'date', 'requested_by' => Sensitive::class];
    }

    /** Ainda sem data marcada. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('appointment_id');
    }

    public function routine(): BelongsTo
    {
        return $this->belongsTo(HealthRoutine::class, 'routine_id');
    }

    public function followup(): BelongsTo
    {
        return $this->belongsTo(HealthFollowup::class, 'followup_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(HealthAppointment::class, 'appointment_id');
    }
}
