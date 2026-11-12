<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Quem vê dado de OUTRAS pessoas (administrador da plataforma, consultor, corretor) só usa o app depois de ligar a verificação
 * em dois passos. Senha vazada ou adivinhada de um profissional entregaria a carteira inteira de clientes; com o segundo passo,
 * a senha sozinha não abre a conta.
 *
 * Só vale com `CERNE_TWO_FACTOR_REQUIRED=true` (config cerne.security.two_factor_required): a ligação em produção é decisão do
 * dono do projeto, porque obriga cada profissional a configurar o aplicativo no próximo acesso. `cerne:check` avisa enquanto desligada.
 *
 * Fica de fora quem é um administrador "entrando como" outra conta: o segundo passo é da pessoa, não de quem a atende.
 */
class RequiresTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            $user === null
            || ! config('cerne.security.two_factor_required')
            || $request->session()->has('impersonator_id')
            || ! $user->mustUseTwoFactor()
            || $user->hasTwoFactor()
        ) {
            return $next($request);
        }

        return redirect()->route('two-factor.setup');
    }
}
