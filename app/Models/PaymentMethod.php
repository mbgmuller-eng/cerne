<?php

namespace App\Models;

use App\Enums\PaymentMethodType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToProfile;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['profile_id', 'type', 'bank_account_id', 'credit_card_id', 'label', 'is_active'])]
class PaymentMethod extends Model
{
    use Auditable, BelongsToProfile, HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'type' => PaymentMethodType::class,
            'is_active' => 'boolean',
        ];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    public function displayName(): string
    {
        return $this->label
            ?? $this->bankAccount?->displayName()
            ?? $this->creditCard?->displayName()
            ?? $this->type->label();
    }
}
