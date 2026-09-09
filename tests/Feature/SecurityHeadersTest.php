<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Regressão: 'microphone=()' no Permissions-Policy bloqueia o navegador
 * de sequer PERGUNTAR permissão de microfone pra pessoa — foi exatamente
 * o que quebrou "Falar despesa" (Fluxo de caixa) em produção: o botão
 * aparecia "ouvindo" mas nunca teve acesso de verdade, sem nenhum prompt
 * aparecer. Câmera/geolocalização/pagamento/USB continuam bloqueados —
 * nada no app usa isso.
 */
class SecurityHeadersTest extends TestCase
{
    public function test_permissions_policy_libera_microfone_pro_proprio_dominio_e_bloqueia_o_resto(): void
    {
        $response = $this->get('/entrar');

        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(self), geolocation=(), payment=(), usb=()');
    }

    public function test_cabecalhos_de_seguranca_basicos_continuam_presentes(): void
    {
        $response = $this->get('/entrar');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }
}
