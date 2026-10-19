<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Liga o profissional a um consórcio de cliente em que ele tem comissão. `role` é "sold" (ele vendeu: vale a regra da
 * administradora) ou "third_party" (um terceiro vendeu: vale só o percentual que ele informar em `percent_override`).
 * Dado DELE (professional_id): ver TenancyCoverageTest::EXEMPT.
 */
#[Fillable(['professional_id', 'debt_id', 'role', 'percent_override', 'schedule_override', 'is_active'])]
class DebtCommission extends Model
{
    use HasFactory, HasUuids;

    public const ROLE_SOLD = 'sold';

    public const ROLE_THIRD_PARTY = 'third_party';

    protected function casts(): array
    {
        return [
            'percent_override' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    /** O consórcio é de um perfil de cliente: quem lê aqui atravessa perfis de propósito, por isso sem os escopos. */
    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class)->withoutGlobalScopes();
    }
}
