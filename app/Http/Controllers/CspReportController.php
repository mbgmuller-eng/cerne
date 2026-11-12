<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Recebe o relato do navegador sobre o que a política de conteúdo em observação (SecurityHeaders::CSP_OBSERVED) bloquearia.
 *
 * Pública de propósito (o navegador manda sem login nem token CSRF) e por isso estreita: teto por IP, corpo pequeno, e só
 * três campos curtos vão ao log, sem endereço completo (o caminho pode carregar id de perfil) e sem trecho de código.
 */
class CspReportController extends Controller
{
    public function store(Request $request): Response
    {
        $corpo = json_decode(mb_substr((string) $request->getContent(), 0, 4000), true);
        $relato = is_array($corpo) ? ($corpo['csp-report'] ?? $corpo) : [];

        if (is_array($relato)) {
            // Nível `error` de propósito: a produção roda com LOG_LEVEL=error, e um relato em nível menor seria descartado, deixando a
            // fase de observação da política sem nenhum dado. Só existe enquanto a política estrita está em Report-Only.
            Log::error('csp-report', [
                'directive' => mb_substr((string) ($relato['effective-directive'] ?? $relato['violated-directive'] ?? ''), 0, 60),
                'blocked' => $this->host((string) ($relato['blocked-uri'] ?? '')),
                'page' => $this->rota((string) ($relato['document-uri'] ?? '')),
            ]);
        }

        return response()->noContent();
    }

    private function host(string $uri): string
    {
        if ($uri === '' || in_array($uri, ['inline', 'eval', 'data', 'blob', 'self'], true)) {
            return $uri;
        }

        return mb_substr((string) (parse_url($uri, PHP_URL_HOST) ?: $uri), 0, 80);
    }

    private function rota(string $uri): string
    {
        // Só o primeiro trecho do caminho ("/painel"): nada de id nem query.
        $caminho = (string) parse_url($uri, PHP_URL_PATH);

        return '/'.mb_substr(explode('/', ltrim($caminho, '/'))[0] ?? '', 0, 40);
    }
}
