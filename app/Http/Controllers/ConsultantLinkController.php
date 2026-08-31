<?php

namespace App\Http\Controllers;

use App\Enums\ConsultantClientStatus;
use App\Models\ConsultantClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Confirmação do vínculo consultor↔cliente quando o cliente convidado já
 * tem conta (ver ConsultantLinkService).
 *
 * `show` chega por um link assinado e expirável — a rota exige `signed`.
 * `accept`/`decline` são POST comuns, já dentro da página de confirmação;
 * não precisam de assinatura própria porque a policy (client_id === quem
 * está logado) é o que realmente autoriza a ação.
 */
class ConsultantLinkController extends Controller
{
    public function show(ConsultantClient $consultantClient): View|RedirectResponse
    {
        $this->authorize('respond', $consultantClient);

        if ($consultantClient->status !== ConsultantClientStatus::Pending) {
            return redirect()->route('dashboard')->with('status', 'Esse pedido já foi resolvido.');
        }

        return view('consultant-link.show', [
            'vinculo' => $consultantClient->load('consultant'),
        ]);
    }

    public function accept(ConsultantClient $consultantClient): RedirectResponse
    {
        $this->authorize('respond', $consultantClient);

        if ($consultantClient->status === ConsultantClientStatus::Pending) {
            $consultantClient->update([
                'status' => ConsultantClientStatus::Active,
                'accepted_at' => now(),
            ]);
        }

        return redirect()->route('dashboard')->with('status', 'Vínculo autorizado.');
    }

    public function decline(ConsultantClient $consultantClient): RedirectResponse
    {
        $this->authorize('respond', $consultantClient);

        if ($consultantClient->status === ConsultantClientStatus::Pending) {
            $consultantClient->delete();
        }

        return redirect()->route('dashboard')->with('status', 'Pedido recusado.');
    }
}
