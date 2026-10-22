<?php

namespace App\Policies;

use App\Models\FinancialProfile;
use App\Models\ProfileCaregiver;
use App\Models\ProfileMember;
use App\Models\User;

/**
 * Quem manda no acesso de UM cuidador (mudar o nível, encerrar): na pessoa acompanhada, o dono dela e ela mesma (se tem login); no perfil comum,
 * a própria pessoa que é acompanhada: cada um do casal convida e revoga só quem acompanha a SUA saúde, e o titular do perfil não mexe no acesso
 * do cônjuge (a privacidade do casal é simétrica).
 */
class ProfileCaregiverPolicy
{
    public function manageGrant(User $user, ProfileCaregiver $acesso): bool
    {
        $perfil = FinancialProfile::withCareProfiles()->find($acesso->profile_id);

        if ($perfil === null) {
            return false;
        }

        if ($perfil->isCare()) {
            $perfis = new FinancialProfilePolicy;

            return $perfis->manageCaregivers($user, $perfil) || $perfis->manageOwnAccess($user, $perfil);
        }

        return $acesso->member_id !== null
            && ProfileMember::query()->whereKey($acesso->member_id)->where('user_id', $user->id)->where('is_active', true)->exists();
    }
}
