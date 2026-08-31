<?php

namespace App\Models;

use App\Enums\CardBrand;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\HasSharingFlags;
use App\Models\Concerns\RespectsMemberPrivacy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'profile_id', 'member_id', 'card_name', 'bank_name', 'card_brand', 'credit_limit',
    'closing_day', 'due_day', 'last_four_digits', 'is_joint', 'visible_to_partner',
    'included_in_consolidated', 'color_hex', 'is_active',
])]
class CreditCard extends Model
{
    use Auditable, BelongsToProfile, HasFactory, HasSharingFlags, HasUuids, RespectsMemberPrivacy;

    protected function casts(): array
    {
        return [
            'card_brand' => CardBrand::class,
            'credit_limit' => 'decimal:2',
            'closing_day' => 'integer',
            'due_day' => 'integer',
            'is_joint' => 'boolean',
            'visible_to_partner' => 'boolean',
            'included_in_consolidated' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(ProfileMember::class, 'member_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(CreditCardInvoice::class);
    }

    public function displayName(): string
    {
        $suffix = $this->last_four_digits ? " ····{$this->last_four_digits}" : '';

        return $this->card_name.$suffix;
    }

    /**
     * Tem alguma fatura, compra ou conta fixa vinculada? Se sim, o cartão
     * não pode ser excluído — só desativado. Mesmo raciocínio de
     * BankAccount::hasActivity(); PaymentMethod fica de fora (cascade,
     * não é lançamento).
     */
    public function hasActivity(): bool
    {
        return ExpenseRecord::query()->where('credit_card_id', $this->id)->exists()
            || CreditCardInvoice::query()->where('credit_card_id', $this->id)->exists()
            || FixedBill::query()->where('credit_card_id', $this->id)->exists()
            || InstallmentGroup::query()->where('credit_card_id', $this->id)->exists();
    }

    // ---------------------------------------------------------------------
    // Ciclo de fatura
    // ---------------------------------------------------------------------

    /**
     * Data de fechamento da fatura de uma competência.
     *
     * Cartão que fecha dia 31 não tem dia 31 em fevereiro. Em vez de pular
     * o mês ou estourar para o mês seguinte, o fechamento cai no último dia
     * do mês — que é o comportamento dos emissores.
     */
    public function closingDateFor(int $year, int $month): CarbonImmutable
    {
        return $this->dayInMonth($year, $month, $this->closing_day);
    }

    /**
     * Vencimento da fatura de uma competência.
     *
     * Quando o vencimento é um dia MENOR que o fechamento (fecha dia 28,
     * vence dia 5), ele pertence ao mês seguinte — é o intervalo normal
     * entre fechar e pagar.
     */
    public function dueDateFor(int $year, int $month): CarbonImmutable
    {
        if ($this->due_day > $this->closing_day) {
            return $this->dayInMonth($year, $month, $this->due_day);
        }

        $next = CarbonImmutable::create($year, $month, 1)->addMonth();

        return $this->dayInMonth($next->year, $next->month, $this->due_day);
    }

    /**
     * Em qual competência (ano/mês) uma compra desta data cai.
     *
     * Comprou depois do fechamento? Entra na fatura do mês seguinte.
     *
     * @return array{int, int} [ano, mês]
     */
    public function billingPeriodFor(CarbonImmutable $purchaseDate): array
    {
        $closing = $this->closingDateFor($purchaseDate->year, $purchaseDate->month);

        if ($purchaseDate->lessThanOrEqualTo($closing)) {
            return [$purchaseDate->year, $purchaseDate->month];
        }

        $next = $purchaseDate->startOfMonth()->addMonth();

        return [$next->year, $next->month];
    }

    private function dayInMonth(int $year, int $month, int $day): CarbonImmutable
    {
        $first = CarbonImmutable::create($year, $month, 1);

        return $first->setDay(min($day, $first->daysInMonth))->startOfDay();
    }
}
