<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\InvalidatesDashboard;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fatura de um ciclo do cartão.
 *
 * Não usa RespectsMemberPrivacy: a fatura herda a privacidade do cartão,
 * e filtrar aqui exigiria um join que o escopo global não consegue montar
 * de forma confiável. As telas sempre chegam à fatura A PARTIR do cartão,
 * que já é filtrado.
 */
#[Fillable([
    'profile_id', 'credit_card_id', 'year', 'month', 'closing_date', 'due_date',
    'total_amount', 'status', 'paid_at', 'paid_amount', 'paid_from_account_id',
])]
class CreditCardInvoice extends Model
{
    use Auditable, BelongsToProfile, InvalidatesDashboard, HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'closing_date' => 'date',
            'due_date' => 'date',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'paid_at' => 'date',
            'status' => InvoiceStatus::class,
        ];
    }

    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    public function paidFromAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'paid_from_account_id');
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', [
            InvoiceStatus::Open,
            InvoiceStatus::Closed,
            InvoiceStatus::Overdue,
        ]);
    }

    public function scopeForPeriod(Builder $query, int $year, int $month): Builder
    {
        return $query->where('year', $year)->where('month', $month);
    }

    public function competenceLabel(): string
    {
        return str_pad((string) $this->month, 2, '0', STR_PAD_LEFT).'/'.$this->year;
    }

    public function isPaid(): bool
    {
        return $this->status === InvoiceStatus::Paid;
    }
}
