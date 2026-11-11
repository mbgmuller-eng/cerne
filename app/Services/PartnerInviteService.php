<?php

namespace App\Services;

use App\Enums\InviteStatus;
use App\Enums\MemberRole;
use App\Mail\PartnerInviteMail;
use App\Models\FinancialProfile;
use App\Models\PartnerInvite;
use App\Models\ProfileMember;
use App\Models\User;
use App\Notifications\ConsultantAccessRequested;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class PartnerInviteService
{
    /**
     * Emite o convite do cônjuge.
     *
     * - Quem convida é o DONO do perfil: o e-mail sai na hora e o link volta para ele (pode repassar se o e-mail não chegar).
     * - Quem convida é o CONSULTOR: nada sai e nenhum link existe para ele. O convite fica guardado, o dono é avisado e só depois que ele aprova
     *   (`approve`) o e-mail vai ao convidado. Sem essa trava o consultor convidava um e-mail que ele controla e virava "cônjuge" com login.
     *
     * @return string|null o link (só para o dono) ou nulo quando aguarda a aprovação do dono
     */
    public function send(FinancialProfile $profile, User $issuer, string $name, string $email): ?string
    {
        if ($this->alreadyHasPartnerComLogin($profile)) {
            throw new RuntimeException('Este perfil já tem um cônjuge cadastrado.');
        }

        $doDono = $issuer->id === $profile->owner_user_id;

        if (! $doDono && $profile->owner->password_pending) {
            throw new RuntimeException('O cliente ainda não ativou o acesso à conta. Envie o convite de acesso primeiro: é ele quem aprova a entrada do cônjuge.');
        }

        // Reenviar substitui o convite anterior em vez de empilhar —
        // a pessoa pode ter perdido o e-mail/link original.
        PartnerInvite::query()
            ->where('profile_id', $profile->id)
            ->where('status', InviteStatus::Pending)
            ->update(['status' => InviteStatus::Expired]);

        ['invite' => $invite, 'token' => $token] = PartnerInvite::issue($profile, $issuer, $name, $email, $doDono);

        if (! $doDono) {
            $profile->owner->notify(new ConsultantAccessRequested($issuer->name, $name, 'o acesso de cônjuge ao seu perfil, com login próprio'));

            return null;
        }

        $link = route('partner-invite.accept', ['token' => $token]);

        Mail::to($email)->queue(new PartnerInviteMail($issuer, $name, $link));

        return $link;
    }

    /** O dono aprova o convite que o consultor montou: troca o token (o antigo nunca saiu) e só agora o e-mail vai ao convidado. */
    public function approve(PartnerInvite $invite, User $owner): string
    {
        $this->assertOwner($invite, $owner);

        if ($invite->status !== InviteStatus::Pending || $invite->isExpired() || $invite->owner_approved_at !== null) {
            throw new RuntimeException('Este pedido já foi tratado ou expirou. Peça um novo ao consultor.');
        }

        if ($this->alreadyHasPartnerComLogin($invite->profile)) {
            throw new RuntimeException('Este perfil já tem um cônjuge cadastrado.');
        }

        $token = \Illuminate\Support\Str::random(48);

        $invite->forceFill([
            'token' => hash('sha256', $token),
            'owner_approved_at' => now(),
            'expires_at' => now()->addDays((int) config('cerne.invite.expires_in_days')),
        ])->save();

        $link = route('partner-invite.accept', ['token' => $token]);

        Mail::to($invite->partner_email)->queue(new PartnerInviteMail($invite->invitedBy, $invite->partner_name, $link));

        return $link;
    }

    public function reject(PartnerInvite $invite, User $owner): void
    {
        $this->assertOwner($invite, $owner);

        $invite->update(['status' => InviteStatus::Expired]);
    }

    private function assertOwner(PartnerInvite $invite, User $owner): void
    {
        if ($invite->profile->owner_user_id !== $owner->id) {
            throw new RuntimeException('Só o dono do perfil aprova este pedido.');
        }
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
