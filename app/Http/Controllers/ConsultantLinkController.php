<?php

namespace App\Http\Controllers;

use App\Enums\ConsultantClientStatus;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\InsurancePolicy;
use App\Support\ProfileContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Confirmação do vínculo consultor↔cliente quando o cliente convidado já
 * tem conta (ver ConsultantLinkService).
 *
 * `show` chega por um link assinado e expirável — a rota exige `signed`.
 * `accept`/`decline` são POST comuns, já dentro da página de confirmação;
 * não precisam de assinatura própria porque a policy (client_id === quem
 * está logado) é o que realmente autoriza a ação.
 *
 * Quando quem pede o vínculo é um CORRETOR, autorizar não libera tudo —
 * libera só o que o cliente marcar na hora. Apólices já cadastradas não
 * aparecem pra ele até o cliente escolher revisitá-las aqui; diferente do
 * consultor, que enxerga tudo assim que o vínculo fica ativo.
 */
class ConsultantLinkController extends Controller
{
    public function show(ConsultantClient $consultantClient): View|RedirectResponse
    {
        $this->authorize('respond', $consultantClient);

        if ($consultantClient->status !== ConsultantClientStatus::Pending) {
            return redirect()->route('dashboard')->with('status', 'Esse pedido já foi resolvido.');
        }

        $consultantClient->loadMissing('consultant');

        return view('consultant-link.show', [
            'vinculo' => $consultantClient,
            'apolices' => $consultantClient->consultant->isBroker()
                ? $this->apolicesDoCliente($consultantClient)
                : collect(),
        ]);
    }

    public function accept(Request $request, ConsultantClient $consultantClient): RedirectResponse
    {
        $this->authorize('respond', $consultantClient);

        if ($consultantClient->status === ConsultantClientStatus::Pending) {
            $consultantClient->loadMissing('consultant');

            if ($consultantClient->consultant->isBroker()) {
                $this->compartilharApolicesEscolhidas($request, $consultantClient);
            }

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

    /**
     * Apólices que o cliente que está aceitando pode ver — mesmo escopo de
     * tenancy/privacidade de sempre (o contexto é montado com o perfil e o
     * MEMBRO do próprio cliente, então algo marcado oculto do cônjuge
     * continua fora daqui). Vazio se o cliente ainda não tem perfil
     * próprio (não deveria acontecer — quem recebe pedido de vínculo já
     * tem conta — mas falha fechado em vez de estourar).
     *
     * @return Collection<int, InsurancePolicy>
     */
    private function apolicesDoCliente(ConsultantClient $consultantClient): Collection
    {
        $perfil = FinancialProfile::query()->where('owner_user_id', $consultantClient->client_id)->first();

        if ($perfil === null) {
            return collect();
        }

        app(ProfileContext::class)->set($perfil, $perfil->memberFor($consultantClient->client));

        return InsurancePolicy::query()->active()->get();
    }

    /**
     * Só marca broker_id nas apólices que o cliente escolheu agora — o que
     * ele deixar desmarcado não muda (nem apólice já compartilhada com
     * OUTRO corretor perde o vínculo sozinha; ver InsuranceIndex pra
     * revogar isso deliberadamente). A lista de ids permitidos vem
     * recalculada aqui, não do POST — evita marcar apólice de outro perfil
     * só porque alguém adulterou o formulário.
     */
    private function compartilharApolicesEscolhidas(Request $request, ConsultantClient $consultantClient): void
    {
        $apolices = $this->apolicesDoCliente($consultantClient);
        $idsEscolhidos = $apolices->pluck('id')->intersect($request->input('apolices_compartilhadas', []));

        if ($idsEscolhidos->isEmpty()) {
            return;
        }

        InsurancePolicy::query()
            ->whereIn('id', $idsEscolhidos)
            ->update(['broker_id' => $consultantClient->consultant_id]);
    }
}
