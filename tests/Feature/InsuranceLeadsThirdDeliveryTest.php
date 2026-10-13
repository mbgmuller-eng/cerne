<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\InsuranceType;
use App\Enums\LeadKind;
use App\Enums\LeadLostReason;
use App\Enums\LeadSource;
use App\Enums\LeadStage;
use App\Livewire\Consultant\LeadMetrics;
use App\Livewire\Consultant\LeadsIndex;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\InsurancePolicy;
use App\Models\Lead;
use App\Models\LeadQuote;
use App\Models\LeadSuggestionDismissal;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\LeadAutomationService;
use App\Services\LeadMetricsService;
use App\Services\LeadService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Terceira entrega dos leads de seguros: leads de renovação criados pela carteira, sugestões de venda cruzada e métricas
 * do funil.
 */
class InsuranceLeadsThirdDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: FinancialProfile, 2: User} profissional, perfil do cliente e o cliente */
    private function clienteVinculado(?User $profissional = null, string $nome = 'Ana Souza'): array
    {
        $cliente = User::factory()->create(['name' => $nome, 'email' => strtolower(str_replace(' ', '.', $nome)).'@example.com']);
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $cliente->id]);
        ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $cliente->id, 'name' => $nome]);

        $profissional ??= User::factory()->consultant()->create();
        ConsultantClient::factory()->create(['consultant_id' => $profissional->id, 'client_id' => $cliente->id, 'status' => ConsultantClientStatus::Active]);

        return [$profissional, $perfil, $cliente];
    }

    private function apolice(FinancialProfile $perfil, array $sobre = []): InsurancePolicy
    {
        return InsurancePolicy::factory()->for($perfil, 'profile')->create($sobre + [
            'insurance_type' => InsuranceType::Carro,
            'insurer_name' => 'Porto Seguro',
            'monthly_premium' => '300.00',
            'coverage_amount' => '60000.00',
            'start_date' => '2025-03-01',
            'expiry_date' => null,
            'is_active' => true,
        ]);
    }

    // ---- renovação

    public function test_apolice_que_vence_em_breve_vira_lead_de_renovacao_uma_vez_so(): void
    {
        Carbon::setTestNow('2026-10-20');
        [$consultor, $perfil, $cliente] = $this->clienteVinculado();
        $apolice = $this->apolice($perfil, ['expiry_date' => '2026-11-20']);

        $servico = app(LeadAutomationService::class);
        self::assertSame(1, $servico->createRenewalLeads($consultor));
        self::assertSame(0, $servico->createRenewalLeads($consultor), 'rodar de novo não duplica');
        self::assertSame(0, $servico->createRenewalLeadsForAll(), 'nem pelo cron da plataforma');

        $lead = Lead::query()->sole();
        self::assertSame($consultor->id, $lead->consultant_id);
        self::assertSame(LeadKind::Insurance, $lead->kind);
        self::assertSame(LeadSource::Renewal, $lead->source);
        self::assertSame(LeadStage::NewContact, $lead->stage);
        self::assertSame($perfil->id, $lead->client_profile_id);
        self::assertSame($apolice->id, $lead->source_policy_id);
        self::assertSame($cliente->email, $lead->email);
        self::assertSame(['carro'], $lead->interests);
        self::assertStringContainsString('Porto Seguro', $lead->source_detail);
        self::assertStringContainsString('20/11/2026', $lead->notes);
        self::assertTrue($lead->isClientDeal());
        // O primeiro contato fica 30 dias antes do vencimento: 21/10.
        self::assertSame('2026-10-21', $lead->next_action_at->toDateString());
    }

    public function test_apolice_ainda_longe_do_vencimento_nao_gera_lead(): void
    {
        Carbon::setTestNow('2026-10-20');
        [$consultor, $perfil] = $this->clienteVinculado();
        $this->apolice($perfil, ['expiry_date' => '2027-03-01']);

        self::assertSame(0, app(LeadAutomationService::class)->createRenewalLeads($consultor));
    }

    public function test_lead_atrasado_ja_nasce_com_contato_para_hoje(): void
    {
        Carbon::setTestNow('2026-10-20');
        [$consultor, $perfil] = $this->clienteVinculado();
        $this->apolice($perfil, ['expiry_date' => '2026-10-25']);

        app(LeadAutomationService::class)->createRenewalLeads($consultor);

        self::assertSame('2026-10-20', Lead::query()->sole()->next_action_at->toDateString());
    }

    public function test_sem_vencimento_vale_o_aniversario_da_apolice(): void
    {
        Carbon::setTestNow('2026-02-10');
        [$consultor, $perfil] = $this->clienteVinculado();
        // Vida vitalícia: sem vencimento; completa mais um ano em 01/03/2026.
        $this->apolice($perfil, ['insurance_type' => InsuranceType::Vida, 'start_date' => '2024-03-01', 'expiry_date' => null]);

        self::assertSame(1, app(LeadAutomationService::class)->createRenewalLeads($consultor));
        self::assertStringContainsString('mais um ano', Lead::query()->sole()->notes);
    }

    public function test_apolice_com_vencimento_nao_gera_um_segundo_lead_pelo_aniversario(): void
    {
        Carbon::setTestNow('2026-02-10');
        [$consultor, $perfil] = $this->clienteVinculado();
        // Aniversário em 01/03 está na janela, mas o que vale é o vencimento (longe).
        $this->apolice($perfil, ['start_date' => '2025-03-01', 'expiry_date' => '2027-03-01']);

        self::assertSame(0, app(LeadAutomationService::class)->createRenewalLeads($consultor));
    }

    public function test_ja_ha_lead_aberto_do_mesmo_cliente_e_tipo_nao_duplica(): void
    {
        Carbon::setTestNow('2026-10-20');
        [$consultor, $perfil] = $this->clienteVinculado();
        $this->apolice($perfil, ['expiry_date' => '2026-11-10']);
        Lead::factory()->insurance()->create(['consultant_id' => $consultor->id, 'client_profile_id' => $perfil->id, 'interests' => ['carro']]);

        self::assertSame(0, app(LeadAutomationService::class)->createRenewalLeads($consultor));
    }

    public function test_corretor_so_recebe_lead_das_apolices_compartilhadas_com_ele(): void
    {
        Carbon::setTestNow('2026-10-20');
        $corretor = User::factory()->broker()->create();
        [, $perfil] = $this->clienteVinculado($corretor);
        $this->apolice($perfil, ['expiry_date' => '2026-11-10', 'broker_id' => null, 'insurer_name' => 'De outro corretor']);
        $minha = $this->apolice($perfil, ['expiry_date' => '2026-11-12', 'broker_id' => $corretor->id, 'insurer_name' => 'Minha', 'insurance_type' => InsuranceType::Vida]);

        self::assertSame(1, app(LeadAutomationService::class)->createRenewalLeads($corretor));
        self::assertSame($minha->id, Lead::query()->sole()->source_policy_id);
    }

    public function test_consultor_e_corretor_do_mesmo_cliente_cada_um_com_o_seu_lead(): void
    {
        Carbon::setTestNow('2026-10-20');
        [$consultor, $perfil, $cliente] = $this->clienteVinculado();
        $corretor = User::factory()->broker()->create();
        ConsultantClient::factory()->create(['consultant_id' => $corretor->id, 'client_id' => $cliente->id, 'status' => ConsultantClientStatus::Active]);
        $this->apolice($perfil, ['expiry_date' => '2026-11-10', 'broker_id' => $corretor->id]);

        self::assertSame(2, app(LeadAutomationService::class)->createRenewalLeadsForAll());
        self::assertEqualsCanonicalizing([$consultor->id, $corretor->id], Lead::query()->pluck('consultant_id')->all());
    }

    public function test_apolice_inativa_ou_de_cliente_desvinculado_nao_gera_lead(): void
    {
        Carbon::setTestNow('2026-10-20');
        [$consultor, $perfil, $cliente] = $this->clienteVinculado();
        $this->apolice($perfil, ['expiry_date' => '2026-11-10', 'is_active' => false]);
        ConsultantClient::query()->update(['status' => ConsultantClientStatus::Pending]);
        $this->apolice($perfil, ['expiry_date' => '2026-11-11']);

        self::assertSame(0, app(LeadAutomationService::class)->createRenewalLeadsForAll());
        self::assertSame(0, Lead::query()->count());
    }

    public function test_lead_de_cliente_se_fecha_sem_convite_e_converter_nao_faz_nada(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');
        [$consultor, $perfil] = $this->clienteVinculado();
        $this->apolice($perfil, ['expiry_date' => '2026-11-10']);
        app(LeadAutomationService::class)->createRenewalLeads($consultor);
        $lead = Lead::query()->sole();

        $this->actingAs($consultor);
        $tela = Livewire::test(LeadsIndex::class)->set('aba', 'seguros');

        $tela->assertSee('Já é cliente')->assertSee('Fechado')->assertDontSee('Converter');

        $tela->call('convertLead', $lead->id);
        self::assertSame(LeadStage::NewContact, $lead->fresh()->stage, 'não manda convite a quem já é cliente');

        $tela->call('closeDeal', $lead->id);
        $lead->refresh();
        self::assertSame(LeadStage::Converted, $lead->stage);
        self::assertSame('2026-10-20', $lead->converted_at->toDateString());
        self::assertSame('Negócio fechado.', $lead->activities()->sole()->description);
    }

    public function test_fechar_negocio_de_lead_comum_nao_faz_nada_porque_ele_precisa_do_convite(): void
    {
        $consultor = User::factory()->consultant()->create();
        $lead = Lead::factory()->insurance()->create(['consultant_id' => $consultor->id]);
        $this->actingAs($consultor);

        Livewire::test(LeadsIndex::class)->set('aba', 'seguros')->call('closeDeal', $lead->id);

        self::assertSame(LeadStage::NewContact, $lead->fresh()->stage);
    }

    public function test_so_o_dono_fecha_o_lead(): void
    {
        $dono = User::factory()->consultant()->create();
        $outro = User::factory()->consultant()->create();
        $lead = Lead::factory()->insurance()->create(['consultant_id' => $dono->id, 'client_profile_id' => FinancialProfile::factory()->create()->id]);
        $this->actingAs($outro);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::test(LeadsIndex::class)->call('closeDeal', $lead->id);
    }

    public function test_excluir_o_cliente_ou_a_apolice_nao_apaga_o_lead(): void
    {
        Carbon::setTestNow('2026-10-20');
        [$consultor, $perfil] = $this->clienteVinculado();
        $apolice = $this->apolice($perfil, ['expiry_date' => '2026-11-10']);
        app(LeadAutomationService::class)->createRenewalLeads($consultor);

        $apolice->delete();
        self::assertNull(Lead::query()->sole()->source_policy_id);

        $perfil->delete();
        self::assertNull(Lead::query()->sole()->client_profile_id);
    }

    // ---- venda cruzada

    public function test_sugere_o_tipo_que_o_cliente_nao_tem_e_nao_o_que_ele_ja_tem(): void
    {
        [$consultor, $perfil] = $this->clienteVinculado();
        $this->apolice($perfil, ['insurance_type' => InsuranceType::Vida]);

        $sugestoes = app(LeadAutomationService::class)->crossSellSuggestions($consultor);

        self::assertEqualsCanonicalizing(['residencia', 'saude'], $sugestoes->map(fn ($s) => $s['type']->value)->all());
        self::assertSame('Ana Souza', $sugestoes->first()['client_name']);
        self::assertSame(1, $sugestoes->first()['policies']);
    }

    public function test_cliente_sem_nenhuma_apolice_tambem_recebe_sugestoes(): void
    {
        [$consultor] = $this->clienteVinculado();

        self::assertCount(3, app(LeadAutomationService::class)->crossSellSuggestions($consultor));
    }

    public function test_aceitar_a_sugestao_cria_o_lead_e_ela_some(): void
    {
        [$consultor, $perfil] = $this->clienteVinculado();
        $this->actingAs($consultor);

        Livewire::test(LeadsIndex::class)
            ->set('aba', 'seguros')
            ->assertSee('Oportunidades na carteira')
            ->call('acceptSuggestion', $perfil->id, 'vida');

        $lead = Lead::query()->sole();
        self::assertSame(LeadSource::CrossSell, $lead->source);
        self::assertSame(['vida'], $lead->interests);
        self::assertSame($perfil->id, $lead->client_profile_id);
        self::assertSame('cross:'.$perfil->id.':vida', $lead->automation_key);

        $restantes = app(LeadAutomationService::class)->crossSellSuggestions($consultor);
        self::assertEqualsCanonicalizing(['residencia', 'saude'], $restantes->map(fn ($s) => $s['type']->value)->all());

        // Aceitar de novo a mesma sugestão não cria um segundo lead.
        Livewire::test(LeadsIndex::class)->set('aba', 'seguros')->call('acceptSuggestion', $perfil->id, 'vida');
        self::assertSame(1, Lead::query()->count());
    }

    public function test_dispensar_a_sugestao_faz_ela_nao_voltar(): void
    {
        [$consultor, $perfil] = $this->clienteVinculado();
        $this->actingAs($consultor);

        Livewire::test(LeadsIndex::class)->set('aba', 'seguros')->call('dismissSuggestion', $perfil->id, 'saude');

        self::assertSame(1, LeadSuggestionDismissal::query()->count());
        self::assertNotContains('saude', app(LeadAutomationService::class)->crossSellSuggestions($consultor)->map(fn ($s) => $s['type']->value)->all());
        self::assertSame(0, Lead::query()->count());
    }

    public function test_so_as_sugestoes_do_proprio_profissional_valem(): void
    {
        [, $perfilDeOutro] = $this->clienteVinculado();
        $estranho = User::factory()->consultant()->create();
        $this->actingAs($estranho);

        Livewire::test(LeadsIndex::class)->set('aba', 'seguros')
            ->call('acceptSuggestion', $perfilDeOutro->id, 'vida')
            ->call('dismissSuggestion', $perfilDeOutro->id, 'vida')
            ->call('acceptSuggestion', $perfilDeOutro->id, 'tipo-inexistente');

        self::assertSame(0, Lead::query()->count());
        self::assertSame(0, LeadSuggestionDismissal::query()->count());
    }

    public function test_corretor_so_conta_o_que_enxerga_e_as_sugestoes_aparecem_so_no_funil_de_seguros(): void
    {
        $corretor = User::factory()->broker()->create();
        [, $perfil] = $this->clienteVinculado($corretor);
        // Apólice de vida de outro corretor: o corretor não a enxerga, então o cliente "não tem vida" para ele.
        $this->apolice($perfil, ['insurance_type' => InsuranceType::Vida, 'broker_id' => null]);

        $tipos = app(LeadAutomationService::class)->crossSellSuggestions($corretor)->map(fn ($s) => $s['type']->value)->all();
        self::assertContains('vida', $tipos);

        $consultor = User::factory()->consultant()->create();
        $this->clienteVinculado($consultor, 'Bruno Lima');
        $this->actingAs($consultor);
        Livewire::test(LeadsIndex::class)->assertSet('aba', 'consultoria')->assertDontSee('Oportunidades na carteira');
    }

    public function test_sugestao_nao_aparece_se_ja_ha_lead_aberto_do_cliente_naquele_tipo(): void
    {
        [$consultor, $perfil] = $this->clienteVinculado();
        Lead::factory()->insurance()->create(['consultant_id' => $consultor->id, 'client_profile_id' => $perfil->id, 'interests' => ['vida']]);

        self::assertNotContains('vida', app(LeadAutomationService::class)->crossSellSuggestions($consultor)->map(fn ($s) => $s['type']->value)->all());
    }

    // ---- métricas

    private function leadEm(User $dono, string $criado, array $sobre = []): Lead
    {
        $lead = Lead::factory()->insurance()->create($sobre + ['consultant_id' => $dono->id]);
        $lead->forceFill(['created_at' => $criado])->save();

        return $lead;
    }

    public function test_metricas_do_funil(): void
    {
        Carbon::setTestNow('2026-10-20');
        $corretor = User::factory()->broker()->create();

        // Emitido em 10 dias, indicação, com cotação aceita: 100/mês (1.200/ano), comissão 30% = 360.
        $a = $this->leadEm($corretor, '2026-09-01', ['stage' => LeadStage::Converted, 'converted_at' => '2026-09-11', 'source' => LeadSource::ClientReferral]);
        LeadQuote::factory()->create(['lead_id' => $a->id, 'premium' => '100.00', 'payment_frequency' => 'monthly', 'status' => 'accepted', 'commission_type' => 'percent', 'commission_percent' => '30']);
        LeadQuote::factory()->create(['lead_id' => $a->id, 'premium' => '50.00', 'status' => 'declined']);
        // Emitido em 20 dias, indicação, comissão em valor fixo de 500, prêmio anual 2.000.
        $b = $this->leadEm($corretor, '2026-09-10', ['stage' => LeadStage::Converted, 'converted_at' => '2026-09-30', 'source' => LeadSource::ClientReferral]);
        LeadQuote::factory()->create(['lead_id' => $b->id, 'premium' => '2000.00', 'payment_frequency' => 'annual', 'status' => 'accepted', 'commission_type' => 'amount', 'commission_amount' => '500.00']);
        // Emitido sem cotação aceita (renovação).
        $this->leadEm($corretor, '2026-09-15', ['stage' => LeadStage::Converted, 'converted_at' => '2026-09-18', 'source' => LeadSource::Renewal]);
        // Perdidos por preço e por falta de retorno; um em andamento.
        $this->leadEm($corretor, '2026-09-20', ['stage' => LeadStage::Lost, 'lost_category' => LeadLostReason::Price, 'source' => LeadSource::Website]);
        $this->leadEm($corretor, '2026-09-21', ['stage' => LeadStage::Lost, 'lost_category' => LeadLostReason::Price, 'source' => LeadSource::Website]);
        $this->leadEm($corretor, '2026-09-22', ['stage' => LeadStage::Lost, 'lost_category' => LeadLostReason::NoResponse, 'source' => LeadSource::ClientReferral]);
        $this->leadEm($corretor, '2026-10-05', ['stage' => LeadStage::Quoting, 'source' => LeadSource::Website]);
        // Fora do período e de outro profissional não contam.
        $this->leadEm($corretor, '2025-01-01', ['stage' => LeadStage::Converted, 'converted_at' => '2025-01-10']);
        $outro = User::factory()->broker()->create();
        $this->leadEm($outro, '2026-09-01', ['stage' => LeadStage::Converted, 'converted_at' => '2026-09-05']);

        $m = app(LeadMetricsService::class)->funnel($corretor, LeadKind::Insurance, CarbonImmutable::parse('2026-07-22'), CarbonImmutable::parse('2026-10-20'));

        self::assertSame(7, $m['entered']);
        self::assertSame(3, $m['issued']);
        self::assertSame(3, $m['lost']);
        self::assertSame(1, $m['open']);
        self::assertSame('50.0', $m['conversion'], '3 fecharam de 6 decididos');
        self::assertSame('11.0', $m['avg_days_to_issue'], '(10 + 20 + 3) / 3');
        self::assertSame('3200.00', $m['premium'], '1.200 + 2.000; a cotação recusada não entra');
        self::assertSame('860.00', $m['commission'], '30% de 1.200 + 500 fixos');
        self::assertSame(1, $m['issued_without_quote']);

        $porOrigem = collect($m['by_source'])->keyBy(fn ($l) => $l['source']->value);
        self::assertSame(['entered' => 3, 'issued' => 2, 'lost' => 1, 'open' => 0, 'conversion' => '66.7'], collect($porOrigem['client_referral'])->except('source')->all());
        self::assertSame(['entered' => 3, 'issued' => 0, 'lost' => 2, 'open' => 1, 'conversion' => '0.0'], collect($porOrigem['website'])->except('source')->all());
        self::assertSame('100.0', $porOrigem['renewal']['conversion']);

        self::assertSame([['reason' => LeadLostReason::Price, 'total' => 2], ['reason' => LeadLostReason::NoResponse, 'total' => 1]], $m['lost_reasons']);
    }

    public function test_sem_decididos_a_conversao_e_nula_em_vez_de_zero(): void
    {
        $corretor = User::factory()->broker()->create();
        $m = app(LeadMetricsService::class)->funnel($corretor, LeadKind::Insurance, CarbonImmutable::now()->subDays(30), CarbonImmutable::now());

        self::assertSame(0, $m['entered']);
        self::assertNull($m['conversion']);
        self::assertNull($m['avg_days_to_issue']);
        self::assertSame('0.00', $m['premium']);
        self::assertSame([], $m['by_source']);
    }

    public function test_o_funil_de_consultoria_e_o_de_seguros_nao_se_misturam(): void
    {
        $consultor = User::factory()->consultant()->create();
        Lead::factory()->create(['consultant_id' => $consultor->id]);
        Lead::factory()->insurance()->create(['consultant_id' => $consultor->id]);

        $servico = app(LeadMetricsService::class);
        $de = CarbonImmutable::now()->subDay();
        $ate = CarbonImmutable::now()->addDay();

        self::assertSame(1, $servico->funnel($consultor, LeadKind::Consulting, $de, $ate)['entered']);
        self::assertSame(1, $servico->funnel($consultor, LeadKind::Insurance, $de, $ate)['entered']);
    }

    public function test_leads_convertidos_antes_ganham_a_data_de_conversao_aproximada_pela_ultima_alteracao(): void
    {
        $consultor = User::factory()->consultant()->create();
        $lead = Lead::factory()->create(['consultant_id' => $consultor->id, 'stage' => LeadStage::Converted]);

        // O campo é novo: o convite enviado por convert() já o preenche.
        $novo = Lead::factory()->create(['consultant_id' => $consultor->id, 'email' => 'x@example.com']);
        app(LeadService::class)->convert($novo, $consultor);

        self::assertNotNull($novo->fresh()->converted_at);
        self::assertNull($lead->fresh()->converted_at, 'só a migração preenche os antigos; o factory não');
    }

    // ---- a tela de métricas

    public function test_tela_de_metricas_mostra_os_numeros_e_respeita_o_periodo(): void
    {
        Carbon::setTestNow('2026-10-20');
        $corretor = User::factory()->broker()->create();
        $this->leadEm($corretor, '2026-10-01', ['stage' => LeadStage::Lost, 'lost_category' => LeadLostReason::Price, 'source' => LeadSource::Website]);
        $this->leadEm($corretor, '2026-04-01', ['stage' => LeadStage::Lost, 'lost_category' => LeadLostReason::Competitor, 'source' => LeadSource::Event]);
        $this->actingAs($corretor);

        Livewire::test(LeadMetrics::class)
            ->assertSet('aba', 'seguros')
            ->assertSee('Métricas dos leads')
            ->assertSee('Site')
            ->assertSee('Preço')
            ->assertDontSee('Evento')
            ->set('periodo', '365')
            ->assertSee('Evento')
            ->assertSee('Fechou com outro');
    }

    public function test_periodo_invalido_volta_ao_padrao_e_corretor_nao_troca_de_funil(): void
    {
        $corretor = User::factory()->broker()->create();
        $this->actingAs($corretor);

        Livewire::withQueryParams(['periodo' => 'sempre', 'aba' => 'consultoria'])->test(LeadMetrics::class)
            ->assertSet('periodo', '90')
            ->assertSet('aba', 'seguros')
            ->call('setAba', 'consultoria')
            ->assertSet('aba', 'seguros');
    }

    public function test_consultor_alterna_entre_os_funis_e_a_tela_de_leads_tem_o_link(): void
    {
        $consultor = User::factory()->consultant()->create();
        $this->actingAs($consultor);

        Livewire::test(LeadMetrics::class)->assertSet('aba', 'consultoria')->call('setAba', 'seguros')->assertSet('aba', 'seguros')->assertSee('Prêmio emitido');
        Livewire::test(LeadMetrics::class)->assertDontSee('Prêmio emitido');
        Livewire::test(LeadsIndex::class)->assertSee(route('consultant.leads.metrics', ['aba' => 'consultoria']), false);
    }

    public function test_cliente_comum_nao_abre_as_metricas(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::test(LeadMetrics::class)->assertStatus(403);

    }

    public function test_metricas_so_contam_os_leads_do_proprio_profissional(): void
    {
        $meu = User::factory()->broker()->create();
        $outro = User::factory()->broker()->create();
        $this->leadEm($outro, now()->subDays(2)->toDateString(), ['stage' => LeadStage::Lost, 'lost_category' => LeadLostReason::Price]);
        $this->actingAs($meu);

        Livewire::test(LeadMetrics::class)->assertSee('Nenhum contato entrou neste período.')->assertSee('Nenhum contato perdido neste período.');
    }
}