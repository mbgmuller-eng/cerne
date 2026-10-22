<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetProfileContext;
use App\Models\FinancialProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Entrar e sair do perfil de uma pessoa acompanhada. Form POST puro, como a troca de perfil do consultor. A escolha vai para a sessão, mas
 * quem concede o acesso é a policy, reavaliada a cada requisição pelo SetProfileContext.
 */
class CareSwitchController extends Controller
{
    public function open(Request $request, string $profile): RedirectResponse
    {
        $perfil = FinancialProfile::withCareProfiles()->whereKey($profile)->firstOrFail();

        abort_unless($perfil->isCare(), 404);
        $this->authorize('view', $perfil);

        $request->session()->put(SetProfileContext::SESSION_KEY, $perfil->id);
        $request->session()->forget(ProfileSwitchController::VOLTAR_KEY);

        return redirect()->route('health.card.index');
    }

    /** Volta para o perfil próprio (ou para a lista de pessoas, se quem cuida não tem perfil próprio). */
    public function leave(Request $request): RedirectResponse
    {
        $request->session()->forget(SetProfileContext::SESSION_KEY);

        return redirect()->route($request->user()->ownedProfiles()->exists() ? 'dashboard' : 'care.index');
    }
}
