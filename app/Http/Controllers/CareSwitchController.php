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

        $this->authorizeOpening($request, $perfil);

        $request->session()->put(SetProfileContext::SESSION_KEY, $perfil->id);
        $request->session()->forget(ProfileSwitchController::VOLTAR_KEY);

        // Quem só acompanha as finanças não tem ficha de saúde para abrir: entra direto na leitura das finanças.
        return redirect()->route($this->soFinancas($request, $perfil) ? 'family.index' : 'health.card.index');
    }

    /** Entra na pessoa e segue para a agenda ou os cuidados dela (o link dos avisos). */
    public function goto(Request $request, string $profile, string $destino): RedirectResponse
    {
        $perfil = FinancialProfile::withCareProfiles()->whereKey($profile)->firstOrFail();

        $this->authorizeOpening($request, $perfil);

        $request->session()->put(SetProfileContext::SESSION_KEY, $perfil->id);

        return redirect()->route(match ($destino) {
            'cuidados' => 'health.care.index',
            'financas' => 'family.index',
            default => 'health.appointments.index',
        });
    }

    /**
     * Esta rota é só para entrar em quem se acompanha: a pessoa acompanhada (dono, ela mesma ou cuidador) ou o perfil comum em que o usuário é
     * CUIDADOR. O dono e os membros de um perfil comum, e o consultor, têm o caminho de sempre.
     */
    private function authorizeOpening(Request $request, FinancialProfile $perfil): void
    {
        $this->authorize('view', $perfil);

        $ehCuidador = \App\Models\ProfileCaregiver::query()->active()->where('profile_id', $perfil->id)->where('user_id', $request->user()->id)->exists();

        abort_unless($perfil->isCare() || $ehCuidador, 404);
    }

    /** O acesso do usuário a este perfil é só de finanças (sem saúde)? */
    private function soFinancas(Request $request, FinancialProfile $perfil): bool
    {
        $acessos = \App\Models\ProfileCaregiver::query()->active()->where('profile_id', $perfil->id)->where('user_id', $request->user()->id)->get();

        return ! $perfil->isCare() && $acessos->isNotEmpty() && ! $acessos->contains('can_view_health', true) && $acessos->contains('can_view_finance', true);
    }

    /** Volta para o perfil próprio (ou para a lista de pessoas, se quem cuida não tem perfil próprio). */
    public function leave(Request $request): RedirectResponse
    {
        $request->session()->forget(SetProfileContext::SESSION_KEY);

        return redirect()->route($request->user()->ownedProfiles()->exists() ? 'dashboard' : 'care.index');
    }
}
