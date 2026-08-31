<?php

namespace App\Models;

use App\Enums\Necessity;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\InvalidatesDashboard;
use App\Models\Concerns\HasCompetence;
use App\Models\Concerns\RespectsMemberPrivacy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'profile_id', 'member_id', 'description', 'necessity', 'category_id', 'subcategory_id',
    'amount', 'expense_date', 'bank_account_id', 'credit_card_id', 'credit_card_invoice_id',
    'installment_group_id', 'installment_number', 'notes', 'source_document_id',
    'created_by_user_id', 'is_private',
])]
class ExpenseRecord extends Model
{
    use Auditable, BelongsToProfile, InvalidatesDashboard, HasCompetence, HasFactory, HasUuids, RespectsMemberPrivacy;

    protected static string $competenceDate = 'expense_date';

    protected function casts(): array
    {
        return [
            'necessity' => Necessity::class,
            'amount' => 'decimal:2',
            'expense_date' => 'date',
            'year' => 'integer',
            'month' => 'integer',
            'installment_number' => 'integer',
            'is_private' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Um lançamento é débito OU crédito. Aceitar os dois faria a despesa
        // ser contada duas vezes: uma no saldo da conta e outra na fatura.
        $validate = function (ExpenseRecord $record): void {
            if ($record->bank_account_id !== null && $record->credit_card_id !== null) {
                throw new LogicException(
                    'Um lançamento não pode ser débito e crédito ao mesmo tempo.'
                );
            }

            if ($record->credit_card_invoice_id !== null && $record->credit_card_id === null) {
                throw new LogicException(
                    'Lançamento vinculado a uma fatura precisa informar o cartão.'
                );
            }
        };

        static::creating($validate);
        static::updating($validate);
    }

    // ---------------------------------------------------------------------
    // Relacionamentos
    // ---------------------------------------------------------------------

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
        return $this->belongsTo(BankAccount::class);
    }

    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CreditCardInvoice::class, 'credit_card_invoice_id');
    }

    public function installmentGroup(): BelongsTo
    {
        return $this->belongsTo(InstallmentGroup::class);
    }

    // ---------------------------------------------------------------------

    public function isInstallment(): bool
    {
        return $this->installment_group_id !== null;
    }

    public function isOnCredit(): bool
    {
        return $this->credit_card_id !== null;
    }

    /** "3 de 10" para as parcelas; vazio para pagamento único. */
    public function installmentLabel(): string
    {
        if (! $this->isInstallment()) {
            return '';
        }

        return $this->installment_number.' de '.$this->installmentGroup->total_installments;
    }

    public function scopeOfNecessity(Builder $query, Necessity $necessity): Builder
    {
        return $query->where($this->qualifyColumn('necessity'), $necessity);
    }

    public function scopeOnCredit(Builder $query): Builder
    {
        return $query->whereNotNull($this->qualifyColumn('credit_card_id'));
    }
}
