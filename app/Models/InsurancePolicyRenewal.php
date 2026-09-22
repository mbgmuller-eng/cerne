<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma linha por RENOVAÇÃO de apólice — prêmio e cobertura antes/depois,
 * registrada pelo profissional na tela de Datas importantes. Diferente do
 * histórico de investimento (InvestmentSnapshot, uma foto por mês): aqui é
 * por evento, não por competência — apólice renova uma vez por ano, não
 * teria doze pontos pra virar gráfico, então isto é uma lista, não série
 * temporal.
 *
 * O log técnico de toda mudança já existe de graça via InsurancePolicy::
 * Auditable — esta tabela é o histórico PRODUTO, visível na tela.
 */
#[Fillable([
    'insurance_policy_id', 'recorded_by_user_id', 'renewed_at',
    'previous_monthly_premium', 'new_monthly_premium',
    'previous_coverage_amount', 'new_coverage_amount', 'notes',
])]
class InsurancePolicyRenewal extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'renewed_at' => 'date',
            'previous_monthly_premium' => 'decimal:2',
            'new_monthly_premium' => 'decimal:2',
            'previous_coverage_amount' => 'decimal:2',
            'new_coverage_amount' => 'decimal:2',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(InsurancePolicy::class, 'insurance_policy_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
