<?php

namespace App\Policies;

use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;

/**
 * Autorização de acesso a um perfil financeiro.
 *
 * A ordem de verificação segue a seção 14 da especificação:
 *   1. o usuário é o dono (owner_user_id)?
 *   2. ou é membro do perfil (casal)?
 *   3. ou é consultor vinculado via consultant_clients — acesso irrestrito?
 *
 * O que esta policy NÃO decide é o que o membro secundário enxerga dentro
 * do perfil: isso é a privacidade do casal, aplicada por
 * RespectsMemberPrivacy no nível da query.
 */
class FinancialProfilePolicy
{
    public function view(User $user, FinancialProfile $profile): bool
    {
        return $this->hasAccess($user, $profile);
    }

    public function update(User $user, FinancialProfile $profile): bool
    {
        // Alterar o perfil em si (nome, tipo, moeda) é do dono ou do consultor.
        return $this->isOwner($user, $profile)
            || $this->isLinkedConsultant($user, $profile);
    }

    public function delete(User $user, FinancialProfile $profile): bool
    {
        return $this->isOwner($user, $profile);
    }

    /** A trilha de auditoria é visível apenas ao consultor. */
    public function viewAuditLog(User $user, FinancialProfile $profile): bool
    {
        return $this->isLinkedConsultant($user, $profile) || $user->isAdmin();
    }

    /** Convidar o cônjuge pro perfil — titular ou consultor vinculado, os dois podem. */
    public function manageMembers(User $user, FinancialProfile $profile): bool
    {
        return $this->isOwner($user, $profile) || $this->isLinkedConsultant($user, $profile);
    }

    // ---------------------------------------------------------------------

    /**
     * "Consegue abrir esse perfil" — não confundir com "vê tudo dentro
     * dele". Um corretor vinculado passa aqui (senão nem trocaria de
     * perfil pra chegar em Seguros), mas cada tela financeira barra ele
     * no próprio mount() (ver App\Livewire\Concerns — telas fora de
     * Seguros exigem isLinkedConsultant explicitamente, não só hasAccess).
     */
    public function hasAccess(User $user, FinancialProfile $profile): bool
    {
        return $this->isOwner($user, $profile)
            || $this->isMember($user, $profile)
            || $this->isLinkedConsultant($user, $profile)
            || $this->isLinkedBroker($user, $profile);
    }

    private function isOwner(User $user, FinancialProfile $profile): bool
    {
        return $profile->owner_user_id === $user->id;
    }

    private function isMember(User $user, FinancialProfile $profile): bool
    {
        return ProfileMember::query()
            ->where('profile_id', $profile->id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Consultor vinculado ao DONO do perfil, com vínculo ativo. Um consultor
     * sem vínculo não tem acesso algum — o papel por si só não basta.
     */
    private function isLinkedConsultant(User $user, FinancialProfile $profile): bool
    {
        if (! $user->isConsultant()) {
            return false;
        }

        return ConsultantClient::query()
            ->active()
            ->where('consultant_id', $user->id)
            ->where('client_id', $profile->owner_user_id)
            ->exists();
    }

    /**
     * Corretor vinculado ao dono do perfil, com vínculo ativo. Mesma
     * checagem de isLinkedConsultant(), papel diferente — um corretor
     * NUNCA entra em update()/manageMembers()/viewAuditLog() acima, só em
     * hasAccess(): ele abre o perfil, mas não administra nada nele, e
     * dentro de Seguros só vê as apólices que têm seu próprio broker_id
     * (ver InsurancePolicyBrokerScope).
     */
    private function isLinkedBroker(User $user, FinancialProfile $profile): bool
    {
        if (! $user->isBroker()) {
            return false;
        }

        return ConsultantClient::query()
            ->active()
            ->where('consultant_id', $user->id)
            ->where('client_id', $profile->owner_user_id)
            ->exists();
    }
}
