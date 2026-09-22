<?php

namespace App\Services;

use App\Enums\InviteStatus;
use App\Enums\UserRole;
use App\Mail\ProfessionalInviteMail;
use App\Models\ProfessionalInvite;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Convite de conta profissional (Consultor ou Corretor), emitido pelo
 * admin — ver ProfessionalInvite. Mesmo padrão de token/e-mail de
 * ClientInviteService, só que sem nunca criar perfil financeiro (ver
 * ProfessionalOnboardingService::acceptInvite()).
 */
class ProfessionalInviteService
{
    public function send(?User $admin, string $name, string $email, UserRole $role): string
    {
        ['invite' => $invite, 'token' => $token] = ProfessionalInvite::issue($admin, $name, $email, $role);

        $link = route('professional-invite.accept', ['token' => $token]);

        Mail::to($email)->queue(new ProfessionalInviteMail($invite, $link));

        return $link;
    }

    public function resend(ProfessionalInvite $invite): string
    {
        $invite->update(['status' => InviteStatus::Expired]);

        return $this->send($invite->invitedBy, $invite->name, $invite->email, $invite->role);
    }
}
