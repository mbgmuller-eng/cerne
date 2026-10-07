<?php

namespace App\Http\Middleware;

use App\Services\AccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trava o app inteiro para quem não tem assinatura em vigor: acabou o teste
 * grátis (ou o pagamento não entrou) e a pessoa cai direto na tela de
 * assinatura, sem dias extras de espera.
 *
 * Ficam FORA deste grupo (ver routes/web.php) só o que é preciso para pagar e
 * sair: a tela de assinatura, Minha conta, tema, notificações push e o logout.
 *
 * Ligado por BILLING_ENFORCE_ACCESS (config/billing.php): desligado por
 * padrão, para a liberação não travar contas que já existem sem revisão.
 */
class RequiresActiveSubscription
{
    public function __construct(private readonly AccessService $acesso) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('billing.enforce_access')) {
            return $next($request);
        }

        $usuario = $request->user();

        if ($usuario === null || $this->acesso->hasAccess($usuario)) {
            return $next($request);
        }

        return redirect()
            ->route('subscription.index')
            ->with('status', 'Seu acesso gratuito terminou. Escolha como pagar para continuar usando o Cerne.');
    }
}
