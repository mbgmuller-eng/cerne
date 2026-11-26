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

/**
 * Um pedido do médico ("Exames de sangue", "Avaliação cardiológica"), com a lista de exames em HealthExamItem. Cada item marca a própria data e
 * recebe o próprio resultado. A coluna `appointment_id` é de quando o pedido era um exame só e não é mais lida (ver a migração dos itens).
 */
#[Fillable(['professional_id', 'profile_id', 'member_id', 'kind', 'category', 'title', 'requested_on', 'requested_by', 'notes', 'routine_id', 'followup_id', 'created_by_member_id', 'created_by_user_id', 'changed_by_user_id'])]
class HealthExamRequest extends Model
{
    use BelongsToProfile, HasActorUser, HasUuids, IsCoupleHealthData;

    protected function casts(): array
    {
        return ['kind' => HealthAppointmentKind::class, 'title' => Sensitive::class, 'requested_on' => 'date', 'requested_by' => Sensitive::class, 'notes' => Sensitive::class];
    }

    /** Pedidos com pelo menos um exame esperando data. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereHas('items', fn (Builder $q) => $q->pending());
    }

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(HealthExamItem::class, 'request_id')->orderBy('position');
    }

    public function reports(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(HealthExamReport::class, 'request_id')->orderBy('performed_on');
    }

    public function routine(): BelongsTo
    {
        return $this->belongsTo(HealthRoutine::class, 'routine_id');
    }

    public function followup(): BelongsTo
    {
        return $this->belongsTo(HealthFollowup::class, 'followup_id');
    }
}
