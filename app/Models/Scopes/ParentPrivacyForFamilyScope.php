<?php

namespace App\Models\Scopes;

use App\Support\ProfileContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Fatura e vencimento de conta fixa herdam a privacidade do cartão e da conta de origem, mas não a filtram sozinhos (as telas sempre chegam a
 * eles a partir do pai). Para quem acompanha as finanças isso não basta: um total de faturas ou uma lista de vencimentos montada direto neles
 * entregaria o que o casal marcou como privado. Para esse acompanhante, só existe o filho cujo pai ele enxerga.
 *
 * Quem não é acompanhante passa sem efeito algum.
 */
class ParentPrivacyForFamilyScope implements Scope
{
    public function __construct(private readonly string $relation) {}

    public function apply(Builder $builder, Model $model): void
    {
        if (app(ProfileContext::class)->isCaregiver()) {
            $builder->whereHas($this->relation);
        }
    }
}