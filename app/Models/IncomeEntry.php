<?php

namespace App\Models;

use App\Enums\IncomeSource;
use App\Enums\IncomeStatus;
use App\Models\Concerns\HasCompetence;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma linha do livro de recebimentos do profissional: o que ele espera receber (ou recebeu) de uma origem, num mês.
 * Dado DELE (professional_id), nunca do cliente: não usa BelongsToProfile, ver TenancyCoverageTest::EXEMPT. Quem lê
 * sempre filtra por `ownedBy()`.
 */
#[Fillable([
    'professional_id', 'profile_id', 'fee_agreement_id', 'origin_key', 'source', 'status', 'description', 'due_on', 'amount',
    'received_amount', 'received_on', 'notes',
])]
class IncomeEntry extends Model
{
    use HasCompetence, HasFactory, HasUuids;

    protected static string $competenceDate = 'due_on';

    protected function casts(): array
    {
        return [
            'source' => IncomeSource::class,
            'status' => IncomeStatus::class,
            'due_on' => 'date',
            'received_on' => 'date',
            'amount' => 'decimal:2',
            'received_amount' => 'decimal:2',
        ];
    }

    public function scopeOwnedBy(Builder $query, User $professional): Builder
    {
        return $query->where('professional_id', $professional->id);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(FinancialProfile::class, 'profile_id');
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(FeeAgreement::class, 'fee_agreement_id');
    }

    /** Nasceu de um contrato ou de uma regra (não foi lançado à mão): só se dá baixa ou se cancela, não se edita nem exclui. */
    public function isGenerated(): bool
    {
        return $this->fee_agreement_id !== null || $this->origin_key !== null;
    }

    /** Previsto que já passou do vencimento sem ser marcado como recebido. */
    public function isOverdue(): bool
    {
        return $this->status === IncomeStatus::Expected && $this->due_on->lt(now()->startOfDay());
    }
}
