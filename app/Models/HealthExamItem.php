<?php

namespace App\Models;

use App\Casts\Sensitive;
use App\Enums\HealthExamItemStatus;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\HasActorUser;
use App\Models\Concerns\IsCoupleHealthData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Um exame dentro de um pedido: tem o próprio detalhe, a própria data (o compromisso em que será feito) e o próprio resultado. */
#[Fillable(['profile_id', 'member_id', 'request_id', 'name', 'detail', 'appointment_id', 'report_id', 'position', 'created_by_member_id', 'created_by_user_id', 'changed_by_user_id'])]
class HealthExamItem extends Model
{
    use BelongsToProfile, HasActorUser, HasUuids, IsCoupleHealthData;

    protected function casts(): array
    {
        return ['name' => Sensitive::class, 'detail' => Sensitive::class, 'position' => 'integer'];
    }

    /** Esperando data: sem resultado e sem compromisso que valha (cancelado ou não realizado devolve o exame para a fila). */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('report_id')->where(fn (Builder $q) => $q
            ->whereNull('appointment_id')
            ->orWhereHas('appointment', fn (Builder $a) => $a->whereIn('status', ['cancelled', 'not_done'])));
    }

    /** Precisa de `appointment` carregado. */
    public function status(): HealthExamItemStatus
    {
        if ($this->report_id !== null) {
            return HealthExamItemStatus::Result;
        }

        $consulta = $this->appointment;

        return match (true) {
            $consulta === null || $consulta->status->didNotHappen() => HealthExamItemStatus::Pending,
            $consulta->status === \App\Enums\HealthAppointmentStatus::Done => HealthExamItemStatus::Done,
            default => HealthExamItemStatus::Scheduled,
        };
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(HealthExamRequest::class, 'request_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(HealthAppointment::class, 'appointment_id');
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(HealthExamReport::class, 'report_id');
    }
}
