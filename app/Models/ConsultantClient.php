<?php

namespace App\Models;

use App\Enums\ConsultantClientStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['consultant_id', 'client_id', 'status', 'invited_at', 'accepted_at', 'ended_at', 'plan_delivery', 'notes'])]
class ConsultantClient extends Model
{
    /**
     * Os perfis dos clientes com vínculo ATIVO deste profissional. É o único critério para ler dado vivo do cliente: vínculo encerrado, pendente ou
     * revogado corta a leitura na hora (tela de comissões e de contratos incluídas), mesmo que o profissional ainda tenha o registro financeiro dele.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    public static function activeProfileIds(User $professional): \Illuminate\Support\Collection
    {
        $clientes = self::query()->where('consultant_id', $professional->id)->where('status', ConsultantClientStatus::Active)->pluck('client_id');

        return FinancialProfile::query()->whereIn('owner_user_id', $clientes)->pluck('id');
    }

    use HasFactory, HasUuids;

    protected static function booted(): void
    {
        // Quem pode abrir o Acompanhamento depende do vínculo: o cache da requisição não pode sobreviver a uma mudança dele.
        static::saved(fn () => app(\App\Services\EngagementAccess::class)->forget());
    }

    protected function casts(): array
    {
        return [
            'status' => ConsultantClientStatus::class,
            'invited_at' => 'datetime',
            'accepted_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consultant_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ConsultantClientStatus::Active);
    }
}
