<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Tela de aceite dos Termos de Uso e da Política de Privacidade (versão atual) e do consentimento específico de saúde. */
class LegalAcceptanceController extends Controller
{
    public function showTerms(Request $request): View|RedirectResponse
    {
        if ($request->user()->hasAcceptedCurrentTerms()) {
            return redirect()->intended(route('dashboard'));
        }

        return view('legal.accept');
    }

    public function storeTerms(Request $request): RedirectResponse
    {
        $request->validate(['accept' => ['accepted']], ['accept.accepted' => 'Para continuar, é preciso aceitar os Termos de Uso e a Política de Privacidade.']);

        $request->user()->acceptTerms();

        return redirect()->intended(route('dashboard'));
    }

    public function showHealth(Request $request): View|RedirectResponse
    {
        if ($request->user()->hasCurrentHealthConsent()) {
            return redirect()->intended(route('health.gym.index'));
        }

        return view('legal.health-consent');
    }

    public function storeHealth(Request $request): RedirectResponse
    {
        $request->validate(['consent' => ['accepted']], ['consent.accepted' => 'Para usar a área de Saúde, é preciso dar o consentimento.']);

        $request->user()->grantHealthConsent();

        return redirect()->intended(route('health.gym.index'));
    }

    /** Revoga o consentimento de saúde (a pessoa pode voltar a dar quando quiser). */
    public function revokeHealth(Request $request): RedirectResponse
    {
        $request->user()->revokeHealthConsent();

        return redirect()->route('my-account')->with('status', 'Consentimento de saúde revogado. A área de Saúde fica fechada e os avisos de saúde param. Seus dados continuam guardados até você apagar a conta.');
    }
}
