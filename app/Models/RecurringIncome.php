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

/**
 * Espelho de FixedBill do lado da receita: salário, aluguel recebido,
 * qualquer entrada que se repete. Ver FixedBill para o raciocínio da
 * periodicidade — é o mesmo aqui.
 */
#[Fillable([
    'profile_id', 'member_id', 'name', 'amount', 'recurrence', 'due_day', 'due_weekday',
    'due_month', 'bank_account_id', 'category_id', 'is_variable', 'is_active', 'notes',
])]
class RecurringIncome extends Model
{
    use Auditable, BelongsToProfile, HasFactory, HasUuids, RespectsMemberPrivacy;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'recurrence' => RecurrenceType::class,
            'due_day' => 'integer',
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
        return $this->belongsTo(IncomeCategory::class, 'category_id');
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(RecurringIncomeOccurrence::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Todas as datas de recebimento desta receita que caem numa
     * competência — ver FixedBill::occurrencesInMonth, mesma lógica.
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
