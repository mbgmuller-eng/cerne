<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetProfileContext;
use App\Models\FinancialProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Troca do perfil ativo — form POST puro, sem Livewire.
 *
 * Mesma razão do login: navegação essencial não deve depender de
 * JavaScript. Se o JS falhar ou demorar, o consultor ainda consegue
 * abrir o perfil do cliente.
 *
 * A escolha vai para a sessão, mas quem concede o acesso é a policy,
 * reavaliada a cada requisição pelo SetProfileContext — a sessão é
 * conveniência, não credencial.
 */
class ProfileSwitchController extends Controller
{
    /** Sessão: a carteira de seguros de onde o profissional entrou no perfil de um cliente (ver InsuranceIndex). */
    public const VOLTAR_KEY = 'carteira_voltar';

    public function store(Request $request, FinancialProfile $profile): RedirectResponse
    {
        $this->authorize('view', $profile);

        $request->session()->put(SetProfileContext::SESSION_KEY, $profile->id);

        // Abrir o perfil de forma comum apaga o "voltar" de um atalho anterior.
        $request->session()->forget(self::VOLTAR_KEY);

        // Atalho da carteira de seguros: cai direto na tela de Seguros do cliente, opcionalmente já na apólice ou com a
        // edição aberta. Só valores conhecidos passam adiante; a tela confere de novo o que o usuário enxerga.
        if ($request->input('destino') === 'seguros') {
            $apolice = (string) $request->input('apolice');

            // De onde o atalho saiu, para a tela de Seguros do cliente oferecer o "Voltar". Só caminho da própria carteira de
            // seguros (com os filtros e, se houver, a âncora do cartão): nunca um endereço de fora.
            $voltar = (string) $request->input('voltar');

            if (preg_match('#^/carteira/seguros(\?[A-Za-z0-9_=&%.+\-]*)?(\#[A-Za-z0-9_\-]*)?$#', $voltar) === 1) {
                $request->session()->put(self::VOLTAR_KEY, ['url' => $voltar, 'profile_id' => $profile->id]);
            }

            return redirect()->route('insurance.index', array_filter([
                'apolice' => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $apolice) === 1 ? $apolice : null,
                'editar' => $request->input('acao') === 'editar' ? 1 : null,
                'expandir' => $request->boolean('expandir') ? 1 : null,
            ]));
        }

        // Atalho da "Minha semana": entra no perfil do cliente já no Acompanhamento. Quem não tem a área (sem vínculo de
        // consultoria) cai na Visão geral, porque a tela confere o acesso de novo.
        if ($request->input('destino') === 'acompanhamento' && $request->user()->isConsultant()) {
            return redirect()->route('engagement.index');
        }

        // Atalho da carteira de dívidas: cai direto na dívida (ou na lista de dívidas do cliente). Só consultor tem a área;
        // o id da dívida é conferido pela própria tela, que só resolve dívida do perfil ativo (404 se for de outro).
        if ($request->input('destino') === 'dividas' && $request->user()->isConsultant()) {
            $divida = (string) $request->input('divida');

            return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $divida) === 1
                ? redirect()->route('debts.show', $divida)
                : redirect()->route('debts.index');
        }

        // Corretor não tem Visão geral (é tela financeira, ver Dashboard::
        // mount()) — cai direto em Seguros, a única tela dele dentro do perfil.
        return redirect()->route($request->user()->isBroker() ? 'insurance.index' : 'dashboard');
    }
}
