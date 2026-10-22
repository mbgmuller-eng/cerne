<?php

namespace App\Http\Middleware;

use App\Support\ProfileContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dado de saúde é dado pessoal SENSÍVEL (LGPD, art. 11): precisa de consentimento específico, destacado e revogável, além do aceite geral dos
 * Termos. Sem ele, as telas de Saúde levam a uma tela que explica o que é tratado e pede o consentimento. Revogar fecha a porta de novo.
 * O administrador "entrando como" outra conta passa direto: o consentimento é da pessoa.
 */
class RequiresHealthConsent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Na pessoa acompanhada o consentimento que vale é o DELA (declarado por quem a cadastrou), não o de quem está olhando.
        $perfil = app(ProfileContext::class)->profile();

        if ($perfil !== null && $perfil->isCare()) {
            if ($perfil->care_consent_at !== null) {
                return $next($request);
            }

            return redirect()->route('care.index')->with('status', 'Esta pessoa ainda não tem a declaração de consentimento. Quem a cadastrou precisa confirmá-la.');
        }

        if ($user === null || $request->session()->has('impersonator_id') || $user->hasCurrentHealthConsent()) {
            return $next($request);
        }

        if ($request->isMethod('GET')) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return redirect()->route('health.consent');
    }
}
