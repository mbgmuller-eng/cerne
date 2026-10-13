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
    public function store(Request $request, FinancialProfile $profile): RedirectResponse
    {
        $this->authorize('view', $profile);

        $request->session()->put(SetProfileContext::SESSION_KEY, $profile->id);

        // Atalho da carteira de seguros: cai direto na tela de Seguros do cliente, opcionalmente já na apólice ou com a
        // edição aberta. Só valores conhecidos passam adiante; a tela confere de novo o que o usuário enxerga.
        if ($request->input('destino') === 'seguros') {
            $apolice = (string) $request->input('apolice');

            return redirect()->route('insurance.index', array_filter([
                'apolice' => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $apolice) === 1 ? $apolice : null,
                'editar' => $request->input('acao') === 'editar' ? 1 : null,
                'expandir' => $request->boolean('expandir') ? 1 : null,
            ]));
        }

        // Corretor não tem Visão geral (é tela financeira, ver Dashboard::
        // mount()) — cai direto em Seguros, a única tela dele dentro do perfil.
        return redirect()->route($request->user()->isBroker() ? 'insurance.index' : 'dashboard');
    }
}
