<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\LeadActivityType;
use App\Enums\LeadQuoteStatus;
use App\Enums\LeadStage;
use App\Enums\ProcessingStatus;
use App\Jobs\ExtractLeadQuoteJob;
use App\Livewire\Consultant\LeadsIndex;
use App\Livewire\Insurance\InsuranceIndex;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\InsurancePolicy;
use App\Models\Lead;
use App\Models\LeadAlertDigest;
use App\Models\LeadAttachment;
use App\Models\LeadQuote;
use App\Models\ProfileMember;
use App\Models\User;
use App\Notifications\LeadFollowUpDigest;
use App\Services\Extraction\LeadQuoteExtractionService;
use App\Services\LeadAlertService;
use App\Services\LeadService;
use App\Support\LeadQuoteComparison;
use App\Support\NotificationPresenter;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Segunda entrega dos leads de seguros: leitura da simulação em PDF por IA (sempre com revisão humana), comparação lado
 * a lado, resumo diário de acompanhamento e a cotação aceita virando apólice.
 */
class InsuranceLeadsSecondDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private User $corretor;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('cerne.lead_files.disk'));
        config(['cerne.ai.api_key' => 'chave-de-teste']);

        $this->corretor = User::factory()->broker()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function lead(array $sobre = []): Lead
    {
        return Lead::factory()->insurance()->create($sobre + ['consultant_id' => $this->corretor->id]);
    }

    private function quote(Lead $lead, array $sobre = []): LeadQuote
    {
        return LeadQuote::factory()->create($sobre + ['lead_id' => $lead->id, 'premium' => '100.00']);
    }

    private function pdf(Lead $lead, ?LeadQuote $quote = null): LeadAttachment
    {
        return app(LeadService::class)->attachFile($lead, UploadedFile::fake()->createWithContent('simulacao.pdf', '%PDF-1.4 teste'), $quote, $this->corretor->id);
    }

    /** @return array<string, mixed> uma cotação como a IA devolve */
    private function lida(array $sobre = []): array
    {
        return $sobre + [
            'tipo' => 'vida',
            'seguradora' => 'Azos',
            'produto' => 'Vida Individual',
            'numero_simulacao' => 'S-123',
            'segurado' => 'Fernanda Lima',
            'valor_segurado' => '400000.00',
            'coberturas' => [
                ['nome' => 'Morte', 'valor' => '400000.00', 'franquia' => ''],
                ['nome' => 'Doenças Graves 30', 'valor' => '135000.00', 'franquia' => ''],
            ],
            'premio' => '141.40',
            'periodicidade' => 'monthly',
            'premio_total_anual' => null,
            'validade' => '2026-11-30',
            'observacoes_item' => 'Carência de 2 anos para suicídio.',
            'campos_incertos' => ['validade'],
        ];
    }

    /** Troca a chamada à API por um retorno fixo. */
    private function leituraDevolve(array $cotacoes, string $observacoes = ''): void
    {
        $this->partialMock(LeadQuoteExtractionService::class, function (MockInterface $m) use ($cotacoes, $observacoes) {
            $m->shouldAllowMockingProtectedMethods()->shouldReceive('ask')->andReturn(['cotacoes' => $cotacoes, 'observacoes' => $observacoes]);
        });
    }

    // ---- leitura por IA: serviço

    public function test_a_leitura_guarda_o_resultado_no_anexo_e_nao_cria_cotacao_nenhuma(): void
    {
        $lead = $this->lead();
        $anexo = $this->pdf($lead);
        $this->leituraDevolve([$this->lida()], 'Validade em dúvida.');

        $lido = app(LeadQuoteExtractionService::class)->extract($anexo);

        self::assertSame(ProcessingStatus::Completed, $lido->extraction_status);
        self::assertSame('Azos', $lido->extraction_summary['cotacoes'][0]['seguradora']);
        self::assertSame([], $lido->extraction_summary['aplicadas']);
        self::assertTrue($lido->awaitingReview());
        self::assertSame(0, LeadQuote::query()->count(), 'dado extraído por IA só vira cotação depois da revisão');
    }

    public function test_falha_da_leitura_fica_registrada_e_nao_cria_nada(): void
    {
        $anexo = $this->pdf($this->lead());
        $this->partialMock(LeadQuoteExtractionService::class, function (MockInterface $m) {
            $m->shouldAllowMockingProtectedMethods()->shouldReceive('ask')->andThrow(new \RuntimeException('API fora do ar'));
        });

        $lido = app(LeadQuoteExtractionService::class)->extract($anexo);

        self::assertSame(ProcessingStatus::Failed, $lido->extraction_status);
        self::assertStringContainsString('API fora do ar', $lido->extraction_error);
        self::assertSame(0, LeadQuote::query()->count());
    }

    public function test_arquivo_que_nao_e_pdf_nao_e_lido(): void
    {
        $lead = $this->lead();
        $imagem = app(LeadService::class)->attachFile($lead, UploadedFile::fake()->image('simulacao.png'), null, $this->corretor->id);

        $lido = app(LeadQuoteExtractionService::class)->extract($imagem);

        self::assertSame(ProcessingStatus::Failed, $lido->extraction_status);
        self::assertStringContainsString('PDF', $lido->extraction_error);
    }

    public function test_o_job_le_o_anexo_e_a_falha_definitiva_registra_o_erro(): void
    {
        $anexo = $this->pdf($this->lead());
        $this->leituraDevolve([$this->lida()]);

        (new ExtractLeadQuoteJob($anexo->id))->handle(app(LeadQuoteExtractionService::class));
        self::assertSame(ProcessingStatus::Completed, $anexo->fresh()->extraction_status);

        (new ExtractLeadQuoteJob($anexo->id))->failed(new \RuntimeException('estourou'));
        $anexo->refresh();
        self::assertSame(ProcessingStatus::Failed, $anexo->extraction_status);
        self::assertStringContainsString('estourou', $anexo->extraction_error);
    }

    public function test_conversao_da_leitura_nos_campos_do_formulario(): void
    {
        $valores = LeadQuoteExtractionService::toFormValues($this->lida());

        self::assertSame('vida', $valores['type']);
        self::assertSame('Azos', $valores['insurer']);
        self::assertSame('141.40', $valores['premium']);
        self::assertSame('monthly', $valores['frequency']);
        self::assertSame('400000.00', $valores['coverageAmount']);
        self::assertSame('2026-11-30', $valores['validUntil']);
        self::assertSame(['validade'], $valores['uncertain']);
        self::assertSame(['name' => 'Doenças Graves 30', 'value' => '135000.00', 'deductible' => ''], $valores['coverages'][1]);
    }

    public function test_leitura_com_so_o_total_anual_vira_pagamento_anual(): void
    {
        $valores = LeadQuoteExtractionService::toFormValues($this->lida(['premio' => null, 'periodicidade' => 'monthly', 'premio_total_anual' => '1696.80']));

        self::assertSame('1696.80', $valores['premium']);
        self::assertSame('annual', $valores['frequency']);
    }

    public function test_valores_estranhos_da_leitura_sao_descartados_em_vez_de_entrar_no_formulario(): void
    {
        $valores = LeadQuoteExtractionService::toFormValues($this->lida([
            'tipo' => 'astronauta', 'premio' => 'R$ 141,40', 'valor_segurado' => '-5', 'validade' => '30/11/2026', 'periodicidade' => 'diario',
        ]));

        self::assertSame('outro', $valores['type']);
        self::assertSame('', $valores['premium']);
        self::assertSame('', $valores['coverageAmount']);
        self::assertSame('', $valores['validUntil']);
        self::assertSame('monthly', $valores['frequency']);
    }

    public function test_o_esquema_enviado_a_api_respeita_o_limite_de_campos_que_aceitam_nulo(): void
    {
        $item = LeadQuoteExtractionService::schema()['properties']['cotacoes']['items'];
        $anulaveis = collect($item['properties'])->filter(fn ($p) => is_array($p['type'] ?? null) && in_array('null', $p['type'], true))->count();

        self::assertLessThanOrEqual(16, $anulaveis);
        self::assertSame(array_keys($item['properties']), $item['required']);
    }

    // ---- leitura por IA: tela

    public function test_enviar_pdf_para_ler_cria_o_anexo_e_despacha_a_leitura_sem_criar_cotacao(): void
    {
        Queue::fake();
        $lead = $this->lead();
        $this->actingAs($this->corretor);

        Livewire::test(LeadsIndex::class)
            ->call('openQuotes', $lead->id)
            ->call('chooseAttachTarget', 'ia')
            ->set('anexo', UploadedFile::fake()->create('simulacao.pdf', 100, 'application/pdf'))
            ->assertHasNoErrors();

        $anexo = LeadAttachment::query()->sole();
        self::assertSame(ProcessingStatus::Pending, $anexo->extraction_status);
        self::assertNotNull($anexo->extraction_requested_at);
        Queue::assertPushed(ExtractLeadQuoteJob::class, fn ($job) => $job->attachmentId === $anexo->id);
        self::assertSame(0, LeadQuote::query()->count());
    }

    public function test_imagem_nao_serve_para_leitura_por_ia(): void
    {
        Queue::fake();
        $lead = $this->lead();
        $this->actingAs($this->corretor);

        Livewire::test(LeadsIndex::class)
            ->call('openQuotes', $lead->id)
            ->call('chooseAttachTarget', 'ia')
            ->set('anexo', UploadedFile::fake()->image('foto.png'))
            ->assertHasErrors('anexo');

        self::assertSame(0, LeadAttachment::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_sem_chave_da_api_o_arquivo_fica_mas_nada_e_lido(): void
    {
        Queue::fake();
        config(['cerne.ai.api_key' => null]);
        $lead = $this->lead();
        $this->actingAs($this->corretor);

        Livewire::test(LeadsIndex::class)
            ->call('openQuotes', $lead->id)
            ->call('chooseAttachTarget', 'ia')
            ->set('anexo', UploadedFile::fake()->create('simulacao.pdf', 100, 'application/pdf'))
            ->assertHasErrors('anexo')
            ->assertDontSee('Ler simulação com IA');

        self::assertNull(LeadAttachment::query()->sole()->extraction_status);
        Queue::assertNothingPushed();
    }

    public function test_teto_diario_de_leituras_por_profissional(): void
    {
        Queue::fake();
        config(['cerne.ai.lead_quote_reads_per_day' => 2]);
        $lead = $this->lead();
        $this->actingAs($this->corretor);
        $tela = Livewire::test(LeadsIndex::class)->call('openQuotes', $lead->id);

        foreach (['a', 'b'] as $nome) {
            $tela->call('readWithAi', $this->pdf($lead)->id)->assertHasNoErrors();
        }

        $tela->call('readWithAi', $this->pdf($lead)->id)->assertHasErrors('anexo');
        Queue::assertPushed(ExtractLeadQuoteJob::class, 2);

        // O limite é de cada profissional: o de outro continua livre.
        self::assertFalse(app(LeadQuoteExtractionService::class)->limitReached(User::factory()->broker()->create()));
        self::assertTrue(app(LeadQuoteExtractionService::class)->limitReached($this->corretor));
    }

    public function test_leitura_do_dia_anterior_nao_conta_no_teto(): void
    {
        config(['cerne.ai.lead_quote_reads_per_day' => 1]);
        $anexo = $this->pdf($this->lead());
        $anexo->forceFill(['extraction_requested_at' => now()->subDay()])->save();

        self::assertFalse(app(LeadQuoteExtractionService::class)->limitReached($this->corretor));
    }

    public function test_revisar_preenche_o_formulario_sem_gravar_e_salvar_cria_a_cotacao_e_liga_o_pdf(): void
    {
        $lead = $this->lead();
        $anexo = $this->pdf($lead);
        $this->leituraDevolve([$this->lida()]);
        app(LeadQuoteExtractionService::class)->extract($anexo);

        $this->actingAs($this->corretor);
        $tela = Livewire::test(LeadsIndex::class)
            ->call('openQuotes', $lead->id)
            ->call('reviewReading', $anexo->id)
            ->assertSet('showQuoteForm', true)
            ->assertSet('quoteInsurer', 'Azos')
            ->assertSet('quotePremium', '141.40')
            ->assertSet('quoteValidUntil', '2026-11-30')
            ->assertSet('reviewUncertain', ['validade'])
            ->assertSee('Preenchido pela leitura automática');

        self::assertSame(0, LeadQuote::query()->count(), 'abrir a revisão não grava nada');

        // O profissional corrige um campo antes de salvar.
        $tela->set('quotePremium', '150.00')->call('saveQuote')->assertHasNoErrors();

        $quote = LeadQuote::query()->sole();
        self::assertSame('150.00', $quote->premium);
        self::assertSame('Azos', $quote->insurer_name);

        $anexo->refresh();
        self::assertSame($quote->id, $anexo->lead_quote_id, 'o PDF passa a ser a simulação dessa cotação');
        self::assertNotNull($anexo->extraction_applied_at);
        self::assertFalse($anexo->awaitingReview());
    }

    public function test_pdf_com_varias_opcoes_vira_uma_cotacao_por_opcao_escolhida(): void
    {
        $lead = $this->lead();
        $anexo = $this->pdf($lead);
        $this->leituraDevolve([$this->lida(['seguradora' => 'Azos']), $this->lida(['seguradora' => 'Icatu', 'premio' => '180.00'])]);
        app(LeadQuoteExtractionService::class)->extract($anexo);

        $this->actingAs($this->corretor);
        $tela = Livewire::test(LeadsIndex::class)->call('openQuotes', $lead->id);

        $tela->call('reviewReading', $anexo->id, 0)->call('saveQuote');
        self::assertNull($anexo->fresh()->extraction_applied_at, 'ainda falta uma opção');
        self::assertTrue($anexo->fresh()->awaitingReview());

        $tela->call('reviewReading', $anexo->id, 1)->assertSet('quoteInsurer', 'Icatu')->call('saveQuote');

        self::assertSame(['Azos', 'Icatu'], LeadQuote::query()->orderBy('created_at')->orderBy('insurer_name')->pluck('insurer_name')->all());
        self::assertSame([0, 1], $anexo->fresh()->extraction_summary['aplicadas']);
        self::assertNotNull($anexo->fresh()->extraction_applied_at);
    }

    public function test_pdf_ja_anexado_a_uma_cotacao_atualiza_essa_cotacao_sem_apagar_o_que_a_leitura_nao_trouxe(): void
    {
        $lead = $this->lead();
        $quote = $this->quote($lead, ['insurer_name' => 'Azos', 'product' => 'Produto antigo', 'notes' => 'Anotação minha']);
        $anexo = $this->pdf($lead, $quote);
        $this->leituraDevolve([$this->lida(['produto' => '', 'observacoes_item' => '', 'premio' => '199.90'])]);
        app(LeadQuoteExtractionService::class)->extract($anexo);

        $this->actingAs($this->corretor);
        Livewire::test(LeadsIndex::class)
            ->call('openQuotes', $lead->id)
            ->call('reviewReading', $anexo->id)
            ->assertSet('editingQuoteId', $quote->id)
            ->assertSet('quoteProduct', 'Produto antigo')
            ->assertSet('quoteNotes', 'Anotação minha')
            ->assertSet('quotePremium', '199.90')
            ->call('saveQuote');

        self::assertSame(1, LeadQuote::query()->count());
        self::assertSame('199.90', $quote->fresh()->premium);
    }

    public function test_descartar_a_leitura_nao_cadastra_nada(): void
    {
        $lead = $this->lead();
        $anexo = $this->pdf($lead);
        $this->leituraDevolve([$this->lida()]);
        app(LeadQuoteExtractionService::class)->extract($anexo);

        $this->actingAs($this->corretor);
        Livewire::test(LeadsIndex::class)->call('openQuotes', $lead->id)->call('discardReading', $anexo->id);

        $anexo->refresh();
        self::assertNull($anexo->extraction_status);
        self::assertNull($anexo->extraction_summary);
        self::assertSame(0, LeadQuote::query()->count());
    }

    public function test_cancelar_o_formulario_da_leitura_deixa_a_leitura_para_depois(): void
    {
        $lead = $this->lead();
        $anexo = $this->pdf($lead);
        $this->leituraDevolve([$this->lida()]);
        app(LeadQuoteExtractionService::class)->extract($anexo);

        $this->actingAs($this->corretor);
        Livewire::test(LeadsIndex::class)->call('openQuotes', $lead->id)->call('reviewReading', $anexo->id)->call('cancelQuoteForm')->assertSet('reviewingAttachmentId', null);

        self::assertTrue($anexo->fresh()->awaitingReview());
        self::assertSame(0, LeadQuote::query()->count());
    }

    public function test_so_o_dono_do_lead_le_e_revisa_os_pdfs_dele(): void
    {
        Queue::fake();
        $leadDeOutro = Lead::factory()->insurance()->create(['consultant_id' => User::factory()->broker()->create()->id]);
        $anexoDeOutro = $this->pdf($leadDeOutro);
        $meu = $this->lead();

        $this->actingAs($this->corretor);
        $tela = Livewire::test(LeadsIndex::class)->call('openQuotes', $meu->id);

        foreach (['readWithAi', 'reviewReading', 'discardReading'] as $acao) {
            try {
                $tela->call($acao, $anexoDeOutro->id);
                self::fail("$acao deveria falhar");
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                // esperado: o anexo não é de um lead aberto na janela
            }
        }

        Queue::assertNothingPushed();
    }

    public function test_enquanto_a_leitura_roda_a_janela_se_atualiza_sozinha(): void
    {
        $lead = $this->lead();
        $anexo = $this->pdf($lead);
        $anexo->forceFill(['extraction_status' => ProcessingStatus::Processing])->save();

        $this->actingAs($this->corretor);
        Livewire::test(LeadsIndex::class)->call('openQuotes', $lead->id)->assertSee('wire:poll.4s', false)->assertSee('Lendo a simulação');

        $anexo->forceFill(['extraction_status' => ProcessingStatus::Completed, 'extraction_summary' => ['cotacoes' => [$this->lida()], 'aplicadas' => []]])->save();
        Livewire::test(LeadsIndex::class)->call('openQuotes', $lead->id)->assertDontSee('wire:poll.4s', false)->assertSee('Revisar leitura');
    }

    // ---- comparação

    public function test_compara_cotacoes_do_mesmo_tipo_marcando_o_melhor_de_cada_linha(): void
    {
        $lead = $this->lead();
        $cara = $this->quote($lead, ['insurer_name' => 'Cara', 'premium' => '200.00', 'coverage_amount' => '500000.00', 'coverages' => [
            ['name' => 'Morte', 'value' => '500000.00', 'deductible' => null],
            ['name' => 'Invalidez', 'value' => '500000.00', 'deductible' => null],
        ]]);
        $barata = $this->quote($lead, ['insurer_name' => 'Barata', 'premium' => '100.00', 'coverage_amount' => '400000.00', 'coverages' => [
            ['name' => 'morte', 'value' => '400000.00', 'deductible' => null],
        ]]);
        $this->quote($lead, ['insurer_name' => 'De carro', 'insurance_type' => 'carro', 'premium' => '300.00']);

        $grupos = LeadQuoteComparison::build($lead->fresh('quotes')->quotes);

        self::assertCount(1, $grupos, 'carro tem só uma cotação, não há o que comparar');
        $g = $grupos[0];
        self::assertSame('vida', $g['type']);
        self::assertSame([$barata->id, $cara->id], array_map(fn ($q) => $q->id, $g['quotes']), 'a mais barata primeiro');
        self::assertSame('1200.00', $g['best']['premium']);
        self::assertSame('500000.00', $g['best']['capital']);
        // Barata: 1.200 por 400 mil = 3,00 por mil; cara: 2.400 por 500 mil = 4,80. Quem tem o preço por capital menor ganha.
        self::assertSame('3.00', $g['costPerThousand'][$barata->id]);
        self::assertSame('4.80', $g['costPerThousand'][$cara->id]);
        self::assertSame('3.00', $g['best']['cost']);

        $linhas = collect($g['coverages'])->keyBy(fn ($l) => mb_strtolower($l['name']));
        self::assertNull($linhas['invalidez']['cells'][$barata->id], 'a mais barata não traz invalidez');
        self::assertSame('500000.00', $linhas['invalidez']['cells'][$cara->id]['value']);
        self::assertTrue(LeadQuoteComparison::isBest('500000.00', $linhas['morte qualquer causa']['best']));
        self::assertFalse(LeadQuoteComparison::isBest('400000.00', $linhas['morte qualquer causa']['best']));
    }

    public function test_comparacao_ignora_recusadas_e_vencidas_mas_mantem_a_aceita(): void
    {
        $hoje = CarbonImmutable::parse('2026-10-20');
        $lead = $this->lead();
        $this->quote($lead, ['insurer_name' => 'Boa']);
        $this->quote($lead, ['insurer_name' => 'Recusada', 'status' => 'declined']);
        $this->quote($lead, ['insurer_name' => 'Vencida', 'status' => 'sent', 'valid_until' => '2026-10-01']);
        $aceitaVelha = $this->quote($lead, ['insurer_name' => 'Aceita velha', 'status' => 'accepted', 'valid_until' => '2026-01-01']);

        $grupos = LeadQuoteComparison::build($lead->fresh('quotes')->quotes, $hoje);

        self::assertCount(1, $grupos);
        self::assertEqualsCanonicalizing(['Boa', 'Aceita velha'], array_map(fn ($q) => $q->insurer_name, $grupos[0]['quotes']));
        self::assertContains($aceitaVelha->id, array_map(fn ($q) => $q->id, $grupos[0]['quotes']));
    }

    public function test_empate_nao_destaca_ninguem_e_sem_dois_valores_nao_ha_melhor(): void
    {
        $lead = $this->lead();
        $this->quote($lead, ['premium' => '100.00']);
        $this->quote($lead, ['premium' => '100.00']);

        $g = LeadQuoteComparison::build($lead->fresh('quotes')->quotes)[0];

        self::assertNull($g['best']['premium']);
        self::assertNull($g['best']['capital']);
        self::assertFalse(LeadQuoteComparison::isBest('100.00', null));
        self::assertFalse(LeadQuoteComparison::isBest(null, '100.00'));
    }

    public function test_a_tela_mostra_o_botao_so_com_duas_cotacoes_comparaveis_e_a_tabela_ao_clicar(): void
    {
        $lead = $this->lead();
        $this->quote($lead, ['insurer_name' => 'Azos', 'coverages' => [['name' => 'Morte', 'value' => '400000.00', 'deductible' => null]]]);
        $this->actingAs($this->corretor);

        Livewire::test(LeadsIndex::class)->call('openQuotes', $lead->id)->assertDontSee('Comparar cotações');

        $this->quote($lead, ['insurer_name' => 'Icatu', 'coverages' => [['name' => 'Invalidez', 'value' => '100000.00', 'deductible' => null]]]);

        Livewire::test(LeadsIndex::class)
            ->call('openQuotes', $lead->id)
            ->assertSee('Comparar cotações')
            ->call('toggleComparison')
            ->assertSet('showComparison', true)
            ->assertSee('Prêmio por ano')
            ->assertSee('Prêmio anual por R$ 1.000 de capital')
            ->assertSee('não inclui')
            ->assertSee('Voltar à lista')
            ->call('toggleComparison')
            ->assertSet('showComparison', false)
            ->assertDontSee('Prêmio por ano');
    }

    // ---- resumo diário

    public function test_resumo_conta_cotacao_vencendo_contato_parado_e_passo_atrasado(): void
    {
        $hoje = CarbonImmutable::parse('2026-10-20 08:00:00');
        Carbon::setTestNow($hoje);

        $vencendo = $this->lead(['name' => 'Vencendo']);
        $this->quote($vencendo, ['status' => 'sent', 'valid_until' => '2026-10-22']);
        $this->quote($vencendo, ['status' => 'draft', 'valid_until' => '2026-10-23']);
        $this->quote($vencendo, ['status' => 'sent', 'valid_until' => '2026-10-24']); // fora dos 3 dias
        $this->quote($vencendo, ['status' => 'accepted', 'valid_until' => '2026-10-21']); // já decidida
        $this->quote($vencendo, ['status' => 'sent', 'valid_until' => '2026-10-19']); // já venceu

        $parado = $this->lead(['name' => 'Parado']);
        $parado->forceFill(['created_at' => '2026-10-01 10:00:00'])->save();
        $comContatoRecente = $this->lead(['name' => 'Contato recente']);
        $comContatoRecente->forceFill(['created_at' => '2026-10-01 10:00:00'])->save();
        app(LeadService::class)->logActivity($comContatoRecente, LeadActivityType::Call, null, $hoje->subDays(2), $this->corretor->id);

        $atrasado = $this->lead(['name' => 'Atrasado', 'next_action_at' => '2026-10-18 09:00:00']);
        $this->lead(['name' => 'Novo hoje']);
        $this->lead(['name' => 'Perdido antigo', 'stage' => LeadStage::Lost, 'next_action_at' => '2026-09-01 09:00:00'])->forceFill(['created_at' => '2026-08-01'])->save();

        $contagem = app(LeadAlertService::class)->countsFor($this->corretor, $hoje);

        // 'Vencendo' também está parado desde que foi criado, 'Atrasado' idem: o que importa aqui é cada contagem.
        self::assertSame(2, $contagem['quotes_expiring']);
        self::assertSame(1, $contagem['overdue_actions']);
        self::assertGreaterThanOrEqual(1, $contagem['stale_leads']);
        self::assertNotNull($atrasado);
    }

    public function test_um_resumo_por_dia_mesmo_se_o_cron_disparar_duas_vezes(): void
    {
        Notification::fake();
        $hoje = CarbonImmutable::parse('2026-10-20 08:00:00');
        $lead = $this->lead();
        $this->quote($lead, ['status' => 'sent', 'valid_until' => '2026-10-21']);

        $servico = app(LeadAlertService::class);

        self::assertSame(1, $servico->notifyDigests($hoje));
        self::assertSame(0, $servico->notifyDigests($hoje), 'segundo disparo no mesmo dia não avisa de novo');
        self::assertSame(1, LeadAlertDigest::query()->count());
        Notification::assertSentToTimes($this->corretor, LeadFollowUpDigest::class, 1);

        self::assertSame(1, $servico->notifyDigests($hoje->addDay()->setTime(8, 0)), 'no dia seguinte avisa de novo, se ainda houver o que avisar');
    }

    public function test_sem_nada_a_dizer_nao_ha_aviso_e_cada_profissional_recebe_o_seu(): void
    {
        Notification::fake();
        $hoje = CarbonImmutable::parse('2026-10-20 08:00:00');
        $this->lead(); // recém-criado: nada pede atenção

        $outro = User::factory()->broker()->create();
        $leadDoOutro = Lead::factory()->insurance()->create(['consultant_id' => $outro->id, 'next_action_at' => '2026-10-10 09:00:00']);

        self::assertSame(1, app(LeadAlertService::class)->notifyDigests($hoje));
        Notification::assertNotSentTo($this->corretor, LeadFollowUpDigest::class);
        Notification::assertSentTo($outro, LeadFollowUpDigest::class, fn ($n) => $n->overdueActions === 1 && $n->quotesExpiring === 0);
        self::assertNotNull($leadDoOutro);
    }

    public function test_texto_do_resumo_e_a_apresentacao_no_sino(): void
    {
        self::assertSame(
            ['2 cotações vencem nos próximos dias', '1 contato está sem retorno há dias', '3 próximos passos estão atrasados'],
            LeadFollowUpDigest::linesFor(2, 1, 3),
        );
        self::assertSame(['1 cotação vence nos próximos dias'], LeadFollowUpDigest::linesFor(1, 0, 0));

        $this->corretor->notify(new LeadFollowUpDigest(1, 0, 2));
        $apresentado = NotificationPresenter::present($this->corretor->notifications()->sole());

        self::assertSame('Leads que pedem atenção', $apresentado['heading']);
        self::assertStringContainsString('1 cotação vence', $apresentado['message']);
        self::assertSame(route('consultant.leads'), $apresentado['url']);
    }

    // ---- cotação aceita vira apólice

    /** @return array{0: User, 1: FinancialProfile, 2: User} consultor, perfil do cliente e o cliente */
    private function consultorNoPerfilDoCliente(): array
    {
        $consultor = User::factory()->consultant()->create();
        $cliente = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $cliente->id]);
        ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $cliente->id]);
        ConsultantClient::factory()->create(['consultant_id' => $consultor->id, 'client_id' => $cliente->id, 'status' => ConsultantClientStatus::Active]);

        $this->actingAs($consultor);
        app(ProfileContext::class)->set($perfil, member: null, asConsultant: true);

        return [$consultor, $perfil, $cliente];
    }

    public function test_apolice_nasce_da_cotacao_aceita_e_o_lead_vira_emitida(): void
    {
        [$consultor] = $this->consultorNoPerfilDoCliente();
        $lead = Lead::factory()->insurance()->create(['consultant_id' => $consultor->id, 'name' => 'Fernanda Lima']);
        $quote = LeadQuote::factory()->create([
            'lead_id' => $lead->id, 'insurer_name' => 'Azos', 'premium' => '141.40', 'status' => 'accepted', 'coverage_amount' => '400000.00',
            'coverages' => [['name' => 'Morte', 'value' => '400000.00', 'deductible' => null], ['name' => 'Doenças Graves 30', 'value' => '135000.00', 'deductible' => null]],
        ]);

        Livewire::test(InsuranceIndex::class)
            ->assertSet('policySourceQuoteId', '')
            ->call('togglePolicyForm')
            ->assertSee('Começar de uma cotação aceita')
            ->set('policySourceQuoteId', $quote->id)
            ->assertSet('policyInsurerName', 'Azos')
            ->assertSet('policyMonthlyPremium', '141.40')
            ->assertSet('policyPaymentFrequency', 'monthly')
            ->assertSet('policyAnnualPremium', '1696.80')
            ->assertSet('policyCoverageAmount', '400000.00')
            ->assertSet('policyInsuredPersonName', 'Fernanda Lima')
            ->set('policyStartDate', '2026-11-01')
            ->set('policyNumber', 'AP-1')
            ->call('savePolicy')
            ->assertHasNoErrors();

        $apolice = InsurancePolicy::withoutProfileScope()->where('policy_number', 'AP-1')->sole();
        self::assertSame($quote->id, $apolice->source_lead_quote_id);
        self::assertSame('Azos', $apolice->insurer_name);
        self::assertSame(['Morte qualquer causa', 'Doenças graves'], array_column($apolice->coverages, 'name'));

        $lead->refresh();
        self::assertSame(LeadStage::Converted, $lead->stage);
        self::assertStringContainsString('Apólice emitida', $lead->activities()->latest('occurred_at')->first()->description);
    }

    public function test_cada_cotacao_gera_uma_apolice_so_e_depois_some_da_lista(): void
    {
        [$consultor] = $this->consultorNoPerfilDoCliente();
        $lead = Lead::factory()->insurance()->create(['consultant_id' => $consultor->id]);
        $quote = LeadQuote::factory()->create(['lead_id' => $lead->id, 'status' => 'accepted']);

        $tela = Livewire::test(InsuranceIndex::class)->call('togglePolicyForm');
        self::assertCount(1, $tela->get('acceptedQuotes'));

        $tela->set('policySourceQuoteId', $quote->id)->set('policyStartDate', '2026-11-01')->call('savePolicy')->assertHasNoErrors();

        self::assertCount(0, Livewire::test(InsuranceIndex::class)->get('acceptedQuotes'));
        self::assertSame(1, InsurancePolicy::withoutProfileScope()->where('source_lead_quote_id', $quote->id)->count());
    }

    public function test_so_cotacao_aceita_e_do_proprio_profissional_aparece(): void
    {
        [$consultor] = $this->consultorNoPerfilDoCliente();
        $meu = Lead::factory()->insurance()->create(['consultant_id' => $consultor->id]);
        LeadQuote::factory()->create(['lead_id' => $meu->id, 'status' => 'sent']);
        LeadQuote::factory()->create(['lead_id' => $meu->id, 'status' => 'declined']);
        $alheia = LeadQuote::factory()->create([
            'lead_id' => Lead::factory()->insurance()->create(['consultant_id' => User::factory()->broker()->create()->id])->id,
            'status' => 'accepted',
        ]);

        $tela = Livewire::test(InsuranceIndex::class);
        self::assertCount(0, $tela->get('acceptedQuotes'));

        // Mesmo forjando o id da cotação de outro profissional, nada é ligado nem o lead dele muda.
        $tela->call('togglePolicyForm')->set('policySourceQuoteId', $alheia->id)->assertSet('policySourceQuoteId', '');
        $tela->set('policyInsurerName', 'Qualquer')->set('policyStartDate', '2026-11-01')->call('savePolicy');

        self::assertSame(0, InsurancePolicy::withoutProfileScope()->whereNotNull('source_lead_quote_id')->count());
        self::assertNotSame(LeadStage::Converted, $alheia->lead->fresh()->stage);
    }

    public function test_cliente_comum_nao_ve_a_escolha_de_cotacao(): void
    {
        $cliente = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $cliente->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $cliente->id]);
        $this->actingAs($cliente);
        app(ProfileContext::class)->set($perfil, $membro);

        Livewire::test(InsuranceIndex::class)->call('togglePolicyForm')->assertDontSee('Começar de uma cotação aceita');
    }

    public function test_apolice_sem_cotacao_de_origem_continua_igual(): void
    {
        $this->consultorNoPerfilDoCliente();

        Livewire::test(InsuranceIndex::class)
            ->call('togglePolicyForm')
            ->set('policyInsurerName', 'Porto Seguro')
            ->set('policyMonthlyPremium', '90')
            ->set('policyStartDate', '2026-11-01')
            ->call('savePolicy')
            ->assertHasNoErrors();

        self::assertNull(InsurancePolicy::withoutProfileScope()->where('insurer_name', 'Porto Seguro')->sole()->source_lead_quote_id);
    }

    public function test_marcar_emitida_e_idempotente_e_reabre_lead_perdido(): void
    {
        $lead = $this->lead(['stage' => LeadStage::Lost, 'lost_at' => now()]);
        $quote = $this->quote($lead);
        $servico = app(LeadService::class);

        $servico->markIssued($lead, $quote, $this->corretor->id);
        $servico->markIssued($lead->fresh(), $quote, $this->corretor->id);

        $lead->refresh();
        self::assertSame(LeadStage::Converted, $lead->stage);
        self::assertNull($lead->lost_at);
        self::assertSame(1, $lead->activities()->count(), 'a anotação não se repete');
    }

    public function test_apagar_o_lead_nao_apaga_a_apolice_que_nasceu_dele(): void
    {
        [$consultor] = $this->consultorNoPerfilDoCliente();
        $lead = Lead::factory()->insurance()->create(['consultant_id' => $consultor->id]);
        $quote = LeadQuote::factory()->create(['lead_id' => $lead->id, 'status' => 'accepted']);

        Livewire::test(InsuranceIndex::class)->call('togglePolicyForm')->set('policySourceQuoteId', $quote->id)->set('policyStartDate', '2026-11-01')->call('savePolicy');

        app(LeadService::class)->delete($lead);

        $apolice = InsurancePolicy::withoutProfileScope()->sole();
        self::assertNull($apolice->source_lead_quote_id);
    }
}
