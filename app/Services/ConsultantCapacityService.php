<?php

namespace App\Services;

use App\Enums\SubscriptionKind;
use App\Models\ConsultantClient;
use App\Models\Subscription;
use App\Models\User;

/**
 * Quantos clientes a mais um profissional pode vincular na faixa atual da
 * assinatura Professional dele — ver client_cap em Subscription.
 *
 * Sem assinatura Professional, sem acesso corrente, ou com client_cap nulo
 * (cortesia/contrato especial, ex.: Marcelo): sem teto, não bloqueia —
 * mesmo comportamento que o app sempre teve antes desta faixa existir. O
 * teto só entra em ação quando existe uma assinatura corrente com
 * client_cap preenchido.
 */
class ConsultantCapacityService
{
    public function remainingSlots(User $consultant): ?int
    {
        $assinatura = Subscription::query()
            ->where('user_id', $consultant->id)
            ->ofKind(SubscriptionKind::Professional)
            ->latest('created_at')
            ->first();

        if ($assinatura === null || ! $assinatura->isCurrent() || $assinatura->client_cap === null) {
            return null;
        }

        $ativos = ConsultantClient::query()->active()->where('consultant_id', $consultant->id)->count();

        return max(0, $assinatura->client_cap - $ativos);
    }

    public function hasRoomForNewClient(User $consultant): bool
    {
        $restantes = $this->remainingSlots($consultant);

        return $restantes === null || $restantes > 0;
    }
}
