<?php

namespace App\Services;

use App\Enums\InviteStatus;
use App\Mail\ClientInviteMail;
use App\Models\ConsultantInvite;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class ClientInviteService
{
    /**
     * Emite o convite e dispara o e-mail.
     *
     * O token só existe em claro aqui e dentro do e-mail — no banco fica o
     * hash. Devolvemos o link para que o consultor possa repassá-lo por
     * outro canal se o e-mail não chegar.
     */
    public function send(User $consultant, string $name, string $email): string
    {
        ['invite' => $invite, 'token' => $token] = ConsultantInvite::issue($consultant, $name, $email);

        $link = route('invite.accept', ['token' => $token]);

        Mail::to($email)->queue(new ClientInviteMail($invite, $link));

        return $link;
    }

    /**
     * Convite sem consultor nenhum — o painel admin usa isto pra criar
     * conta de cliente independente (ver ClientOnboardingService::
     * acceptInvite(), que pula o vínculo quando consultant_id é nulo).
     * Mesmo link, mesma tela de aceite — só o e-mail muda de assunto.
     */
    public function sendStandalone(string $name, string $email): string
    {
        ['invite' => $invite, 'token' => $token] = ConsultantInvite::issue(null, $name, $email);

        $link = route('invite.accept', ['token' => $token]);

        Mail::to($email)->queue(new ClientInviteMail($invite, $link));

        return $link;
    }

    /**
     * Reenvia um convite de cliente titular já emitido — a pessoa pode ter
     * perdido o e-mail original ou o link ter expirado. Substitui o
     * convite anterior em vez de empilhar (mesmo raciocínio de
     * PartnerInviteService::send()): expira o antigo e emite um token novo,
     * senão o link velho continuaria valendo em paralelo com o novo.
     */
    public function resend(ConsultantInvite $invite): string
    {
        $invite->update(['status' => InviteStatus::Expired]);

        return $invite->consultant_id !== null
            ? $this->send($invite->consultant, $invite->client_name, $invite->client_email)
            : $this->sendStandalone($invite->client_name, $invite->client_email);
    }
}
