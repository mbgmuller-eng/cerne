<?php

namespace App\Models;

use App\Enums\AdjustmentIndex;
use App\Enums\ConsortiumType;
use App\Enums\ContemplationMode;
use App\Enums\DebtAmortization;
use App\Enums\DebtInstallmentStatus;
use App\Enums\DebtKind;
use App\Enums\DebtRateBasis;
use App\Enums\DebtStatus;
use App\Enums\Necessity;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\InvalidatesDashboard;
use App\Models\Concerns\RespectsMemberPrivacy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma dívida com cronograma: financiamento ou empréstimo (consórcio e dívida em aberto chegam nas próximas fases).
 *
 * O contrato guarda a classificação PADRÃO das parcelas (categoria e subcategoria), mas cada parcela paga vira uma despesa com
 * a sua própria categoria (ver DebtService): a classificação pode mudar com o momento da dívida.
 *
 * Privacidade como a das demais despesas: do dono, visível ao casal, a não ser que marcada como privada; sem dono (member_id
 * nulo) é da família.
 */
#[Fillable([
    'profile_id', 'member_id', 'is_private', 'kind', 'status', 'name', 'creditor', 'principal', 'amortization', 'rate',
    'rate_basis', 'term', 'first_due_date', 'installment_amount', 'monthly_fees', 'paid_before', 'necessity',
    'category_id', 'subcategory_id', 'bank_account_id', 'notes', 'created_by_user_id',
    'consortium_type', 'consortium_group', 'credit_amount', 'is_flex', 'adjustment_index', 'adjustment_every_months',
    'adjustment_first_after_months', 'next_adjustment_on', 'contemplated_on', 'contemplation_mode', 'bid_amount', 'credit_released',
    'current_balance', 'balance_updated_on', 'agreement_on', 'agreement_total', 'agreement_discount',
    'creditor_document', 'contracted_on', 'flex_percent', 'consortium_contract', 'statement_on', 'statement_balance', 'statement_remaining', 'insurance_percent', 'last_adjustment_on',
])]
class Debt extends Model
{
    use Auditable, BelongsToProfile, HasUuids, InvalidatesDashboard, RespectsMemberPrivacy;

    protected function casts(): array
    {
        return [
            'kind' => DebtKind::class,
            'status' => DebtStatus::class,
            'amortization' => DebtAmortization::class,
            'rate_basis' => DebtRateBasis::class,
            'necessity' => Necessity::class,
            'principal' => 'decimal:2',
            'rate' => 'decimal:6',
            'installment_amount' => 'decimal:2',
            'monthly_fees' => 'decimal:2',
            'first_due_date' => 'date',
            'contracted_on' => 'date',
            'term' => 'integer',
            'paid_before' => 'integer',
            'is_private' => 'boolean',
            'consortium_type' => ConsortiumType::class,
            'credit_amount' => 'decimal:2',
            'is_flex' => 'boolean',
            'flex_percent' => 'integer',
            'statement_on' => 'date',
            'statement_balance' => 'decimal:2',
            'statement_remaining' => 'integer',
            'insurance_percent' => 'decimal:6',
            'last_adjustment_on' => 'date',
            'adjustment_index' => AdjustmentIndex::class,
            'adjustment_every_months' => 'integer',
            'adjustment_first_after_months' => 'integer',
            'next_adjustment_on' => 'date',
            'contemplated_on' => 'date',
            'contemplation_mode' => ContemplationMode::class,
            'bid_amount' => 'decimal:2',
            'credit_released' => 'decimal:2',
            'current_balance' => 'decimal:2',
            'balance_updated_on' => 'date',
            'agreement_on' => 'date',
            'agreement_total' => 'decimal:2',
            'agreement_discount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        // A privacidade das parcelas é a do contrato: quem muda o dono ou o "privado" muda nas parcelas também.
        static::updated(function (self $divida): void {
            if ($divida->wasChanged(['member_id', 'is_private'])) {
                DebtInstallment::withoutGlobalScopes()->where('debt_id', $divida->id)->update(['member_id' => $divida->member_id, 'is_private' => $divida->is_private]);
            }
        });
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(ProfileMember::class, 'member_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseSubcategory::class, 'subcategory_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function installments(): HasMany
    {
        return $this->hasMany(DebtInstallment::class)->orderBy('number');
    }

    /**
     * Quanto ainda deve. Em SQL, nunca em coleção (regra 3). Com cronograma calculado (SAC/Price) é a soma das AMORTIZAÇÕES em
     * aberto; com parcelas informadas não há como separar os juros, então é o total a pagar, a soma das parcelas em aberto.
     */
    public function balance(): string
    {
        // Dívida em aberto, ainda sem acordo: não há parcelas, o saldo é o que a pessoa informou.
        if ($this->status->isUnscheduled()) {
            return number_format((float) $this->current_balance, 2, '.', '');
        }

        $coluna = $this->amortization === DebtAmortization::Fixed ? 'amount' : 'principal_amount';

        return number_format((float) $this->installments()->where('status', DebtInstallmentStatus::Open)->sum($coluna), 2, '.', '');
    }

    public function paidCount(): int
    {
        return $this->installments()->where('status', DebtInstallmentStatus::Paid)->count();
    }

    /**
     * Estimativa da parcela cheia se o consórcio flex fosse contemplado agora, sem lance: o que o extrato diz que falta pagar,
     * dividido pelas parcelas que restam, mais o seguro. É só uma ordem de grandeza (o lance e o reajuste mudam a conta).
     */
    public function estimatedFullInstallment(): ?string
    {
        if (! $this->isSavingForCredit() || $this->statement_balance === null || ! $this->statement_remaining) {
            return null;
        }

        $base = bcdiv((string) $this->statement_balance, (string) $this->statement_remaining, 6);
        $seguro = $this->insurance_percent !== null ? bcmul(bcdiv((string) $this->insurance_percent, '100', 8), (string) $this->credit_amount, 6) : '0';

        return bcadd(bcadd($base, $seguro, 6), '0.005', 2);
    }

    public function isConsortium(): bool
    {
        return $this->kind === DebtKind::Consortium;
    }

    public function isContemplated(): bool
    {
        return $this->contemplated_on !== null;
    }

    /** Consórcio ainda não contemplado: é dinheiro sendo juntado para a carta, não uma dívida. */
    public function isSavingForCredit(): bool
    {
        return $this->isConsortium() && ! $this->isContemplated();
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(DebtAdjustment::class)->orderByDesc('applied_on');
    }

    public function isOpen(): bool
    {
        return $this->status === DebtStatus::Active;
    }

    /** A etiqueta de "como o saldo foi calculado" para a tela: devedor de verdade ou total a pagar. */
    public function balanceLabel(): string
    {
        if ($this->status->isUnscheduled()) {
            return 'Saldo atual';
        }

        return $this->amortization === DebtAmortization::Fixed ? 'Total a pagar' : 'Saldo devedor';
    }
}
