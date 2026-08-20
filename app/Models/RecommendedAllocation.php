<?php

namespace App\Models;

use App\Enums\AllocationAssetClass;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToProfile;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['profile_id', 'investor_profile_id', 'asset_class', 'target_percentage'])]
class RecommendedAllocation extends Model
{
    use Auditable, BelongsToProfile, HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'asset_class' => AllocationAssetClass::class,
            'target_percentage' => 'decimal:2',
        ];
    }

    public function investorProfile(): BelongsTo
    {
        return $this->belongsTo(InvestorProfile::class);
    }
}
