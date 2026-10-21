<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminErrors;
use App\Mail\ErrorDigestMail;
use App\Models\ErrorLog;
use App\Models\User;
use App\Services\ErrorTracking\ErrorDigest;
use App\Services\ErrorTracking\ErrorRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Acompanhamento de erros: o registro agrupado (e sem dado de cliente), a reserva quando o banco cai, o relatório diário por e-mail e a tela
 * do administrador.
 */
class ErrorTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Nos outros testes o acompanhamento fica desligado (phpunit.xml) para erros de propósito não sujarem o banco.
        config(['cerne.error_tracking.enabled' => true, 'cerne.error_tracking.report_to' => []]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-22 09:00:00'));
        @unlink(app(ErrorRecorder::class)->fallbackPath());
    }

    protected function tearDown(): void
    {
        @unlink(app(ErrorRecorder::class)->fallbackPath());
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function recorder(): ErrorRecorder
    {
        return app(ErrorRecorder::class);
    }

    /** Lança em uma linha FIXA (a impressão digital é classe + arquivo + linha) e devolve a exceção. */
    private function falha(string $mensagem = 'deu ruim'): RuntimeException
    {
        return new RuntimeException($mensagem); // a linha desta instrução é a "mesma" em toda chamada
    }

    /** Outro erro, também numa linha fixa. */
    private function outra(string $mensagem): RuntimeException
    {
        return new RuntimeException($mensagem); // linha fixa do segundo tipo de erro
    }

    private function admin(string $email = 'admin@teste.com'): User
    {
        return User::factory()->create(['email' => $email, 'is_platform_admin' => true]);
    }

    // --- Registro ------------------------------------------------------------------------------------------------------------

    public function test_o_mesmo_erro_vira_uma_linha_so_com_contador_dia_e_usuarios(): void
    {
        $usuario = User::factory()->create();
        $this->actingAs($usuario);

        $this->recorder()->record($this->falha());
        $this->recorder()->record($this->falha('outra mensagem, mesmo lugar'));

        self::assertSame(1, ErrorLog::query()->count());
        $erro = ErrorLog::query()->firstOrFail();
        self::assertSame(2, $erro->occurrences);
        self::assertSame('new', $erro->status);
        self::assertSame(RuntimeException::class, $erro->exception_class);
        self::assertStringContainsString('outra mensagem', $erro->message, 'a mensagem mais recente fica');
        self::assertStringContainsString('tests/Feature/ErrorTrackingTest.php', $erro->file);
        self::assertSame(2, (int) DB::table('error_log_days')->where('error_log_id', $erro->id)->where('day', '2026-10-22')->value('count'));
        self::assertSame([$usuario->id], DB::table('error_log_users')->where('error_log_id', $erro->id)->pluck('user_id')->all());
    }

    public function test_erro_em_outro_lugar_e_outro_registro(): void
    {
        $this->recorder()->record($this->falha());
        $this->recorder()->record(new RuntimeException('em outra linha'));

        self::assertSame(2, ErrorLog::query()->count());
    }

    public function test_o_que_nao_e_falha_nossa_nao_e_anotado(): void
    {
        $r = $this->recorder();

        $r->record(ValidationException::withMessages(['x' => 'obrigatório']));
        $r->record(new AuthenticationException);
        $r->record(new NotFoundHttpException);
        $r->record(new HttpException(403));
        $r->record(new HttpException(429));

        self::assertSame(0, ErrorLog::query()->count());

        $r->record(new HttpException(503, 'fora do ar'));
        self::assertSame(1, ErrorLog::query()->count(), 'resposta 5xx é falha nossa');
    }

    public function test_ligado_pelo_laravel_um_erro_de_tela_entra_com_a_rota_em_padrao_e_nunca_o_endereco(): void
    {
        Route::middleware('web')->get('/_teste-erro/{token}', function (string $token): never {
            throw new RuntimeException('quebrou na tela');
        })->name('teste.erro');
        Route::middleware('web')->get('/_teste-erro-sem-nome/{token}', function (string $token): never {
            throw new RuntimeException('quebrou sem nome');
        });

        $usuario = User::factory()->create();

        $this->actingAs($usuario)->get('/_teste-erro/segredo-do-convite-123?email=pessoa@teste.com')->assertStatus(500);
        $this->actingAs($usuario)->get('/_teste-erro-sem-nome/segredo-do-convite-456')->assertStatus(500);

        $comNome = ErrorLog::query()->where('message', 'quebrou na tela')->firstOrFail();
        self::assertSame('teste.erro', $comNome->origin);
        self::assertSame('web', $comNome->context);
        self::assertSame('GET', $comNome->http_method);
        self::assertSame(500, $comNome->status_code);

        $semNome = ErrorLog::query()->where('message', 'quebrou sem nome')->firstOrFail();
        self::assertSame('/_teste-erro-sem-nome/{token}', $semNome->origin);

        $tudo = ErrorLog::query()->get()->map(fn ($e) => $e->origin.$e->message.$e->trace)->implode(' ');
        self::assertStringNotContainsString('segredo-do-convite', $tudo);
        self::assertStringNotContainsString('pessoa@teste.com', $tudo);
    }

    public function test_erro_de_comando_marca_o_comando_como_origem(): void
    {
        $_SERVER['argv'] = ['artisan', 'schedule:run'];

        $this->recorder()->record($this->falha());

        $erro = ErrorLog::query()->firstOrFail();
        self::assertSame('console', $erro->context);
        self::assertSame('artisan schedule:run', $erro->origin);
        self::assertNull($erro->http_method);
    }

    // --- Nada de dado de cliente ---------------------------------------------------------------------------------------------

    public function test_a_mensagem_perde_email_cpf_numero_longo_e_token(): void
    {
        $this->recorder()->record($this->falha('Falhou para maria@exemplo.com.br, CPF 123.456.789-09, conta 123456789012 e chave '.str_repeat('a1B2', 12)));

        $m = ErrorLog::query()->firstOrFail()->message;

        self::assertStringContainsString('[e-mail]', $m);
        self::assertStringContainsString('[cpf]', $m);
        self::assertStringContainsString('[número]', $m);
        self::assertStringContainsString('[token]', $m);
        self::assertStringNotContainsString('maria@exemplo', $m);
        self::assertStringNotContainsString('123.456.789-09', $m);
        self::assertStringNotContainsString('123456789012', $m);
    }

    public function test_erro_de_consulta_guarda_a_sql_com_interrogacao_e_nunca_os_valores(): void
    {
        $e = new QueryException('mysql', 'select * from users where email = ? and cpf = ?', ['fulano@teste.com', '12345678909'], new \Exception('SQLSTATE[42S22]: Column not found'));

        $this->recorder()->record($e);

        $m = ErrorLog::query()->firstOrFail()->message;
        self::assertStringContainsString('SQLSTATE[42S22]', $m);
        self::assertStringContainsString('where email = ? and cpf = ?', $m);
        self::assertStringNotContainsString('fulano@teste.com', $m);
        self::assertStringNotContainsString('12345678909', $m);
    }

    public function test_o_passo_a_passo_traz_so_codigo_do_cerne_sem_argumentos(): void
    {
        $this->recorder()->record($this->falha());

        $trace = ErrorLog::query()->firstOrFail()->trace;

        self::assertStringContainsString('tests/Feature/ErrorTrackingTest.php', $trace);
        self::assertStringNotContainsString('vendor/', $trace);
    }

    // --- Resolvido que volta -------------------------------------------------------------------------------------------------

    public function test_erro_resolvido_que_volta_reabre_sozinho_e_conta_como_regressao(): void
    {
        $this->recorder()->record($this->falha());
        $erro = ErrorLog::query()->firstOrFail();
        $erro->forceFill(['status' => 'resolved', 'resolved_at' => now()])->save();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-23 10:00:00'));
        $this->recorder()->record($this->falha());

        $erro->refresh();
        self::assertSame('new', $erro->status);
        self::assertNull($erro->resolved_at);
        self::assertSame(1, $erro->regressions);
        self::assertSame('2026-10-23 10:00:00', $erro->reopened_at->format('Y-m-d H:i:s'));
        self::assertSame(2, $erro->occurrences);
    }

    public function test_erro_ignorado_continua_ignorado_mas_conta(): void
    {
        $this->recorder()->record($this->falha());
        $erro = ErrorLog::query()->firstOrFail();
        $erro->forceFill(['status' => 'ignored'])->save();

        $this->recorder()->record($this->falha());

        $erro->refresh();
        self::assertSame('ignored', $erro->status);
        self::assertSame(0, $erro->regressions);
        self::assertSame(2, $erro->occurrences);
    }

    // --- Banco fora do ar ----------------------------------------------------------------------------------------------------

    public function test_se_o_banco_falha_o_erro_vai_para_o_arquivo_de_reserva_sem_derrubar_ninguem_e_volta_depois(): void
    {
        $quebrado = new class extends ErrorRecorder
        {
            public function store(array $d): void
            {
                throw new \PDOException('SQLSTATE[HY000] [2002] Operation not permitted');
            }
        };

        $quebrado->record($this->falha('com o banco fora'));   // não pode lançar

        self::assertSame(0, ErrorLog::query()->count());
        $linhas = file($this->recorder()->fallbackPath(), FILE_IGNORE_NEW_LINES);
        self::assertCount(1, $linhas);
        self::assertStringContainsString('com o banco fora', $linhas[0]);

        self::assertSame(1, $this->recorder()->importPending());

        self::assertFalse(is_file($this->recorder()->fallbackPath()), 'a reserva esvazia depois de importada');
        self::assertSame(1, ErrorLog::query()->count());
        self::assertSame('2026-10-22', (string) DB::table('error_log_days')->value('day'), 'volta com o dia em que aconteceu');
    }

    public function test_importar_sem_reserva_nao_faz_nada(): void
    {
        self::assertSame(0, $this->recorder()->importPending());
    }

    public function test_desligado_nada_e_anotado(): void
    {
        config(['cerne.error_tracking.enabled' => false]);

        $this->recorder()->record($this->falha());

        self::assertSame(0, ErrorLog::query()->count());
    }

    // --- Limpeza -------------------------------------------------------------------------------------------------------------

    public function test_a_limpeza_tira_o_que_ja_foi_tratado_ha_muito_e_deixa_o_recente_e_o_aberto(): void
    {
        $this->recorder()->record($this->falha());
        $this->recorder()->record(new RuntimeException('b'));
        $this->recorder()->record(new RuntimeException('c'));
        $this->recorder()->record(new RuntimeException('d'));
        [$resolvidoVelho, $resolvidoNovo, $abertoVelho, $abertoMuitoVelho] = ErrorLog::query()->orderBy('id')->get()->all();

        $resolvidoVelho->forceFill(['status' => 'resolved', 'last_seen_at' => now()->subDays(91)])->save();
        $resolvidoNovo->forceFill(['status' => 'resolved', 'last_seen_at' => now()->subDays(30)])->save();
        $abertoVelho->forceFill(['last_seen_at' => now()->subDays(100)])->save();
        $abertoMuitoVelho->forceFill(['last_seen_at' => now()->subDays(181)])->save();

        self::assertSame(2, $this->recorder()->prune());
        self::assertSame([$resolvidoNovo->id, $abertoVelho->id], ErrorLog::query()->orderBy('id')->pluck('id')->all());
    }

    // --- Relatório diário ----------------------------------------------------------------------------------------------------

    private function ocorrencias(): void
    {
        // Ontem (21/10): A três vezes (nova), B uma vez (já existia, voltou).
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 10:00:00'));
        $this->recorder()->record($this->outra('B antigo'));
        ErrorLog::query()->firstOrFail()->forceFill(['status' => 'resolved'])->save();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-21 10:00:00'));
        $this->recorder()->record($this->outra('B antigo'));
        foreach (range(1, 3) as $_) {
            $this->recorder()->record($this->falha('A nova'));
        }

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-22 09:00:00'));
    }

    public function test_o_relatorio_soma_o_dia_do_mais_frequente_para_o_menos_com_novos_e_reabertos(): void
    {
        $this->ocorrencias();

        $r = app(ErrorDigest::class)->build(CarbonImmutable::parse('2026-10-21'));

        self::assertSame(4, $r['occurrences']);
        self::assertSame(2, $r['types']);
        self::assertSame(1, $r['new'], 'só o A nasceu neste dia');
        self::assertSame(1, $r['reopened'], 'o B estava resolvido e voltou');
        self::assertSame(2, $r['open_total']);
        self::assertSame(['A nova', 'B antigo'], array_map(fn ($l) => $l['message'], $r['rows']));
        self::assertSame(3, $r['rows'][0]['day_count']);
        self::assertSame(0, $r['more']);
    }

    public function test_dia_sem_erro_gera_relatorio_de_tudo_calmo(): void
    {
        $r = app(ErrorDigest::class)->build(CarbonImmutable::parse('2026-10-21'));

        self::assertSame(0, $r['occurrences']);
        self::assertSame([], $r['rows']);
    }

    public function test_o_relatorio_sai_uma_vez_por_dia_para_o_destinatario_configurado(): void
    {
        Mail::fake();
        $this->ocorrencias();
        config(['cerne.error_tracking.report_to' => ['dono@teste.com']]);
        $digest = app(ErrorDigest::class);
        $dia = CarbonImmutable::parse('2026-10-21');

        self::assertSame('enviado', $digest->send($dia)['status']);
        self::assertSame('já enviado', $digest->send($dia)['status'], 'o cron pode disparar repetido');
        self::assertSame('enviado', $digest->send($dia, forcar: true)['status']);

        Mail::assertSent(ErrorDigestMail::class, 2);
        Mail::assertSent(ErrorDigestMail::class, function (ErrorDigestMail $m) {
            $m->assertHasTo('dono@teste.com');
            $m->assertSeeInHtml('A nova');
            $m->assertSeeInHtml('voltou depois de resolvido');
            $m->assertSeeInHtml(route('admin.errors'));

            return str_contains($m->envelope()->subject, '4 erros em 21/10 (2 tipos)');
        });
    }

    public function test_sem_destinatario_configurado_vai_para_os_administradores(): void
    {
        Mail::fake();
        $this->admin('admin1@teste.com');
        $this->admin('admin2@teste.com');
        User::factory()->create(['email' => 'cliente@teste.com']);

        $r = app(ErrorDigest::class)->send(CarbonImmutable::parse('2026-10-21'));

        self::assertEqualsCanonicalizing(['admin1@teste.com', 'admin2@teste.com'], $r['to']);
        Mail::assertSent(ErrorDigestMail::class, fn (ErrorDigestMail $m) => str_contains($m->envelope()->subject, 'nenhum erro em 21/10'));
    }

    public function test_sem_ninguem_para_receber_nao_envia_nem_reserva_o_dia(): void
    {
        Mail::fake();

        self::assertSame('sem destinatário', app(ErrorDigest::class)->send(CarbonImmutable::parse('2026-10-21'))['status']);
        self::assertSame(0, DB::table('error_report_digests')->count());
        Mail::assertNothingSent();
    }

    public function test_se_o_envio_falha_o_dia_nao_fica_reservado(): void
    {
        $this->admin();
        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(new RuntimeException('SMTP fora'));

        try {
            app(ErrorDigest::class)->send(CarbonImmutable::parse('2026-10-21'));
            self::fail('deveria propagar a falha do e-mail');
        } catch (RuntimeException) {
            // esperado
        }

        self::assertSame(0, DB::table('error_report_digests')->count(), 'a próxima rodada tenta de novo');
    }

    public function test_o_comando_manda_o_relatorio_de_ontem_importa_a_reserva_e_limpa(): void
    {
        Mail::fake();
        $this->admin();
        $this->ocorrencias();
        file_put_contents($this->recorder()->fallbackPath(), json_encode(array_merge(
            $this->recorder()->describe(new RuntimeException('veio da reserva')), ['occurred_at' => '2026-10-21 12:00:00'],
        ))."\n");

        $this->artisan('cerne:error-report')->expectsOutputToContain('enviado')->assertSuccessful();

        Mail::assertSent(ErrorDigestMail::class, fn (ErrorDigestMail $m) => $m->dados['occurrences'] === 5);
        self::assertSame(3, ErrorLog::query()->count());
        self::assertFalse(is_file($this->recorder()->fallbackPath()));

        $this->artisan('cerne:error-report')->expectsOutputToContain('já enviado')->assertSuccessful();
        $this->artisan('cerne:error-report', ['--date' => 'xx'])->assertFailed();
    }

    public function test_o_comando_falha_com_aviso_quando_ninguem_vai_receber(): void
    {
        $this->artisan('cerne:error-report')->expectsOutputToContain('Nenhum destinatário')->assertFailed();
    }

    public function test_a_rotina_esta_agendada_as_oito_e_dez(): void
    {
        $evento = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'relatorio-de-erros');

        self::assertNotNull($evento);
        self::assertSame('10 8 * * *', $evento->expression);
    }

    // --- Tela do administrador ---------------------------------------------------------------------------------------------

    public function test_so_o_administrador_abre_a_tela_de_erros(): void
    {
        $this->actingAs(User::factory()->consultant()->create());
        Livewire::test(AdminErrors::class)->assertStatus(403);
        $this->get(route('admin.errors'))->assertForbidden();

        $this->actingAs($this->admin());
        $this->get(route('admin.errors'))->assertOk()->assertSee('Erros');
    }

    public function test_a_tela_lista_filtra_busca_e_atualiza_sozinha(): void
    {
        $this->recorder()->record($this->falha('quebrou o painel'));
        $this->recorder()->record(new RuntimeException('outra coisa'));
        ErrorLog::query()->where('message', 'outra coisa')->update(['status' => 'resolved']);
        $this->actingAs($this->admin());

        Livewire::test(AdminErrors::class)
            ->assertSee('quebrou o painel')->assertDontSee('outra coisa')
            ->assertSee('Atualiza sozinho')
            ->set('filtro', 'resolvidos')->assertSee('outra coisa')->assertDontSee('quebrou o painel')
            ->set('filtro', 'todos')->assertSee('quebrou o painel')->assertSee('outra coisa')
            ->set('busca', 'painel')->assertSee('quebrou o painel')->assertDontSee('outra coisa');

        self::assertStringContainsString('wire:poll.30s', Livewire::test(AdminErrors::class)->html());
    }

    public function test_resolver_ignorar_e_reabrir_pela_tela(): void
    {
        $this->recorder()->record($this->falha());
        $erro = ErrorLog::query()->firstOrFail();
        $this->actingAs($this->admin());

        $tela = Livewire::test(AdminErrors::class);

        $tela->call('resolver', $erro->id);
        self::assertSame('resolved', $erro->fresh()->status);
        self::assertNotNull($erro->fresh()->resolved_at);

        $tela->call('reabrir', $erro->id);
        self::assertSame('new', $erro->fresh()->status);
        self::assertNull($erro->fresh()->resolved_at);

        $tela->call('ignorar', $erro->id);
        self::assertSame('ignored', $erro->fresh()->status);
    }

    public function test_o_detalhe_mostra_o_passo_a_passo_o_dia_a_dia_e_quantos_usuarios(): void
    {
        $this->actingAs(User::factory()->create());
        $this->recorder()->record($this->falha('detalhe aqui'));
        $erro = ErrorLog::query()->firstOrFail();
        $this->actingAs($this->admin());

        Livewire::test(AdminErrors::class)
            ->call('abrir', $erro->id)->assertSet('detalheAberto', true)
            ->assertSee('Passo a passo')->assertSee('tests/Feature/ErrorTrackingTest.php')->assertSee('22/10: 1')->assertSee('1 usuário')
            ->call('fechar')->assertSet('detalheAberto', false)->assertSet('selecionado', null);
    }

    public function test_o_menu_do_administrador_mostra_quantos_erros_estao_abertos(): void
    {
        $this->recorder()->record($this->falha());
        $this->actingAs($this->admin());

        $this->get(route('admin.users'))->assertOk()->assertSee('Erros')->assertSee('Há erros abertos');
    }

    public function test_a_tela_mostra_os_totais_de_hoje(): void
    {
        $this->recorder()->record($this->falha());
        $this->recorder()->record($this->falha());
        $this->recorder()->record(new RuntimeException('segundo tipo'));
        $this->actingAs($this->admin());

        $tela = Livewire::test(AdminErrors::class);
        $resumo = $tela->viewData('resumo');

        self::assertSame(2, $resumo['open']);
        self::assertSame(3, $resumo['today']);
        self::assertSame(2, $resumo['new_today']);
    }
}
