<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Liga o profissional a uma apólice em que ele tem comissão. Dado DELE (professional_id): ver TenancyCoverageTest::EXEMPT.
 * `origin` é "auto" (nasceu da venda: corretor vinculado ou cotação de um lead dele) ou "manual" (o consultor incluiu).
 */
#[Fillable(['professional_id', 'insurance_policy_id', 'origin', 'is_active'])]
class PolicyCommission extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    /** A apólice é de um perfil de cliente: quem lê aqui atravessa perfis de propósito, por isso sem os escopos. */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(InsurancePolicy::class, 'insurance_policy_id')->withoutGlobalScopes();
    }
}
