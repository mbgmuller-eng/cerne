<?php

namespace App\Models;

use App\Enums\Benchmark;
use App\Enums\PeriodType;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Scopes\InheritedInvestmentPrivacyScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'profile_id', 'member_id', 'investment_id', 'period_type', 'year', 'month',
    'return_amount', 'return_percentage', 'benchmark', 'benchmark_return',
    'vs_benchmark', 'institution', 'source_document_id',
])]
class InvestmentPerformance extends Model
{
    use BelongsToProfile, HasFactory, HasUuids;

    protected static function booted(): void
    {
        static::addGlobalScope(new InheritedInvestmentPrivacyScope);
    }

    protected $table = 'investment_performance';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'period_type' => PeriodType::class,
            'benchmark' => Benchmark::class,
            'year' => 'integer',
            'month' => 'integer',
            'return_amount' => 'decimal:2',
            'return_percentage' => 'decimal:4',
            'benchmark_return' => 'decimal:4',
            'vs_benchmark' => 'decimal:4',
            'created_at' => 'datetime',
        ];
    }

    public function investment(): BelongsTo
    {
        return $this->belongsTo(InvestmentRecord::class, 'investment_id');
    }

    /** Rentabilidade da carteira inteira, não de um ativo específico. */
    public function isPortfolioWide(): bool
    {
        return $this->investment_id === null;
    }

    /** Bateu o benchmark? */
    public function beatBenchmark(): ?bool
    {
        if ($this->vs_benchmark === null) {
            return null;
        }

        return bccomp($this->vs_benchmark, '0', 4) > 0;
    }

    public function periodLabel(): string
    {
        return match ($this->period_type) {
            PeriodType::Monthly => str_pad((string) $this->month, 2, '0', STR_PAD_LEFT).'/'.$this->year,
            PeriodType::Yearly => (string) $this->year,
            PeriodType::Inception => 'Desde o início',
        };
    }
}
