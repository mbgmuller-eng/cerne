<?php

namespace App\Models;

use App\Enums\HealthAppointmentKind;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\IsCoupleHealthData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma consulta ou exame — passado (com anotação do que aconteceu) ou futuro (vira lembrete). */
#[Fillable([
    'profile_id', 'member_id', 'kind', 'title', 'professional_name', 'specialty', 'location', 'address', 'phone',
    'booked_by_name', 'booked_with_name', 'scheduled_at', 'notes', 'created_by_member_id',
])]
class HealthAppointment extends Model
{
    use BelongsToProfile, HasUuids, IsCoupleHealthData;

    protected function casts(): array
    {
        return [
            'kind' => HealthAppointmentKind::class,
            'scheduled_at' => 'datetime',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(ProfileMember::class, 'created_by_member_id');
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('scheduled_at', '>=', now())->orderBy('scheduled_at');
    }

    public function scopePast(Builder $query): Builder
    {
        return $query->where('scheduled_at', '<', now())->orderByDesc('scheduled_at');
    }

    public function isPast(): bool
    {
        return $this->scheduled_at->isPast();
    }
}
