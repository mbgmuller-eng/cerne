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

        return redirect()->route('dashboard');
    }
}
