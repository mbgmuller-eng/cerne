<?php

namespace App\Models;

use App\Enums\TransactionType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\RespectsMemberPrivacy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'profile_id', 'member_id', 'investment_id', 'transaction_type', 'quantity',
    'unit_price', 'total_amount', 'broker_fee', 'other_fees', 'net_amount',
    'operation_date', 'settlement_date', 'source_document_id', 'created_by_user_id',
])]
class InvestmentTransaction extends Model
{
    use Auditable, BelongsToProfile, HasFactory, HasUuids, RespectsMemberPrivacy;

    protected static string $privacyDomain = 'investment_visibility';

    protected function casts(): array
    {
        return [
            'transaction_type' => TransactionType::class,
            'quantity' => 'decimal:6',
            'unit_price' => 'decimal:6',
            'total_amount' => 'decimal:2',
            'broker_fee' => 'decimal:2',
            'other_fees' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'operation_date' => 'date',
            'settlement_date' => 'date',
        ];
    }

    public function investment(): BelongsTo
    {
        return $this->belongsTo(InvestmentRecord::class, 'investment_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(ProfileMember::class, 'member_id');
    }
}
