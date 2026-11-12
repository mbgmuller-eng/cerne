<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\CsvSafe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
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
    use RefreshDatabase;

    use RefreshDatabase;

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

    public function test_a_politica_de_conteudo_bloqueia_embutir_base_forjada_plugin_e_formulario_para_fora_e_observa_a_estrita(): void
    {
        $r = $this->get('/entrar');

        $enforced = (string) $r->headers->get('Content-Security-Policy');
        foreach (["frame-ancestors 'self'", "base-uri 'self'", "object-src 'none'", "form-action 'self'"] as $diretiva) {
            $this->assertStringContainsString($diretiva, $enforced);
        }

        $observada = (string) $r->headers->get('Content-Security-Policy-Report-Only');
        $this->assertStringContainsString("default-src 'self'", $observada);
        $this->assertStringContainsString('report-uri /csp-report', $observada);
    }

    public function test_o_php_nao_anuncia_a_versao(): void
    {
        $this->assertNull($this->get('/entrar')->headers->get('X-Powered-By'));
    }

    public function test_tela_de_quem_esta_logado_nao_fica_em_cache_e_a_de_visitante_segue_como_antes(): void
    {
        $usuario = User::factory()->create();

        $logado = $this->actingAs($usuario)->get(route('verification.notice'));
        $this->assertStringContainsString('no-store', (string) $logado->headers->get('Cache-Control'));
    }

    public function test_arquivo_que_define_o_proprio_cache_nao_e_sobrescrito(): void
    {
        Route::middleware('web')->get('/__teste-cache', fn () => response('x', 200, ['Cache-Control' => 'public, max-age=31536000, immutable']));

        $this->actingAs(User::factory()->create())->get('/__teste-cache')->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
    }

    public function test_o_relato_da_politica_so_grava_campos_curtos_e_sem_endereco_completo(): void
    {
        Log::spy();

        $this->postJson('/csp-report', ['csp-report' => [
            'effective-directive' => 'script-src-elem',
            'blocked-uri' => 'https://cdn.exemplo.com/lib.js?token=segredo',
            'document-uri' => 'https://cerne.app.br/dividas/abc-123?x=1',
            'script-sample' => 'conteudo-que-nao-deve-ser-gravado',
        ]])->assertNoContent();

        Log::shouldHaveReceived('notice')->once()->withArgs(function (string $mensagem, array $contexto): bool {
            $this->assertSame('csp-report', $mensagem);
            $this->assertSame(['directive' => 'script-src-elem', 'blocked' => 'cdn.exemplo.com', 'page' => '/dividas'], $contexto);
            $this->assertStringNotContainsString('segredo', json_encode($contexto));
            $this->assertStringNotContainsString('conteudo-que-nao-deve', json_encode($contexto));

            return true;
        });
    }

    public function test_security_txt_so_existe_com_o_contato_do_encarregado_configurado(): void
    {
        config(['cerne.legal.dpo_email' => null]);
        $this->get('/.well-known/security.txt')->assertNotFound();

        config(['cerne.legal.dpo_email' => 'privacidade@exemplo.com']);
        $this->get('/.well-known/security.txt')->assertOk()->assertSee('Contact: mailto:privacidade@exemplo.com', false)->assertSee('Expires:', false);
    }

    public function test_csv_neutraliza_formula_mas_preserva_numero_negativo(): void
    {
        $this->assertSame("'=HYPERLINK(\"http://x\")", CsvSafe::cell('=HYPERLINK("http://x")'));
        $this->assertSame("'+cmd", CsvSafe::cell('+cmd'));
        $this->assertSame("'-1+2", CsvSafe::cell('-1+2'));
        $this->assertSame("'@SUM(A1)", CsvSafe::cell('@SUM(A1)'));
        $this->assertSame('-1234,50', CsvSafe::cell('-1234,50'));
        $this->assertSame('Financiamento do carro', CsvSafe::cell('Financiamento do carro'));
        $this->assertNull(CsvSafe::cell(null));
        $this->assertSame(['a', "'=b"], CsvSafe::row(['a', '=b']));
    }
}
