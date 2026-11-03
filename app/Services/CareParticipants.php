<?php

namespace App\Services;

use App\Models\FinancialProfile;
use App\Models\HealthAppointment;
use App\Models\ProfileCaregiver;
use App\Models\ProfileMember;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Quem faz parte do cuidado de uma pessoa acompanhada: o dono, os cuidadores ativos e a própria pessoa (se já tem login). É o conjunto de quem
 * pode ser escolhido para levá-la a uma consulta.
 */
class CareParticipants
{
    /**
     * Quem pode ser escolhido para levar a pessoa de UMA consulta. Na pessoa acompanhada, quem cuida dela (forProfile). Em perfil comum (os dois idosos
     * e os filhos que acompanham a saúde de cada um): a própria pessoa, quem divide o perfil com ela e os familiares que acompanham a SAÚDE dela. Sem
     * nenhum familiar acompanhando, o "quem leva" não existe ali (o casal combina como sempre combinou).
     *
     * @return Collection<int, User>
     */
    public function forAppointment(HealthAppointment $consulta): Collection
    {
        $perfil = FinancialProfile::withCareProfiles()->findOrFail($consulta->profile_id);

        if ($perfil->isCare()) {
            return $this->forProfile($perfil);
        }

        return $this->forMember($perfil, ProfileMember::query()->withoutGlobalScopes()->findOrFail($consulta->member_id));
    }

    /** @return Collection<int, User> vazio quando ninguém de fora acompanha a saúde dessa pessoa */
    public function forMember(FinancialProfile $perfil, ProfileMember $membro): Collection
    {
        $seguidores = ProfileCaregiver::query()->active()->where('profile_id', $perfil->id)->where('can_view_health', true)
            ->where(fn ($q) => $q->where('member_id', $membro->id)->orWhereNull('member_id'))->with('user')->get()->pluck('user')->filter();

        if ($seguidores->isEmpty()) {
            return collect();
        }

        $dentro = $perfil->activeMembers()->whereNotNull('user_id')->with('user')->get()->pluck('user');

        return $dentro->merge($seguidores)->filter()->unique('id')->sortBy('name')->values();
    }

    /** @return Collection<int, User> sem repetição, ordenados pelo nome */
    public function forProfile(FinancialProfile $perfil): Collection
    {
        $cuidadores = ProfileCaregiver::query()->active()->where('profile_id', $perfil->id)->with('user')->get()->pluck('user');
        $propria = $perfil->activeMembers()->whereNotNull('user_id')->with('user')->get()->pluck('user');

        return collect([$perfil->owner])->merge($cuidadores)->merge($propria)->filter()->unique('id')->sortBy('name')->values();
    }
}
