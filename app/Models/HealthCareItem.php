<?php

namespace App\Models;

use App\Enums\HealthCareCategory;
use App\Enums\HealthCareIntervalUnit;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\IsCoupleHealthData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item de saúde com troca, renovação ou revisão periódica (ver a migration).
 */
#[Fillable([
    'profile_id', 'member_id', 'category', 'name', 'device_name', 'interval_value', 'interval_unit',
    'last_done_on', 'next_due_on', 'notes', 'is_active', 'created_by_member_id',
])]
class HealthCareItem extends Model
{
    use BelongsToProfile, HasUuids, IsCoupleHealthData;

    protected function casts(): array
    {
        return [
            'category' => HealthCareCategory::class,
            'interval_unit' => HealthCareIntervalUnit::class,
            'interval_value' => 'integer',
            'last_done_on' => 'date',
            'next_due_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(ProfileMember::class, 'created_by_member_id');
    }

    /** Quando vence se a última vez foi em `$desde`. */
    public function dueAfter(\Carbon\CarbonInterface $desde): CarbonImmutable
    {
        return $this->interval_unit->addTo($desde, $this->interval_value);
    }

    /** "a cada 15 dias", "a cada 6 meses", "a cada mês". */
    public function frequencyLabel(): string
    {
        $unidade = $this->interval_value === 1 ? $this->interval_unit->singular() : $this->interval_unit->label();

        return $this->interval_value === 1 ? "a cada {$unidade}" : "a cada {$this->interval_value} {$unidade}";
    }

    /** Dias até vencer: negativo = atrasado, 0 = hoje. Nulo quando não há próxima data. */
    public function daysUntilDue(?CarbonImmutable $hoje = null): ?int
    {
        if ($this->next_due_on === null) {
            return null;
        }

        $hoje ??= CarbonImmutable::today();

        return (int) $hoje->startOfDay()->diffInDays($this->next_due_on->startOfDay(), false);
    }
}
