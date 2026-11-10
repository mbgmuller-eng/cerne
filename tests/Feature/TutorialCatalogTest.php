<?php

namespace Tests\Feature;

use App\Support\Tutorials\TutorialCatalog;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * O conteúdo de verdade dos tutoriais (config/tutorials.php): cada entrada aponta para uma tela que existe e segue o tom do produto.
 * Fica fora do TutorialTest: ali o catálogo é trocado por uma entrada de teste.
 */
class TutorialCatalogTest extends TestCase
{
    public function test_toda_entrada_aponta_para_uma_rota_que_existe_e_abre_sem_parametro(): void
    {
        foreach (array_keys(TutorialCatalog::all()) as $rota) {
            $this->assertTrue(Route::has($rota), "o tutorial '$rota' não tem rota com esse nome");

            $route = Route::getRoutes()->getByName($rota);
            $this->assertContains('GET', $route->methods(), "'$rota' não é uma tela (GET)");
            $this->assertEmpty($route->parameterNames(), "'$rota' precisa de parâmetro na URL: o botão \"?\" só existe em tela fixa");
        }
    }

    public function test_toda_entrada_tem_titulo_resumo_e_de_3_a_6_passos(): void
    {
        foreach (TutorialCatalog::all() as $rota => $tutorial) {
            $this->assertNotEmpty($tutorial['title'] ?? '', "$rota: sem título");
            $this->assertNotEmpty($tutorial['summary'] ?? '', "$rota: sem resumo");
            $this->assertLessThanOrEqual(110, mb_strlen($tutorial['summary']), "$rota: resumo longo demais para o cartão");

            $passos = $tutorial['steps'] ?? [];
            $this->assertGreaterThanOrEqual(3, count($passos), "$rota: poucos passos");
            $this->assertLessThanOrEqual(6, count($passos), "$rota: passos demais");

            foreach ($passos as $passo) {
                $this->assertIsString($passo);
                $this->assertNotEmpty(trim($passo), "$rota: passo vazio");
            }
        }
    }

    public function test_os_textos_seguem_o_tom_do_produto(): void
    {
        foreach (TutorialCatalog::all() as $rota => $tutorial) {
            $textos = array_merge([$tutorial['title'], $tutorial['summary'], $tutorial['tip'] ?? ''], $tutorial['steps']);

            foreach ($textos as $texto) {
                $this->assertStringNotContainsString('—', $texto, "$rota: travessão em \"$texto\"");
                $this->assertDoesNotMatchRegularExpression('/\b(pra|num|numa|nuns|numas)\b/iu', $texto, "$rota: coloquial em \"$texto\"");
                $this->assertDoesNotMatchRegularExpression('/\s{2,}/', $texto, "$rota: espaço duplo em \"$texto\"");
            }
        }
    }
}
