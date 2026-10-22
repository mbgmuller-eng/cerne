<?php

namespace App\Services;

use App\Models\FinancialProfile;
use App\Models\ProfileCaregiver;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Quem recebe o lembrete de saúde de um perfil (consulta amanhã, troca de item).
 *
 * Perfil comum (individual ou casal): os usuários dos membros que deram o consentimento de saúde; sem consentimento, nem avisar.
 * Pessoa acompanhada: a pessoa normalmente não tem login, então o aviso vai para o dono e para os cuidadores com avisos ligados. O
 * consentimento que vale ali é o dela (declaração de quem cadastrou), não o de cada destinatário; sem a declaração, ninguém é avisado.
 */
class HealthReminderRecipients
{
    /**
     * Quem é avisado quando algo muda numa consulta ou exame (agendada, remarcada, cancelada, confirmada, quem leva...): a pessoa de quem é o
     * compromisso e quem a acompanha. Na pessoa acompanhada, o dono, os cuidadores (que ligaram o aviso do tipo) e a própria pessoa, se tem login.
     * Num perfil comum, a pessoa e os cuidadores DELA: o cônjuge que divide o perfil não entra (já vê a agenda, e a mudança é de outra pessoa).
     * Quem fez a mudança sai da lista (quem chama).
     *
     * @return Collection<int, User>
     */
    public function forAppointmentChange(\App\Models\HealthAppointment $consulta): Collection
    {
        return $this->forChange($consulta->profile_id, $consulta->member_id, 'appointments');
    }

    /** O mesmo, para um item de cuidado (filtro do aparelho, palmilha...): vale o aviso "troca de itens" de cada cuidador. */
    public function forCareItemChange(\App\Models\HealthCareItem $item): Collection
    {
        return $this->forChange($item->profile_id, $item->member_id, 'care_items');
    }

    /** @return Collection<int, User> */
    private function forChange(string $profileId, string $memberId, string $kind): Collection
    {
        $perfil = FinancialProfile::withCareProfiles()->find($profileId);

        if ($perfil === null) {
            return collect();
        }

        if ($perfil->isCare()) {
            return $this->forProfile($perfil->id, $kind, $memberId);
        }

        $membro = \App\Models\ProfileMember::query()->with('user')->find($memberId);
        $sujeito = $membro?->user;

        // Vale o consentimento de saúde de quem é acompanhado: sem ele, ninguém é avisado.
        if ($sujeito === null || ! $sujeito->hasCurrentHealthConsent()) {
            return collect();
        }

        $seguidores = ProfileCaregiver::query()->active()->where('profile_id', $perfil->id)
            ->where(fn ($q) => $q->where('member_id', $membro->id)->orWhereNull('member_id'))
            ->where($kind === 'care_items' ? 'notify_care_items' : 'notify_reminders', true)->with('user')->get()->pluck('user');

        return collect([$sujeito])->merge($seguidores)->filter()->unique('id')->values();
    }

    /**
     * @param  string  $kind  'appointments' (consultas e exames) ou 'care_items' (troca de itens): cada cuidador escolhe quais quer receber
     * @return Collection<int, User>
     */
    public function forProfile(string $profileId, string $kind = 'appointments', ?string $memberId = null): Collection
    {
        $perfil = FinancialProfile::withCareProfiles()->find($profileId);

        if ($perfil === null) {
            return collect();
        }

        if ($perfil->isCare()) {
            // Sem a declaração de consentimento, ou com a assinatura de quem paga fora de vigor, ninguém é avisado.
            if ($perfil->care_consent_at === null || ! app(EntitlementService::class)->careProfileEntitled($perfil)) {
                return collect();
            }

            $cuidadores = ProfileCaregiver::query()->active()->where('profile_id', $perfil->id)->where($kind === 'care_items' ? 'notify_care_items' : 'notify_reminders', true)->with('user')->get()->pluck('user');

            // A própria pessoa, se já tem login, recebe os avisos dela.
            $propria = $perfil->activeMembers()->whereNotNull('user_id')->with('user')->get()->pluck('user');

            return collect([$perfil->owner])->merge($cuidadores)->merge($propria)->filter()->unique('id')->values();
        }

        $membros = $perfil->activeMembers()->whereNotNull('user_id')->with('user')->get();
        $destinatarios = $membros->pluck('user')->filter(fn (?User $user) => $user !== null && $user->hasCurrentHealthConsent());

        // Quem acompanha a pessoa de quem é o compromisso (e só dela). Vale o consentimento de saúde de QUEM É ACOMPANHADO: sem ele, ninguém é avisado.
        $acompanhado = $memberId !== null ? $membros->firstWhere('id', $memberId) : null;

        if ($acompanhado?->user?->hasCurrentHealthConsent()) {
            $coluna = $kind === 'care_items' ? 'notify_care_items' : 'notify_reminders';

            $seguidores = ProfileCaregiver::query()->active()->where('profile_id', $perfil->id)
                ->where(fn ($q) => $q->where('member_id', $memberId)->orWhereNull('member_id'))
                ->where($coluna, true)->with('user')->get()->pluck('user');

            $destinatarios = $destinatarios->merge($seguidores);
        }

        return $destinatarios->filter()->unique('id')->values();
    }
}
