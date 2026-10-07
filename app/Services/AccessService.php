<?php

namespace App\Services;

use App\Enums\SubscriptionKind;
use App\Models\ConsultantClient;
use App\Models\Subscription;
use App\Models\User;

/**
 * "Esta conta pode usar o Cerne agora?" — a pergunta do bloqueio geral (ver
 * RequiresActiveSubscription), diferente do EntitlementService, que decide
 * qual MÓDULO um perfil enxerga.
 *
 * Tem acesso quem: é administrador da plataforma; tem assinatura própria em
 * vigor (profissional, para consultor/corretor; direta, para os demais); ou
 * é cliente vinculado e ativo de um profissional com assinatura em vigor
 * (os clientes dele recebem tudo sem pagar).
 */
class AccessService
{
    public function hasAccess(User $usuario): bool
    {
        if ($usuario->isPlatformAdmin()) {
            return true;
        }

        $propria = $usuario->isLinkedProfessional() ? SubscriptionKind::Professional : SubscriptionKind::Direct;

        if ($this->temAssinaturaEmVigor($usuario->id, $propria)) {
            return true;
        }

        // Cliente vinculado a um profissional: acesso pela assinatura dele.
        $profissionais = ConsultantClient::query()->active()->where('client_id', $usuario->id)->pluck('consultant_id');

        return $profissionais->isNotEmpty()
            && Subscription::query()
                ->ofKind(SubscriptionKind::Professional)
                ->whereIn('user_id', $profissionais)
                ->get()
                ->contains(fn (Subscription $assinatura) => $assinatura->isCurrent());
    }

    private function temAssinaturaEmVigor(string $userId, SubscriptionKind $kind): bool
    {
        return Subscription::query()
            ->ofKind($kind)
            ->where('user_id', $userId)
            ->get()
            ->contains(fn (Subscription $assinatura) => $assinatura->isCurrent());
    }
}
