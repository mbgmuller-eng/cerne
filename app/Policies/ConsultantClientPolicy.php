<?php

namespace App\Policies;

use App\Models\ConsultantClient;
use App\Models\User;

/**
 * Só a própria pessoa convidada responde ao pedido de vínculo.
 *
 * O link é assinado (URL::temporarySignedRoute) — prova que não foi
 * forjado — mas isso sozinho não prova que quem clicou é o dono da conta.
 * Por isso a rota exige login e esta policy confere `client_id` contra o
 * usuário autenticado antes de qualquer leitura ou ação.
 */
class ConsultantClientPolicy
{
    public function respond(User $user, ConsultantClient $consultantClient): bool
    {
        return $consultantClient->client_id === $user->id;
    }
}
