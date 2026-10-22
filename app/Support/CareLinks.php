<?php

namespace App\Support;

use App\Models\FinancialProfile;

/**
 * Links dos avisos de saúde. O aviso de uma pessoa acompanhada vai a quem cuida dela, que está logado no PRÓPRIO perfil: o link comum abriria a
 * agenda dele, não a dela. Por isso o link passa por `care.goto`, que escolhe a pessoa (depois de autorizar) e segue para a tela.
 */
class CareLinks
{
    /** O id do perfil se ele for de uma pessoa acompanhada; nulo para perfil comum. */
    public static function careProfileId(string $profileId): ?string
    {
        $perfil = FinancialProfile::withCareProfiles()->find($profileId);

        return $perfil?->isCare() ? $perfil->id : null;
    }

    public static function to(?string $careProfileId, string $destino, string $rotaComum): string
    {
        return $careProfileId !== null
            ? route('care.goto', ['profile' => $careProfileId, 'destino' => $destino])
            : route($rotaComum);
    }
}
