<?php

namespace App\Models;

use App\Enums\RecurrenceType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\RespectsMemberPrivacy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'profile_id', 'member_id', 'name', 'amount', 'due_day', 'recurrence', 'due_weekday',
    'due_month', 'bank_account_id', 'credit_card_id', 'category_id', 'subcategory_id',
    'is_variable', 'is_active', 'notes',
])]
class FixedBill extends Model
{
    use Auditable, BelongsToProfile, HasFactory, HasUuids, RespectsMemberPrivacy;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'due_day' => 'integer',
            'recurrence' => RecurrenceType::class,
            'due_weekday' => 'integer',
            'due_month' => 'integer',
            'is_variable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(ProfileMember::class, 'member_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(FixedBillPayment::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Todas as datas de vencimento desta conta que caem numa competência.
     *
     * Mensal e anual: no máximo uma. Semanal: normalmente 4, às vezes 5 —
     * é por isso que a idempotência de FixedBillPayment é por due_date, não
     * mais por (ano, mês) sozinho (ver migration 2026_08_25_100001).
     *
     * @return list<CarbonImmutable>
     */
    public function occurrencesInMonth(int $year, int $month): array
    {
        return match ($this->recurrence) {
            RecurrenceType::Monthly => [$this->dayInMonth($year, $month, $this->due_day)],
            RecurrenceType::Annual => $this->due_month === $month
                ? [$this->dayInMonth($year, $month, $this->due_day)]
                : [],
            RecurrenceType::Weekly => $this->weeklyOccurrences($year, $month),
        };
    }

    /**
     * Cai no último dia do mês quando due_day não existe nele (conta que
     * vence dia 31 não tem dia 31 em fevereiro) — mesma regra do
     * fechamento de cartão (ver CreditCard::dayInMonth).
     */
    private function dayInMonth(int $year, int $month, int $day): CarbonImmutable
    {
        $primeiro = CarbonImmutable::create($year, $month, 1);

        return $primeiro->setDay(min($day, $primeiro->daysInMonth))->startOfDay();
    }

    /** @return list<CarbonImmutable> */
    private function weeklyOccurrences(int $year, int $month): array
    {
        $primeiro = CarbonImmutable::create($year, $month, 1);
        $ocorrencias = [];

        for ($dia = 1; $dia <= $primeiro->daysInMonth; $dia++) {
            $data = $primeiro->setDay($dia);

            if ($data->dayOfWeek === $this->due_weekday) {
                $ocorrencias[] = $data->startOfDay();
            }
        }

        return $ocorrencias;
    }
}
