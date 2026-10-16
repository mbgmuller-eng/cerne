<?php

namespace App\Models;

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
            'term' => 'integer',
            'paid_before' => 'integer',
            'is_private' => 'boolean',
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
        $coluna = $this->amortization === DebtAmortization::Fixed ? 'amount' : 'principal_amount';

        return number_format((float) $this->installments()->where('status', DebtInstallmentStatus::Open)->sum($coluna), 2, '.', '');
    }

    public function paidCount(): int
    {
        return $this->installments()->where('status', DebtInstallmentStatus::Paid)->count();
    }

    public function isOpen(): bool
    {
        return $this->status === DebtStatus::Active;
    }

    /** A etiqueta de "como o saldo foi calculado" para a tela: devedor de verdade ou total a pagar. */
    public function balanceLabel(): string
    {
        return $this->amortization === DebtAmortization::Fixed ? 'Total a pagar' : 'Saldo devedor';
    }
}
