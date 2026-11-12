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
    /**
     * Parte da política de conteúdo que JÁ vale (bloqueia): quem pode embutir o app, `<base>` forjado, plugins e para onde um formulário
     * pode enviar dado. Nada disso depende de como as telas montam scripts, então não quebra o que já funciona.
     */
    private const CSP_ENFORCED = "frame-ancestors 'self'; base-uri 'self'; object-src 'none'; form-action 'self'";

    /**
     * Política estrita (tudo só do próprio domínio), em modo de OBSERVAÇÃO: o navegador não bloqueia nada, só relata o que bloquearia
     * (ver CspReportController). O Alpine precisa de 'unsafe-eval' e o Livewire injeta script inline, então a parte de script ainda é
     * larga; o ganho está em barrar origem externa de script, imagem, fonte e conexão. Promover a `Content-Security-Policy` só
     * depois de uns dias sem relato de tela legítima.
     */
    private const CSP_OBSERVED = "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; media-src 'self' blob: data:; worker-src 'self'; manifest-src 'self'; frame-src 'none'";

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

        // Microfone liberado só pro próprio domínio: é o que a IA usa pra
        // transcrever "Falar despesa" no Fluxo de caixa (Web Speech API,
        // ver resources/js/app.js) — bloqueado feito 'microphone=()' faz o
        // navegador negar o acesso DIRETO, sem nem chegar a perguntar
        // permissão pra pessoa (foi isso que quebrou a função em produção:
        // parecia estar "ouvindo" mas nunca teve acesso de verdade). Nada
        // aqui usa câmera, localização, pagamento nativo ou USB.
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(self), geolocation=(), payment=(), usb=()'
        );

        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        // Política de conteúdo (ver as constantes). O relato da observação só existe onde há endereço configurado.
        $response->headers->set('Content-Security-Policy', self::CSP_ENFORCED);
        $response->headers->set('Content-Security-Policy-Report-Only', self::CSP_OBSERVED.'; report-uri '.route('csp.report', absolute: false));

        // Tela de quem está logado nunca fica em cache do navegador nem de proxy: o botão "voltar" depois de sair não pode
        // reabrir finanças e saúde na tela, e um computador compartilhado não guarda a página. Só mexe no que ainda está no
        // padrão do Laravel (`no-cache, private`): arquivo que já definiu o próprio cache (foto de exercício) fica como está.
        if ($request->user() !== null && $this->usaCachePadrao($response)) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        // O PHP anuncia a versão em `X-Powered-By`: informação de graça para quem procura versão com falha conhecida.
        header_remove('X-Powered-By');
        $response->headers->remove('X-Powered-By');

        if (app()->environment('production')) {
            // HSTS: o domínio só responde em HTTPS daqui em diante.
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        return $response;
    }

    private function usaCachePadrao(Response $response): bool
    {
        $cache = $response->headers->get('Cache-Control', '');

        return $cache === '' || in_array(str_replace(' ', '', $cache), ['no-cache,private', 'private,no-cache'], true);
    }
}
