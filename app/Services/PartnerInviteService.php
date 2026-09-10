<?php

namespace App\Services;

use App\Enums\InviteStatus;
use App\Enums\MemberRole;
use App\Mail\PartnerInviteMail;
use App\Models\FinancialProfile;
use App\Models\PartnerInvite;
use App\Models\ProfileMember;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Convite de cônjuge — titular ou consultor vinculado (ver
 * FinancialProfilePolicy::manageMembers) convida a segunda pessoa do
 * casal pra criar acesso próprio.
 */
class PartnerInviteService
{
    /**
     * @return string o link de convite, devolvido pro chamador poder
     *                 repassar por outro canal se o e-mail não chegar —
     *                 mesmo fallback do convite de cliente.
     */
    public function send(FinancialProfile $profile, User $issuer, string $name, string $email): string
    {
        if ($this->alreadyHasPartnerComLogin($profile)) {
            throw new RuntimeException('Este perfil já tem um cônjuge cadastrado.');
        }

        // Reenviar substitui o convite anterior em vez de empilhar —
        // a pessoa pode ter perdido o e-mail/link original.
        PartnerInvite::query()
            ->where('profile_id', $profile->id)
            ->where('status', InviteStatus::Pending)
            ->update(['status' => InviteStatus::Expired]);

        ['invite' => $invite, 'token' => $token] = PartnerInvite::issue($profile, $issuer, $name, $email);

        $link = route('partner-invite.accept', ['token' => $token]);

        Mail::to($email)->queue(new PartnerInviteMail($issuer, $name, $link));

        return $link;
    }

    /**
     * Só bloqueia quando o cônjuge JÁ TEM login — um cônjuge cadastrado
     * sem login (addPartnerWithoutLogin(), ou vindo de importação) pode
     * receber convite depois: é exatamente o caminho pra dar acesso a
     * alguém que a gente cadastrou sem login no começo. Ver
     * ClientOnboardingService::addPartner(), que reaproveita esse
     * ProfileMember em vez de criar outro quando o convite é aceito.
     */
    private function alreadyHasPartnerComLogin(FinancialProfile $profile): bool
    {
        return ProfileMember::query()
            ->where('profile_id', $profile->id)
            ->where('role', MemberRole::Secondary)
            ->whereNotNull('user_id')
            ->exists();
    }
}
