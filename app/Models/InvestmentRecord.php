<?php

namespace App\Models;

use App\Enums\AssetClass;
use App\Enums\InvestmentSector;
use App\Enums\RecordSource;
use App\Enums\ReturnRateType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\InvalidatesDashboard;
use App\Models\Concerns\RespectsMemberPrivacy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'profile_id', 'member_id', 'sector', 'asset_class', 'ticker', 'name', 'institution',
    'current_amount', 'invested_amount', 'average_price', 'quantity', 'purchase_date',
    'maturity_date', 'return_rate', 'return_rate_type', 'broker_account_id', 'source',
    'external_asset_id', 'is_locked_by_sync', 'is_active', 'notes', 'source_document_id',
    'created_by_user_id',
])]
class InvestmentRecord extends Model
{
    use Auditable, BelongsToProfile, InvalidatesDashboard, HasFactory, HasUuids, RespectsMemberPrivacy;

    protected static string $privacyDomain = 'investment_visibility';

    protected function casts(): array
    {
        return [
            'sector' => InvestmentSector::class,
            'asset_class' => AssetClass::class,
            'return_rate_type' => ReturnRateType::class,
            'source' => RecordSource::class,
            'current_amount' => 'decimal:2',
            'invested_amount' => 'decimal:2',
            'average_price' => 'decimal:6',
            'quantity' => 'decimal:6',
            'purchase_date' => 'date',
            'maturity_date' => 'date',
            'is_locked_by_sync' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(ProfileMember::class, 'member_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(InvestmentTransaction::class, 'investment_id')->orderByDesc('operation_date');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(InvestmentSnapshot::class, 'investment_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Registro vindo do Open Finance é somente leitura até o usuário
     * destravar; a partir daí vira manual e para de ser sobrescrito
     * (seção 11 da especificação).
     */
    public function isReadOnly(): bool
    {
        return $this->source->isReadOnlyByDefault() && ! $this->is_locked_by_sync;
    }

    /** Ganho ou perda não realizada: valor atual menos o investido. */
    public function unrealizedGain(): string
    {
        if ($this->invested_amount === null) {
            return '0.00';
        }

        return bcsub($this->current_amount, $this->invested_amount, 2);
    }

    public function displayName(): string
    {
        return $this->ticker ? $this->ticker.' · '.$this->name : $this->name;
    }
}
