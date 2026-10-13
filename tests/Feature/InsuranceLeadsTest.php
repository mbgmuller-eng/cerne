<?php

namespace Tests\Feature;

use App\Enums\LeadKind;
use App\Enums\LeadLostReason;
use App\Enums\LeadQuoteStatus;
use App\Enums\LeadSource;
use App\Enums\LeadStage;
use App\Livewire\Consultant\ImportantDates;
use App\Livewire\Consultant\LeadsIndex;
use App\Models\Lead;
use App\Models\LeadAttachment;
use App\Models\LeadQuote;
use App\Models\User;
use App\Services\ImportantDatesService;
use App\Services\LeadService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Funil de seguros nos leads: interesses e dados para cotar, cotações com prêmio, validade e simulação em PDF, etapas
 * próprias, motivo de perda categorizado, valor do funil e retenção dos arquivos.
 */
class InsuranceLeadsTest extends TestCase
{
    use RefreshDatabase;

    private User $corretor;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('cerne.lead_files.disk'));

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

    // ---- cadastro do lead de seguros

    public function test_corretor_cadastra_lead_de_seguros_com_interesses_dados_para_cotar_e_origem(): void
    {
        $this->actingAs($this->corretor);

        Livewire::test(LeadsIndex::class)
            ->assertSet('aba', 'seguros')
            ->call('toggleLeadForm')
            ->assertSet('leadKind', 'insurance')
            ->set('leadName', 'Fernanda Lima')
            ->set('leadPhone', '41999990000')
            ->set('leadBirthDate', '1985-03-10')
            ->set('leadSource', 'client_referral')
            ->set('leadSourceDetail', 'Indicada pela Ana Souza')
            ->set('leadInterests', ['vida', 'carro'])
            ->set('leadQuoteData', ['vida' => ['fuma' => 'nao', 'renda' => '8000', 'invasor' => 'x'], 'saude' => ['vidas' => '4']])
            ->call('saveLead')
            ->assertHasNoErrors();

        $lead = Lead::query()->sole();
        self::assertSame(LeadKind::Insurance, $lead->kind);
        self::assertSame(['vida', 'carro'], $lead->interests);
        self::assertSame('1985-03-10', $lead->birth_date->toDateString());
        self::assertSame(LeadSource::ClientReferral, $lead->source);
        self::assertSame('Indicada pela Ana Souza', $lead->source_detail);
        // Só o que é dos tipos escolhidos e de campo conhecido: nada de chave solta nem dado de tipo não marcado.
        self::assertSame(['vida' => ['fuma' => 'nao', 'renda' => '8000']], $lead->quote_data);
    }

    public function test_corretor_nao_cria_lead_de_consultoria(): void
    {
        $this->actingAs($this->corretor);

        Livewire::test(LeadsIndex::class)
            ->call('toggleLeadForm')
            ->set('leadKind', 'consulting')
            ->set('leadName', 'Fulano')
            ->call('saveLead')
            ->assertHasErrors('leadKind');

        self::assertSame(0, Lead::query()->count());
    }

    public function test_interesse_invalido_e_recusado(): void
    {
        $this->actingAs($this->corretor);

        Livewire::test(LeadsIndex::class)
            ->call('toggleLeadForm')
            ->set('leadName', 'Fulano')
            ->set('leadInterests', ['nao-existe'])
            ->call('saveLead')
            ->assertHasErrors('leadInterests.0');
    }

    public function test_nascimento_no_futuro_e_origem_desconhecida_sao_recusados(): void
    {
        $this->actingAs($this->corretor);

        Livewire::test(LeadsIndex::class)
            ->call('toggleLeadForm')
            ->set('leadName', 'Fulano')
            ->set('leadBirthDate', now()->addYear()->toDateString())
            ->set('leadSource', 'cartomante')
            ->call('saveLead')
            ->assertHasErrors(['leadBirthDate', 'leadSource']);
    }

    public function test_consultor_escolhe_o_funil_e_o_contato_nasce_no_que_esta_aberto(): void
    {
        $consultor = User::factory()->consultant()->create();
        $this->actingAs($consultor);

        $tela = Livewire::test(LeadsIndex::class)->assertSet('aba', 'consultoria');
        $tela->call('toggleLeadForm')->assertSet('leadKind', 'consulting');

        $tela->call('toggleLeadForm')->call('setAba', 'seguros')->call('toggleLeadForm')->assertSet('leadKind', 'insurance');
    }

    // ---- funis e etapas

    public function test_cada_funil_tem_as_suas_etapas(): void
    {
        $consultor = User::factory()->consultant()->create();
        $this->actingAs($consultor);
        Lead::factory()->create(['consultant_id' => $consultor->id, 'name' => 'De consultoria']);
        Lead::factory()->insurance()->create(['consultant_id' => $consultor->id, 'name' => 'De seguros']);

        $consultoria = Livewire::test(LeadsIndex::class);
        self::assertSame(['De consultoria'], $consultoria->get('leadsByStage')->flatten(1)->pluck('name')->all());
        self::assertSame(
            [LeadStage::NewContact, LeadStage::MeetingScheduled, LeadStage::ProposalSent],
            $consultoria->viewData('openStages'),
        );

        $seguros = Livewire::test(LeadsIndex::class)->call('setAba', 'seguros');
        self::assertSame(['De seguros'], $seguros->get('leadsByStage')->flatten(1)->pluck('name')->all());
        self::assertSame(
            [LeadStage::NewContact, LeadStage::GatheringData, LeadStage::Quoting, LeadStage::QuoteSent, LeadStage::Negotiating, LeadStage::ProposalSent],
            $seguros->viewData('openStages'),
        );
    }

    public function test_lead_de_seguros_avanca_por_todas_as_etapas_e_para_na_ultima(): void
    {
        $lead = $this->lead();
        $this->actingAs($this->corretor);
        $tela = Livewire::test(LeadsIndex::class);

        $esperadas = [LeadStage::GatheringData, LeadStage::Quoting, LeadStage::QuoteSent, LeadStage::Negotiating, LeadStage::ProposalSent, LeadStage::ProposalSent];
        foreach ($esperadas as $etapa) {
            $tela->call('advanceStage', $lead->id);
            self::assertSame($etapa, $lead->fresh()->stage);
        }

        $tela->call('regressStage', $lead->id);
        self::assertSame(LeadStage::Negotiating, $lead->fresh()->stage);
    }

    public function test_converter_em_seguros_se_chama_emitida(): void
    {
        self::assertSame('Emitida', LeadStage::Converted->labelFor(LeadKind::Insurance));
        self::assertSame('Convertido', LeadStage::Converted->labelFor(LeadKind::Consulting));
    }

    public function test_abas_mostram_quantos_leads_em_andamento_ha_em_cada_funil(): void
    {
        $consultor = User::factory()->consultant()->create();
        Lead::factory()->count(2)->create(['consultant_id' => $consultor->id]);
        Lead::factory()->insurance()->create(['consultant_id' => $consultor->id]);
        Lead::factory()->insurance()->stage(LeadStage::Lost)->create(['consultant_id' => $consultor->id]);
        Lead::factory()->insurance()->create(['consultant_id' => User::factory()->consultant()->create()->id]);
        $this->actingAs($consultor);

        self::assertSame(['consultoria' => 2, 'seguros' => 1], Livewire::test(LeadsIndex::class)->viewData('abaCounts'));
    }

    // ---- perda

    public function test_perda_em_seguros_exige_a_categoria_e_guarda_quando_foi(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');
        $lead = $this->lead();
        $this->actingAs($this->corretor);

        Livewire::test(LeadsIndex::class)->call('toggleMarkLost', $lead->id)->call('markLost')->assertHasErrors('lostCategory');
        self::assertSame(LeadStage::NewContact, $lead->fresh()->stage);

        Livewire::test(LeadsIndex::class)
            ->call('toggleMarkLost', $lead->id)
            ->set('lostCategory', 'price')
            ->call('markLost')
            ->assertHasNoErrors();

        $lead->refresh();
        self::assertSame(LeadStage::Lost, $lead->stage);
        self::assertSame(LeadLostReason::Price, $lead->lost_category);
        self::assertNull($lead->lost_reason, 'a anotação é opcional em seguros');
        self::assertSame('2026-10-20', $lead->lost_at->toDateString());
    }

    public function test_perda_em_consultoria_continua_pedindo_o_motivo_escrito(): void
    {
        $consultor = User::factory()->consultant()->create();
        $lead = Lead::factory()->create(['consultant_id' => $consultor->id]);
        $this->actingAs($consultor);

        Livewire::test(LeadsIndex::class)->call('toggleMarkLost', $lead->id)->call('markLost')->assertHasErrors('lostReason');
    }

    // ---- cotações

    private function abrirCotacoes(Lead $lead)
    {
        $this->actingAs($this->corretor);

        return Livewire::test(LeadsIndex::class)->call('openQuotes', $lead->id)->assertSet('showQuotes', true);
    }

    public function test_cadastra_cotacao_com_premio_validade_comissao_e_protecoes(): void
    {
        $lead = $this->lead(['interests' => ['vida']]);

        $this->abrirCotacoes($lead)
            ->call('newQuote')
            ->assertSet('quoteType', 'vida')
            ->set('quoteInsurer', 'Azos')
            ->set('quoteProduct', 'Vida Individual')
            ->set('quotePremium', '141.40')
            ->set('quoteFrequency', 'monthly')
            ->set('quoteCommission', '30')
            ->set('quoteValidUntil', '2026-11-30')
            ->set('quoteCoverages', [
                ['name' => 'Doenças Graves 30', 'value' => '135000', 'deductible' => ''],
                ['name' => 'Morte', 'value' => '400000', 'deductible' => ''],
                ['name' => '', 'value' => '', 'deductible' => ''],
            ])
            ->call('saveQuote')
            ->assertHasNoErrors();

        $quote = LeadQuote::query()->sole();
        self::assertSame($lead->id, $quote->lead_id);
        self::assertSame('Azos', $quote->insurer_name);
        self::assertSame('141.40', $quote->premium);
        self::assertSame('30.00', $quote->commission_percent);
        self::assertSame(LeadQuoteStatus::Draft, $quote->status);
        // Vida segue o padrão do Cerne (mesma lista e ordem das apólices) e o capital principal é o de morte.
        self::assertSame(['Morte qualquer causa', 'Doenças graves'], array_column($quote->coverages, 'name'));
        self::assertSame('400000.00', $quote->coverage_amount);
    }

    public function test_calculos_de_premio_anual_mensal_e_comissao(): void
    {
        $lead = $this->lead();
        $mensal = $this->quote($lead, ['premium' => '141.40', 'payment_frequency' => 'monthly', 'commission_percent' => '30']);
        $anual = $this->quote($lead, ['premium' => '2492.80', 'payment_frequency' => 'annual', 'commission_percent' => '12.5']);
        $trimestral = $this->quote($lead, ['premium' => '300.00', 'payment_frequency' => 'quarterly', 'commission_percent' => null]);

        self::assertSame('1696.80', $mensal->annualPremium());
        self::assertSame('141.40', $mensal->monthlyPremium());
        self::assertSame('509.04', $mensal->expectedCommission(), '30% de 1.696,80');
        self::assertSame('2492.80', $anual->annualPremium());
        self::assertSame('207.73', $anual->monthlyPremium());
        self::assertSame('311.60', $anual->expectedCommission(), '12,5% de 2.492,80');
        self::assertSame('1200.00', $trimestral->annualPremium());
        self::assertNull($trimestral->expectedCommission());
    }

    public function test_cotacao_sem_premio_nao_quebra_nada(): void
    {
        $lead = $this->lead();
        $semPremio = $this->quote($lead, ['premium' => null]);

        self::assertNull($semPremio->annualPremium());
        self::assertNull($semPremio->monthlyPremium());
        self::assertNull($semPremio->expectedCommission());
    }

    public function test_validade_vencida_e_dias_para_vencer(): void
    {
        $hoje = CarbonImmutable::parse('2026-10-20');
        $lead = $this->lead();

        $vence = $this->quote($lead, ['valid_until' => '2026-10-23', 'status' => 'sent']);
        $venceHoje = $this->quote($lead, ['valid_until' => '2026-10-20']);
        $vencida = $this->quote($lead, ['valid_until' => '2026-10-19', 'status' => 'sent']);
        $aceitaVelha = $this->quote($lead, ['valid_until' => '2026-01-01', 'status' => 'accepted']);
        $semData = $this->quote($lead, ['valid_until' => null]);

        self::assertSame(3, $vence->daysToExpire($hoje));
        self::assertSame(0, $venceHoje->daysToExpire($hoje));
        self::assertTrue($vencida->isExpired($hoje));
        self::assertNull($vencida->daysToExpire($hoje));
        self::assertFalse($aceitaVelha->isExpired($hoje), 'cotação já aceita não "vence"');
        self::assertNull($semData->daysToExpire($hoje));
        self::assertFalse($semData->isExpired($hoje));
    }

    public function test_editar_e_mudar_a_situacao_da_cotacao(): void
    {
        $lead = $this->lead();
        $quote = $this->quote($lead, ['insurer_name' => 'Azos']);

        $tela = $this->abrirCotacoes($lead)
            ->call('editQuote', $quote->id)
            ->assertSet('quoteInsurer', 'Azos')
            ->set('quotePremium', '200.00')
            ->call('saveQuote')
            ->assertHasNoErrors();

        self::assertSame('200.00', $quote->fresh()->premium);
        self::assertSame(1, LeadQuote::query()->count(), 'editar não cria outra');

        $tela->call('setQuoteStatus', $quote->id, 'accepted');
        self::assertSame(LeadQuoteStatus::Accepted, $quote->fresh()->status);

        $tela->call('setQuoteStatus', $quote->id, 'inventada');
        self::assertSame(LeadQuoteStatus::Accepted, $quote->fresh()->status, 'situação desconhecida é ignorada');
    }

    public function test_campos_obrigatorios_e_limites_da_cotacao(): void
    {
        $lead = $this->lead();

        $this->abrirCotacoes($lead)
            ->call('newQuote')
            ->set('quotePremium', '-5')
            ->set('quoteCommission', '150')
            ->call('saveQuote')
            ->assertHasErrors(['quoteInsurer', 'quotePremium', 'quoteCommission']);

        self::assertSame(0, LeadQuote::query()->count());
    }

    public function test_excluir_cotacao_leva_os_arquivos_dela(): void
    {
        $lead = $this->lead();
        $quote = $this->quote($lead);
        $anexo = app(LeadService::class)->attachFile($lead, UploadedFile::fake()->create('simulacao.pdf', 100, 'application/pdf'), $quote, $this->corretor->id);
        Storage::disk(config('cerne.lead_files.disk'))->assertExists($anexo->storage_path);

        $this->abrirCotacoes($lead)->call('deleteQuote', $quote->id);

        self::assertSame(0, LeadQuote::query()->count());
        self::assertSame(0, LeadAttachment::query()->count());
        Storage::disk(config('cerne.lead_files.disk'))->assertMissing($anexo->storage_path);
    }

    // ---- o valor do funil

    public function test_a_cotacao_que_representa_o_lead_e_a_aceita_ou_a_mais_barata_ainda_valida(): void
    {
        $hoje = CarbonImmutable::parse('2026-10-20');
        $lead = $this->lead();
        $this->quote($lead, ['insurer_name' => 'Cara', 'premium' => '300.00']);
        $barata = $this->quote($lead, ['insurer_name' => 'Barata', 'premium' => '120.00']);
        $this->quote($lead, ['insurer_name' => 'Vencida barata', 'premium' => '50.00', 'status' => 'sent', 'valid_until' => '2026-10-01']);
        $this->quote($lead, ['insurer_name' => 'Recusada barata', 'premium' => '40.00', 'status' => 'declined']);

        self::assertSame($barata->id, $lead->fresh('quotes')->estimatedQuote($hoje)->id);

        $aceita = $this->quote($lead, ['insurer_name' => 'Aceita', 'premium' => '250.00', 'status' => 'accepted']);

        self::assertSame($aceita->id, $lead->fresh('quotes')->estimatedQuote($hoje)->id, 'a aceita vale mais que a mais barata');
    }

    public function test_valor_do_funil_soma_um_prêmio_por_lead_e_conta_os_sem_cotacao(): void
    {
        $hoje = CarbonImmutable::parse('2026-10-20');
        $um = $this->lead(['name' => 'Um']);
        $this->quote($um, ['premium' => '100.00', 'commission_percent' => '30']);
        $this->quote($um, ['premium' => '150.00', 'commission_percent' => '30']);
        $dois = $this->lead(['name' => 'Dois']);
        $this->quote($dois, ['premium' => '1200.00', 'payment_frequency' => 'annual', 'commission_percent' => '10']);
        $this->lead(['name' => 'Sem cotação']);
        $semPremio = $this->lead(['name' => 'Cotação sem prêmio']);
        $this->quote($semPremio, ['premium' => null]);

        $leads = Lead::query()->with('quotes')->get();
        $funil = app(LeadService::class)->funnelValue($leads, $hoje);

        // Um: a mais barata (100/mês = 1.200/ano, 30% = 360). Dois: 1.200/ano, 10% = 120.
        self::assertSame('2400.00', $funil['premio_anual']);
        self::assertSame('480.00', $funil['comissao']);
        self::assertSame(2, $funil['com_cotacao']);
        self::assertSame(2, $funil['sem_cotacao']);
    }

    public function test_a_tela_mostra_o_valor_do_funil_so_na_aba_de_seguros(): void
    {
        $lead = $this->lead();
        $this->quote($lead, ['premium' => '100.00']);

        $this->actingAs($this->corretor);
        Livewire::test(LeadsIndex::class)->assertSee('Prêmio previsto no funil')->assertSee('R$ 1.200,00');

        $consultor = User::factory()->consultant()->create();
        $this->actingAs($consultor);
        Livewire::test(LeadsIndex::class)->assertDontSee('Prêmio previsto no funil');
    }

    // ---- anexos

    public function test_anexa_a_simulacao_a_uma_cotacao_pelo_componente(): void
    {
        $lead = $this->lead();
        $quote = $this->quote($lead);

        $this->abrirCotacoes($lead)
            ->call('chooseAttachTarget', $quote->id)
            ->set('anexo', UploadedFile::fake()->create('simulacao-azos.pdf', 200, 'application/pdf'))
            ->assertHasNoErrors();

        $anexo = LeadAttachment::query()->sole();
        self::assertSame($lead->id, $anexo->lead_id);
        self::assertSame($quote->id, $anexo->lead_quote_id);
        self::assertSame('simulacao-azos.pdf', $anexo->original_filename);
        self::assertSame('application/pdf', $anexo->mime_type);
        self::assertStringStartsWith('leads-anexos/'.$this->corretor->id.'/'.$lead->id.'/', $anexo->storage_path);
        self::assertStringNotContainsString('simulacao-azos', $anexo->storage_path, 'o nome no disco é gerado');
        Storage::disk(config('cerne.lead_files.disk'))->assertExists($anexo->storage_path);
    }

    public function test_anexa_arquivo_do_contato_em_geral(): void
    {
        $lead = $this->lead();

        $this->abrirCotacoes($lead)
            ->call('chooseAttachTarget', 'geral')
            ->set('anexo', UploadedFile::fake()->image('cnh.jpg'))
            ->assertHasNoErrors();

        self::assertNull(LeadAttachment::query()->sole()->lead_quote_id);
    }

    public function test_recusa_tipo_de_arquivo_e_tamanho_fora_do_limite(): void
    {
        $lead = $this->lead();

        $tela = $this->abrirCotacoes($lead)->call('chooseAttachTarget', 'geral');

        $tela->set('anexo', UploadedFile::fake()->create('planilha.xlsx', 50, 'application/vnd.ms-excel'))->assertHasErrors('anexo');
        $tela->set('anexo', UploadedFile::fake()->create('grande.pdf', config('cerne.lead_files.max_kb') + 1, 'application/pdf'))->assertHasErrors('anexo');

        self::assertSame(0, LeadAttachment::query()->count());
    }

    public function test_limite_de_arquivos_por_contato(): void
    {
        config(['cerne.lead_files.max_per_lead' => 2]);
        $lead = $this->lead();
        $servico = app(LeadService::class);
        $servico->attachFile($lead, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'));
        $servico->attachFile($lead, UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'));

        $this->abrirCotacoes($lead)
            ->call('chooseAttachTarget', 'geral')
            ->set('anexo', UploadedFile::fake()->create('c.pdf', 10, 'application/pdf'))
            ->assertHasErrors('anexo');

        self::assertSame(2, LeadAttachment::query()->count());
    }

    public function test_anexo_de_cotacao_de_outro_lead_e_recusado(): void
    {
        $lead = $this->lead();
        $outroLead = $this->lead();
        $cotacaoDeOutro = $this->quote($outroLead);

        $this->expectException(\InvalidArgumentException::class);

        app(LeadService::class)->attachFile($lead, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'), $cotacaoDeOutro);
    }

    public function test_remover_anexo_apaga_o_arquivo(): void
    {
        $lead = $this->lead();
        $anexo = app(LeadService::class)->attachFile($lead, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'));

        $this->abrirCotacoes($lead)->call('deleteAttachment', $anexo->id);

        self::assertSame(0, LeadAttachment::query()->count());
        Storage::disk(config('cerne.lead_files.disk'))->assertMissing($anexo->storage_path);
    }

    public function test_excluir_o_lead_apaga_os_arquivos_do_disco(): void
    {
        $lead = $this->lead();
        $quote = $this->quote($lead);
        $servico = app(LeadService::class);
        $geral = $servico->attachFile($lead, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'));
        $daCotacao = $servico->attachFile($lead, UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'), $quote);

        $this->actingAs($this->corretor);
        Livewire::test(LeadsIndex::class)->call('deleteLead', $lead->id);

        self::assertSame(0, Lead::query()->count());
        self::assertSame(0, LeadQuote::query()->count());
        Storage::disk(config('cerne.lead_files.disk'))->assertMissing($geral->storage_path);
        Storage::disk(config('cerne.lead_files.disk'))->assertMissing($daCotacao->storage_path);
    }

    // ---- quem abre o arquivo

    public function test_o_dono_abre_o_arquivo_e_o_navegador_nunca_guarda_copia(): void
    {
        $lead = $this->lead();
        $anexo = app(LeadService::class)->attachFile($lead, UploadedFile::fake()->createWithContent('s.pdf', '%PDF-1.4 simulacao'), null, $this->corretor->id);

        $resposta = $this->actingAs($this->corretor)->get(route('consultant.leads.attachment', $anexo))->assertOk();

        self::assertStringContainsString('no-store', $resposta->headers->get('Cache-Control'));
        self::assertStringContainsString('inline; filename="s.pdf"', $resposta->headers->get('Content-Disposition'));
        self::assertSame('%PDF-1.4 simulacao', $resposta->getContent());
    }

    public function test_outro_profissional_leva_404_e_sem_login_vai_para_a_entrada(): void
    {
        $lead = $this->lead();
        $anexo = app(LeadService::class)->attachFile($lead, UploadedFile::fake()->create('s.pdf', 10, 'application/pdf'));

        $this->actingAs(User::factory()->broker()->create())->get(route('consultant.leads.attachment', $anexo))->assertNotFound();
        $this->actingAs(User::factory()->consultant()->create())->get(route('consultant.leads.attachment', $anexo))->assertNotFound();

        auth()->logout();
        $this->get(route('consultant.leads.attachment', $anexo))->assertRedirect(route('login'));
    }

    public function test_lead_de_outro_profissional_nao_abre_cotacoes_nem_recebe_arquivo(): void
    {
        $leadDeOutro = Lead::factory()->insurance()->create(['consultant_id' => User::factory()->broker()->create()->id]);
        $this->actingAs($this->corretor);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::test(LeadsIndex::class)->call('openQuotes', $leadDeOutro->id);
    }

    public function test_nao_mexe_em_cotacao_de_outro_lead_nem_de_outro_profissional(): void
    {
        $meu = $this->lead();
        $cotacaoAlheia = $this->quote(Lead::factory()->insurance()->create(['consultant_id' => User::factory()->broker()->create()->id]));

        $tela = $this->abrirCotacoes($meu);

        foreach (['editQuote', 'deleteQuote'] as $acao) {
            try {
                $tela->call($acao, $cotacaoAlheia->id);
                self::fail("$acao deveria falhar");
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                // esperado
            }
        }

        self::assertNotNull($cotacaoAlheia->fresh());
    }

    // ---- retenção dos arquivos

    public function test_anexos_de_lead_perdido_ha_mais_de_12_meses_saem_do_servidor(): void
    {
        $servico = app(LeadService::class);
        $velho = $this->lead(['stage' => LeadStage::Lost, 'lost_at' => now()->subMonths(13)]);
        $recente = $this->lead(['stage' => LeadStage::Lost, 'lost_at' => now()->subMonths(11)]);
        $aberto = $this->lead(['lost_at' => null]);
        $legado = $this->lead(['stage' => LeadStage::Lost, 'lost_at' => null]);

        $arquivos = collect([$velho, $recente, $aberto, $legado])->mapWithKeys(
            fn (Lead $l) => [$l->id => $servico->attachFile($l, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'))]
        );

        self::assertSame(1, $servico->purgeExpiredAttachments());

        Storage::disk(config('cerne.lead_files.disk'))->assertMissing($arquivos[$velho->id]->storage_path);
        foreach ([$recente, $aberto, $legado] as $mantido) {
            Storage::disk(config('cerne.lead_files.disk'))->assertExists($arquivos[$mantido->id]->storage_path);
        }
        self::assertNotNull($velho->fresh(), 'o contato e as cotações ficam; só os arquivos saem');
    }

    public function test_a_limpeza_e_idempotente_e_o_comando_roda(): void
    {
        $servico = app(LeadService::class);
        $velho = $this->lead(['stage' => LeadStage::Lost, 'lost_at' => now()->subMonths(13)]);
        $servico->attachFile($velho, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'));

        $this->artisan('cerne:purge-lead-files')->expectsOutputToContain('1 anexo(s) apagado(s).')->assertSuccessful();
        $this->artisan('cerne:purge-lead-files')->expectsOutputToContain('Nenhum anexo vencido.')->assertSuccessful();
    }

    public function test_o_card_do_lead_perdido_avisa_quando_os_arquivos_saem(): void
    {
        $lead = $this->lead(['stage' => LeadStage::Lost, 'lost_at' => CarbonImmutable::parse('2026-03-10')]);
        app(LeadService::class)->attachFile($lead, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'));

        self::assertSame('2027-03-10', app(LeadService::class)->attachmentsExpireOn($lead->fresh())->toDateString());

        $this->actingAs($this->corretor);
        Livewire::test(LeadsIndex::class)->set('showClosed', true)->assertSee('saem do servidor em 10/03/2027');
    }

    // ---- avisos no card

    public function test_card_mostra_whatsapp_cotacoes_e_aviso_de_vencimento(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');
        CarbonImmutable::setTestNow('2026-10-20 10:00:00');
        $lead = $this->lead(['name' => 'Fernanda Lima', 'phone' => '(41) 99999-0000', 'interests' => ['vida'], 'source' => 'event', 'source_detail' => 'Feira 2026']);
        $this->quote($lead, ['premium' => '141.40', 'valid_until' => '2026-10-22', 'status' => 'sent']);

        $this->actingAs($this->corretor);
        Livewire::test(LeadsIndex::class)
            ->assertSee('Fernanda Lima')
            ->assertSee('https://wa.me/5541999990000', false)
            ->assertSee('1 cotação')
            ->assertSee('R$ 141,40')
            ->assertSee('cotação vence em 2 dias')
            ->assertSee('Origem: Evento')
            ->assertSee('Feira 2026');
    }

    public function test_whatsapp_so_com_telefone_que_parece_telefone(): void
    {
        self::assertSame('https://wa.me/5541999990000', $this->lead(['phone' => '41999990000'])->whatsappUrl());
        self::assertSame('https://wa.me/554133330000', $this->lead(['phone' => '4133330000'])->whatsappUrl());
        self::assertSame('https://wa.me/5541999990000', $this->lead(['phone' => '+55 41 99999-0000'])->whatsappUrl());
        self::assertNull($this->lead(['phone' => '12345'])->whatsappUrl());
        self::assertNull($this->lead(['phone' => null])->whatsappUrl());
    }

    public function test_aviso_de_lead_esfriando_depois_de_7_dias_sem_contato(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');
        CarbonImmutable::setTestNow('2026-10-20 10:00:00');
        $lead = $this->lead();
        $lead->forceFill(['created_at' => '2026-10-08 10:00:00'])->save();

        $this->actingAs($this->corretor);
        Livewire::test(LeadsIndex::class)->assertSee('sem contato há 12 dias');
    }

    // ---- aniversário de lead

    public function test_aniversario_de_lead_aparece_em_datas_importantes(): void
    {
        Carbon::setTestNow('2026-10-20');
        $proximo = $this->lead(['name' => 'Aniversariante', 'birth_date' => '1985-10-24']);
        $this->lead(['name' => 'Longe', 'birth_date' => '1985-03-01']);
        $this->lead(['name' => 'Sem data']);
        $this->lead(['name' => 'Já perdido', 'birth_date' => '1985-10-22', 'stage' => LeadStage::Lost]);
        Lead::factory()->insurance()->create(['consultant_id' => User::factory()->broker()->create()->id, 'name' => 'De outro', 'birth_date' => '1985-10-23']);

        $lista = app(ImportantDatesService::class)->upcomingLeadBirthdays($this->corretor, Carbon::now(), Carbon::now()->addDays(7));

        self::assertSame([$proximo->id], $lista->pluck('lead.id')->all());
        self::assertSame(41, $lista->first()['turning_age']);

        $this->actingAs($this->corretor);
        Livewire::test(ImportantDates::class)->assertSee('Contatos que ainda não são clientes')->assertSee('Aniversariante');
    }
}
