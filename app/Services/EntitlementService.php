<?php

namespace App\Services;

use App\Enums\PlatformModule;
use App\Enums\SubscriptionKind;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\Subscription;

/**
 * A pergunta que todo gate de módulo faz: este perfil tem acesso àquele
 * produto (Finanças/Seguros/Documentos/Saúde) agora?
 *
 * Duas fontes, nesta ordem: (1) assinatura DIRETA do dono do perfil; (2)
 * se não, assinatura PROFISSIONAL de algum consultor/corretor vinculado e
 * ativo a ele — reaproveita ConsultantClient::active(), o mesmo escopo
 * que FinancialProfilePolicy já usa pra decidir "o profissional abre esse
 * perfil". Sem cache de propósito: são 1-2 queries indexadas por
 * (user_id, kind, status), não uma agregação pesada — ver a memória do
 * bug de cache do dashboard antes de considerar adicionar um aqui.
 */
class EntitlementService
{
    public function profileHasModule(FinancialProfile $profile, PlatformModule $modulo): bool
    {
        if ($this->hasDirectAccess($profile->owner_user_id, $modulo)) {
            return true;
        }

        $profissionaisVinculados = ConsultantClient::query()
            ->active()
            ->where('client_id', $profile->owner_user_id)
            ->pluck('consultant_id');

        if ($profissionaisVinculados->isEmpty()) {
            return false;
        }

        return Subscription::query()
            ->ofKind(SubscriptionKind::Professional)
            ->whereIn('user_id', $profissionaisVinculados)
            ->get()
            ->contains(fn (Subscription $assinatura) => $assinatura->isCurrent() && $assinatura->bundle->includes($modulo));
    }

    private function hasDirectAccess(string $userId, PlatformModule $modulo): bool
    {
        return Subscription::query()
            ->ofKind(SubscriptionKind::Direct)
            ->where('user_id', $userId)
            ->get()
            ->contains(fn (Subscription $assinatura) => $assinatura->isCurrent() && $assinatura->bundle->includes($modulo));
    }
}
