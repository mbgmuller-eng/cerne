<?php

namespace App\Models;

use App\Casts\Sensitive;
use App\Enums\HealthAppointmentKind;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\IsCoupleHealthData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma consulta ou exame — passado (com anotação do que aconteceu) ou futuro (vira lembrete). */
#[Fillable(['professional_id', 
    'profile_id', 'member_id', 'kind', 'title', 'professional_name', 'specialty', 'location', 'address', 'phone',
    'booked_by_name', 'booked_with_name', 'scheduled_at', 'notes', 'created_by_member_id', 'created_by_user_id', 'escort_user_id', 'status', 'status_at', 'status_by_user_id',
    'routine_id',
])]
class HealthAppointment extends Model
{
    use BelongsToProfile, HasUuids, IsCoupleHealthData, \App\Models\Concerns\HasActorUser;

    /** Nasce agendada (o banco já tem o padrão, mas o model recém-criado precisa saber sem ser relido). */
    protected $attributes = ['status' => 'scheduled'];

    protected static function booted(): void
    {
        // A próxima data do check-up sai da última consulta REALIZADA ligada a ele: confirmar, remarcar, ligar ou apagar um compromisso refaz a conta.
        static::saved(function (self $consulta): void {
            if ($consulta->wasChanged(['routine_id', 'status', 'scheduled_at']) || ($consulta->wasRecentlyCreated && $consulta->routine_id !== null)) {
                // Dentro de `saved` o original ainda é o anterior: a rotina de onde o compromisso saiu também é refeita.
                app(\App\Services\HealthRoutineService::class)->refreshMany([$consulta->routine_id, $consulta->getOriginal('routine_id')]);
            }
        });
        static::deleted(function (self $consulta): void {
            app(\App\Services\HealthRoutineService::class)->refreshMany([$consulta->routine_id]);
        });
    }

    /** A rotina preventiva (check-up) a que este compromisso pertence. */
    public function routine(): BelongsTo
    {
        return $this->belongsTo(HealthRoutine::class, 'routine_id');
    }

    /** Os acompanhamentos (problemas) em que este compromisso aparece. */
    public function followups(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(HealthFollowup::class, 'health_followup_appointment', 'appointment_id', 'followup_id');
    }

    public function documents(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Document::class, 'health_appointment_id');
    }

    protected function casts(): array
    {
        return [
            'location' => Sensitive::class,
            'address' => Sensitive::class,
            'phone' => Sensitive::class,
            'booked_by_name' => Sensitive::class,
            'booked_with_name' => Sensitive::class,
            'kind' => HealthAppointmentKind::class,
            'status' => \App\Enums\HealthAppointmentStatus::class,
            'status_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'title' => Sensitive::class,
            'professional_name' => Sensitive::class,
            'specialty' => Sensitive::class,
            'notes' => Sensitive::class,
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(ProfileMember::class, 'created_by_member_id');
    }

    /** Quem vai levar a pessoa a esta consulta (um dos que cuidam dela). */
    public function escort(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'escort_user_id');
    }

    /** Quem confirmou ou cancelou. */
    public function statusBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'status_by_user_id');
    }

    /** As que ainda vão acontecer: no futuro E ainda agendadas (a cancelada deixa de ser "próxima" e vai para o histórico). */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('scheduled_at', '>=', now())->where('status', \App\Enums\HealthAppointmentStatus::Scheduled->value)->orderBy('scheduled_at');
    }

    /** O histórico: o que já passou, mais o que foi cancelado ou marcado como não realizado mesmo antes da data. */
    public function scopePast(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('scheduled_at', '<', now())->orWhere('status', '!=', \App\Enums\HealthAppointmentStatus::Scheduled->value))->orderByDesc('scheduled_at');
    }

    public function isPast(): bool
    {
        return $this->scheduled_at->isPast();
    }

    /** Já passou da data e ninguém confirmou se aconteceu. */
    public function awaitsConfirmation(): bool
    {
        return $this->isPast() && $this->status === \App\Enums\HealthAppointmentStatus::Scheduled;
    }
}
