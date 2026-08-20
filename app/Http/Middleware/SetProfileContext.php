<?php

namespace App\Http\Middleware;

use App\Models\FinancialProfile;
use App\Support\ProfileContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve o perfil ativo da requisição e o entrega ao ProfileContext,
 * que por sua vez alimenta o escopo global de tenancy.
 *
 * O perfil escolhido fica na sessão (o consultor troca de cliente pela
 * tela 1). Toda troca é reautorizada aqui a cada requisição — guardar na
 * sessão é conveniência, não credencial.
 */
class SetProfileContext
{
    public const SESSION_KEY = 'cerne.active_profile_id';

    public function handle(Request $request, Closure $next): Response
    {
        // Começa sempre do zero. O contexto é um singleton, e herdar o
        // perfil de uma resolução anterior faria uma requisição sem perfil
        // enxergar os dados da anterior — em testes e em workers de fila,
        // onde o container sobrevive entre requisições.
        $context = app(ProfileContext::class);
        $context->clear();

        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $profile = $this->resolveProfile($request, $user);

        if ($profile === null) {
            return $next($request);
        }

        // Reautoriza a cada requisição: um vínculo revogado precisa cortar
        // o acesso na hora, sem depender de a sessão expirar.
        if (! $user->can('view', $profile)) {
            $request->session()->forget(self::SESSION_KEY);

            return $next($request);
        }

        $context->set(
            profile: $profile,
            member: $profile->memberFor($user),
            asConsultant: $user->isConsultant() && $profile->owner_user_id !== $user->id,
        );

        $request->session()->put(self::SESSION_KEY, $profile->id);

        return $next($request);
    }

    private function resolveProfile(Request $request, $user): ?FinancialProfile
    {
        $sessionId = $request->session()->get(self::SESSION_KEY);

        if ($sessionId !== null) {
            $profile = FinancialProfile::find($sessionId);

            if ($profile !== null) {
                return $profile;
            }

            $request->session()->forget(self::SESSION_KEY);
        }

        // Sem escolha na sessão: cai no perfil próprio do usuário.
        return $user->ownedProfiles()->first()
            ?? $user->memberships()->where('is_active', true)->first()?->profile;
    }
}
