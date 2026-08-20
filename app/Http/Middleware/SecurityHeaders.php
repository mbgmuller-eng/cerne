<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeçalhos de segurança.
 *
 * Aplicados no PHP e não no servidor web porque a hospedagem
 * compartilhada não dá acesso à configuração do Nginx — e um cabeçalho
 * que depende de `.htaccess` some no dia em que o plano mudar.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Impede que o navegador adivinhe o tipo do conteúdo: um PDF de
        // extrato interpretado como HTML seria execução de script.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // O app não deve ser embutido em iframe de terceiro — protege
        // contra clickjacking sobre botões de pagamento e exclusão.
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        // Não vaza a URL interna (que carrega ids de perfil e fatura) ao
        // navegar para fora.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Nada aqui precisa de câmera, microfone ou localização.
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=()'
        );

        if (app()->environment('production')) {
            // HSTS: o domínio só responde em HTTPS daqui em diante.
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        return $response;
    }
}
