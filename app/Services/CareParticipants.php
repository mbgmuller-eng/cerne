<?php

namespace App\Services;

use App\Models\FinancialProfile;
use App\Models\ProfileCaregiver;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Quem faz parte do cuidado de uma pessoa acompanhada: o dono, os cuidadores ativos e a própria pessoa (se já tem login). É o conjunto de quem
 * pode ser escolhido para levá-la a uma consulta.
 */
class CareParticipants
{
    /** @return Collection<int, User> sem repetição, ordenados pelo nome */
    public function forProfile(FinancialProfile $perfil): Collection
    {
        $cuidadores = ProfileCaregiver::query()->active()->where('profile_id', $perfil->id)->with('user')->get()->pluck('user');
        $propria = $perfil->activeMembers()->whereNotNull('user_id')->with('user')->get()->pluck('user');

        return collect([$perfil->owner])->merge($cuidadores)->merge($propria)->filter()->unique('id')->sortBy('name')->values();
    }
}
