<?php

namespace App\Services;

use App\Enums\ConsultantClientStatus;
use App\Mail\ConsultantLinkRequestMail;
use App\Models\ConsultantClient;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

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

    /**
     * Ponto único de "vincular um cliente à carteira" — usado tanto pelo
     * consultor (PortfolioOverview) quanto pelo corretor (PortfolioInsurance):
     * e-mail sem conta vira convite de cadastro (ClientInviteService);
     * e-mail que já tem conta vira pedido de autorização de vínculo (este
     * service). Ninguém ganha uma segunda conta só porque um profissional
     * diferente tentou convidar o mesmo endereço, e ninguém autoriza duas
     * vezes o mesmo profissional.
     *
     * @return string o link gerado (convite ou pedido de vínculo)
     */
    public function inviteOrRequest(User $professional, string $name, string $email, ClientInviteService $invites): string
    {
        $existente = User::where('email', $email)->first();

        if ($existente === null) {
            return $invites->send($professional, $name, $email);
        }

        if (! $existente->isClient()) {
            throw ValidationException::withMessages([
                'inviteEmail' => 'Esse e-mail já pertence a uma conta que não é de cliente.',
            ]);
        }

        $vinculo = ConsultantClient::query()
            ->where('consultant_id', $professional->id)
            ->where('client_id', $existente->id)
            ->first();

        if ($vinculo?->status === ConsultantClientStatus::Active) {
            throw ValidationException::withMessages([
                'inviteEmail' => 'Esse e-mail já é seu cliente.',
            ]);
        }

        if ($vinculo?->status === ConsultantClientStatus::Pending) {
            throw ValidationException::withMessages([
                'inviteEmail' => 'Já existe uma autorização pendente pra esse e-mail.',
            ]);
        }

        return $this->request($professional, $existente);
    }
}
