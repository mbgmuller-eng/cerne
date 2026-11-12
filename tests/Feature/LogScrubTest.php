<?php

namespace Tests\Feature;

use App\Logging\ScrubLogs;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * O arquivo de log não guarda dado pessoal (revisão de segurança 2026-11): e-mail, CPF e valores de consulta saem do texto, e a exceção
 * vira "mensagem limpa + linhas do app", sem argumento de função.
 */
class LogScrubTest extends TestCase
{
    public function test_texto_perde_email_cpf_numero_longo_e_token(): void
    {
        $limpo = ScrubLogs::text('Falha para fulana@exemplo.com cpf 123.456.789-09 conta 123456789012 chave '.str_repeat('a1', 25));

        $this->assertStringNotContainsString('fulana', $limpo);
        $this->assertStringNotContainsString('123.456.789-09', $limpo);
        $this->assertStringNotContainsString('123456789012', $limpo);
        $this->assertStringNotContainsString(str_repeat('a1', 25), $limpo);
        $this->assertStringContainsString('[e-mail]', $limpo);
    }

    public function test_consulta_de_banco_perde_os_valores_e_mantem_o_esqueleto(): void
    {
        $limpo = ScrubLogs::text("SQLSTATE[23000]: Integrity (Connection: mysql, SQL: insert into users (name, email, age) values ('Maria da Silva', 'maria@exemplo.com', 41) where id = 77)");

        $this->assertStringNotContainsString('Maria da Silva', $limpo);
        $this->assertStringNotContainsString('maria@', $limpo);
        $this->assertStringNotContainsString('= 77', $limpo);
        $this->assertStringContainsString('insert into users (name, email, age) values', $limpo);
    }

    public function test_excecao_no_contexto_vira_texto_limpo_com_so_as_linhas_do_app(): void
    {
        $contexto = ScrubLogs::context(['exception' => new RuntimeException('erro com pessoa@exemplo.com')]);

        $this->assertIsString($contexto['exception']);
        $this->assertStringContainsString('RuntimeException: erro com [e-mail]', $contexto['exception']);
        $this->assertStringNotContainsString('pessoa@exemplo.com', $contexto['exception']);
        $this->assertStringContainsString('tests/Feature/LogScrubTest.php', $contexto['exception']);
    }

    public function test_o_filtro_esta_ligado_nos_canais_de_arquivo(): void
    {
        foreach (['single', 'daily'] as $canal) {
            $this->assertContains(ScrubLogs::class, config("logging.channels.{$canal}.tap"));
        }
    }

    public function test_de_ponta_a_ponta_o_arquivo_nao_recebe_o_email(): void
    {
        $arquivo = storage_path('logs/teste-scrub-'.uniqid().'.log');
        config(['logging.channels.scrubtest' => [
            'driver' => 'single', 'path' => $arquivo, 'level' => 'debug', 'tap' => [ScrubLogs::class],
        ]]);

        Log::channel('scrubtest')->error('Falha com outra@exemplo.com', ['exception' => new RuntimeException('detalhe de cliente@exemplo.com')]);

        $conteudo = (string) file_get_contents($arquivo);
        @unlink($arquivo);

        $this->assertStringContainsString('Falha com [e-mail]', $conteudo);
        $this->assertStringNotContainsString('@exemplo.com', $conteudo);
    }
}
