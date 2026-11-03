<?php

namespace App\Models\Scopes;

use App\Support\ProfileContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Fatura e vencimento de conta fixa herdam a privacidade do cartão e da conta de origem, mas não a filtram sozinhos (as telas sempre chegam a eles a
 * partir do pai). Para quem soma ou lista direto neles (o painel, os avisos, o familiar que acompanha as finanças) isso não basta: um total de faturas ou
 * uma lista de vencimentos entregaria o que o casal marcou como privado, por agregado. Por isso, para quem opera dentro de um perfil, só existe o filho
 * cujo pai ele enxerga, no cônjuge e no familiar.
 *
 * Não age sem perfil ativo (as rotinas agendadas atravessam perfis de propósito, e lá o pai já é conferido à mão) nem para o consultor, que vê tudo.
 */
class ParentPrivacyScope implements Scope
{
    public function __construct(private readonly string $relation) {}

    public function apply(Builder $builder, Model $model): void
    {
        $contexto = app(ProfileContext::class);

        if ($contexto->hasProfile() && ! $contexto->isConsultant()) {
            $builder->whereHas($this->relation);
        }
    }
}