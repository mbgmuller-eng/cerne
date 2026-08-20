<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\InvalidatesDashboard;
use App\Models\Concerns\HasSharingFlags;
use App\Models\Concerns\RespectsMemberPrivacy;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'profile_id', 'member_id', 'bank_name', 'account_type', 'agency', 'account_number',
    'current_balance', 'is_joint', 'visible_to_partner', 'included_in_consolidated',
    'color_hex', 'is_active', 'notes',
])]
class BankAccount extends Model
{
    use Auditable, BelongsToProfile, InvalidatesDashboard, HasFactory, HasSharingFlags, HasUuids, RespectsMemberPrivacy;

    protected static string $privacyDomain = 'bank_account_visibility';

    protected function casts(): array
    {
        return [
            'account_type' => AccountType::class,
            'current_balance' => 'decimal:2',
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

    public function displayName(): string
    {
        return $this->bank_name.' · '.$this->account_type->label();
    }

    /**
     * Movimenta o saldo de forma atômica.
     *
     * O incremento acontece no banco (`saldo = saldo + valor`) e não em
     * PHP: ler-somar-gravar perderia um lançamento se dois acontecessem
     * ao mesmo tempo na mesma conta. Valor negativo debita.
     */
    public function applyToBalance(string|float|int $amount): void
    {
        $delta = (float) Money::parse($amount);

        if ($delta === 0.0) {
            return;
        }

        $this->newQuery()->whereKey($this->getKey())->increment('current_balance', $delta);

        $this->refresh();
    }
}
