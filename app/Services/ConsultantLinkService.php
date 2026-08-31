<?php

namespace App\Services;

use App\Enums\ConsultantClientStatus;
use App\Mail\ConsultantLinkRequestMail;
use App\Models\ConsultantClient;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Pedido de vínculo quando o e-mail convidado já pertence a uma conta.
 *
 * Diferente do convite (ClientInviteService), aqui não se cria usuário
 * nenhum — só um `consultant_clients` pendente, que só vira Active quando
 * a PRÓPRIA pessoa, logada, confirma (ver ConsultantLinkController). O
 * link é assinado (URL::temporarySignedRoute) em vez de token com hash
 * próprio: mesma garantia de "não foi forjado", sem reinventar o que o
 * framework já resolve.
 */
class ConsultantLinkService
{
    /**
     * @return string o link de confirmação, devolvido pro consultor poder
     *                 repassar por outro canal se o e-mail não chegar —
     *                 mesmo fallback do convite.
     */
    public function request(User $consultant, User $client): string
    {
        // updateOrCreate porque a restrição única é (consultant_id,
        // client_id): um vínculo já revogado (Inactive) precisa reabrir a
        // MESMA linha, não criar outra.
        $vinculo = ConsultantClient::query()->updateOrCreate(
            ['consultant_id' => $consultant->id, 'client_id' => $client->id],
            ['status' => ConsultantClientStatus::Pending, 'invited_at' => now(), 'accepted_at' => null],
        );

        $link = URL::temporarySignedRoute(
            'link.show',
            now()->addDays(config('cerne.invite.expires_in_days')),
            ['consultantClient' => $vinculo->id],
        );

        Mail::to($client->email)->queue(new ConsultantLinkRequestMail($consultant, $link));

        return $link;
    }
}
