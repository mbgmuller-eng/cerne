<?php

namespace Tests\Feature;

use App\Enums\CommissionType;
use App\Enums\ConsultantClientStatus;
use App\Enums\IncomeSource;
use App\Enums\IncomeStatus;
use App\Enums\InsuranceType;
use App\Enums\MemberRole;
use App\Enums\PaymentFrequency;
use App\Livewire\Consultant\CommissionRules;
use App\Livewire\Consultant\ProfessionalIncome;
use App\Models\CommissionRule;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\IncomeEntry;
use App\Models\InsurancePolicy;
use App\Models\Insurer;
use App\Models\Lead;
use App\Models\LeadQuote;
use App\Models\PolicyCommission;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\InsuranceCommissionCalculator;
use App\Services\InsuranceCommissionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Comissão e agenciamento de seguros: a conta por parcela e por ano da apólice, as regras por seguradora, o vínculo das
 * apólices que o profissional vendeu e a previsão que cai no livro de recebimentos, sem duplicar e sem mexer no recebido.
 */
class InsuranceCommissionTest extends TestCase
{
    use RefreshDatabase;

    private User $corretor;

    private FinancialProfile $perfil;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-10 09:00:00'));

        $this->corretor = User::factory()->broker()->create();
        $this->perfil = $this->perfilDeCliente('Helen Ferry');
        Insurer::createApproved('Porto Seguro', '#0A6');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function perfilDeCliente(string $nome): FinancialProfile
    {
        $cliente = User::factory()->create(['name' => $nome]);
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $cliente->id]);
        ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $cliente->id, 'role' => MemberRole::Primary, 'name' => $nome]);

        return $perfil;
    }

    private function apolice(array $campos = []): InsurancePolicy
    {
        return InsurancePolicy::factory()->create(array_merge([
            'profile_id' => $this->perfil->id, 'broker_id' => $this->corretor->id, 'insurer_name' => 'Porto Seguro', 'insurance_type' => InsuranceType::Vida,
            'monthly_premium' => '200.00', 'payment_frequency' => PaymentFrequency::Monthly, 'start_date' => '2025-11-20', 'policy_number' => 'AP-1',
        ], $campos));
    }

    private function regra(array $campos = []): CommissionRule
    {
        return CommissionRule::factory()->create(array_merge(['professional_id' => $this->corretor->id], $campos));
    }

    private function service(): InsuranceCommissionService
    {
        return app(InsuranceCommissionService::class);
    }

    // --- Conta ---------------------------------------------------------------------------------------------------

    public function test_comissao_mensal_usa_o_percentual_do_ano_da_apolice(): void
    {
        $regra = $this->regra(['first_year_percent' => '30.000', 'renewal_percent' => '10.000']);
        // Começou em 20/11/2025: as parcelas até 20/10/2026 são do 1º ano; de 20/11/2026 em diante, 2º ano.
        $apolice = $this->apolice();

        $itens = collect(app(InsuranceCommissionCalculator::class)->entries($apolice, $regra))->keyBy('due_on');

        self::assertSame('20.00', $itens['2026-11-20']['amount'] ?? null, 'o 2º ano começa em 20/11/2026: 10% de 200');
        self::assertCount(13, $itens);
        self::assertSame('20.00', $itens['2027-10-20']['amount']);
    }

    public function test_renovacao_vazia_repete_o_percentual_do_primeiro_ano(): void
    {
        $regra = $this->regra(['first_year_percent' => '25.000', 'renewal_percent' => null]);

        $itens = app(InsuranceCommissionCalculator::class)->entries($this->apolice(), $regra);

        self::assertSame(['50.00'], array_values(array_unique(array_column($itens, 'amount'))));
    }

    public function test_apolice_em_seu_primeiro_ano_usa_o_percentual_do_primeiro_ano(): void
    {
        $regra = $this->regra(['first_year_percent' => '30.000', 'renewal_percent' => '10.000']);
        $apolice = $this->apolice(['start_date' => '2026-10-15']);

        $itens = collect(app(InsuranceCommissionCalculator::class)->entries($apolice, $regra))->keyBy('due_on');

        self::assertSame('60.00', $itens['2026-11-15']['amount']);
        self::assertSame('60.00', $itens['2027-09-15']['amount']);
        self::assertSame('20.00', $itens['2027-10-15']['amount']);
    }

    public function test_trimestral_e_anual_geram_uma_comissao_por_cobranca(): void
    {
        $regra = $this->regra(['first_year_percent' => '20.000']);
        $tri = $this->apolice(['payment_frequency' => PaymentFrequency::Quarterly, 'monthly_premium' => '600.00', 'start_date' => '2026-11-12']);
        $anual = $this->apolice(['payment_frequency' => PaymentFrequency::Annual, 'monthly_premium' => '2400.00', 'annual_premium' => '2400.00', 'start_date' => '2026-12-05', 'policy_number' => 'AP-2']);

        $calc = app(InsuranceCommissionCalculator::class);

        self::assertSame(['2026-11-12', '2027-02-12', '2027-05-12', '2027-08-12', '2027-11-12'], array_column($calc->entries($tri, $regra), 'due_on'));
        self::assertSame('120.00', $calc->entries($tri, $regra)[0]['amount']);
        self::assertSame(['2026-12-05'], array_column($calc->entries($anual, $regra), 'due_on'));
        self::assertSame('480.00', $calc->entries($anual, $regra)[0]['amount']);
    }

    public function test_prazo_de_pagamento_desloca_a_data_da_comissao(): void
    {
        $regra = $this->regra(['payout_delay_days' => 30]);

        $itens = app(InsuranceCommissionCalculator::class)->entries($this->apolice(['start_date' => '2026-11-05']), $regra);

        self::assertSame('2026-12-05', $itens[0]['due_on']);
    }

    public function test_apolice_sem_renovacao_automatica_nao_gera_depois_do_vencimento(): void
    {
        $regra = $this->regra();
        $apolice = $this->apolice(['start_date' => '2026-08-10', 'expiry_date' => '2027-01-10', 'auto_renews' => false]);

        $datas = array_column(app(InsuranceCommissionCalculator::class)->entries($apolice, $regra), 'due_on');

        self::assertSame('2027-01-10', end($datas));
    }

    public function test_agenciamento_so_para_apolice_nova_e_em_valor_fixo_ou_percentual(): void
    {
        $calc = app(InsuranceCommissionCalculator::class);
        $fixo = $this->regra(['agency_type' => CommissionType::Amount, 'agency_amount' => '300.00']);
        $nova = $this->apolice(['start_date' => '2026-11-12']);
        $antiga = $this->apolice(['start_date' => '2025-01-12', 'policy_number' => 'AP-9']);

        $agenciamento = fn ($itens) => collect($itens)->firstWhere('source', IncomeSource::InsuranceAgency);

        self::assertSame('300.00', $agenciamento($calc->entries($nova, $fixo))['amount']);
        self::assertNull($agenciamento($calc->entries($antiga, $fixo)), 'apólice antiga não gera fechamento retroativo');

        $percentual = $this->regra(['counterparty' => 'Outra', 'counterparty_key' => 'outra', 'agency_type' => CommissionType::Percent, 'agency_percent' => '10.000']);
        // 10% do prêmio do 1º ano (200 x 12 = 2.400).
        self::assertSame('240.00', $agenciamento($calc->entries($nova, $percentual))['amount']);
    }

    public function test_arredonda_meio_centavo_para_cima(): void
    {
        $regra = $this->regra(['first_year_percent' => '12.500']);

        $itens = app(InsuranceCommissionCalculator::class)->entries($this->apolice(['monthly_premium' => '100.04', 'start_date' => '2026-11-10']), $regra);

        // 12,5% de 100,04 = 12,505
        self::assertSame('12.51', $itens[0]['amount']);
    }

    // --- Regras ---------------------------------------------------------------------------------------------------

    public function test_regra_do_tipo_vale_mais_que_a_da_seguradora_inteira(): void
    {
        $this->regra(['first_year_percent' => '20.000', 'renewal_percent' => '20.000']);
        $this->regra(['insurance_type' => InsuranceType::Vida, 'type_key' => 'vida', 'first_year_percent' => '35.000', 'renewal_percent' => '35.000']);
        $vida = $this->apolice(['start_date' => '2026-11-10']);
        $carro = $this->apolice(['insurance_type' => InsuranceType::Carro, 'start_date' => '2026-11-10', 'policy_number' => 'AP-3']);
        PolicyCommission::factory()->create(['professional_id' => $this->corretor->id, 'insurance_policy_id' => $vida->id]);
        PolicyCommission::factory()->create(['professional_id' => $this->corretor->id, 'insurance_policy_id' => $carro->id]);

        $this->service()->reconcile($this->corretor);

        $pegar = fn (InsurancePolicy $p) => IncomeEntry::query()->where('origin_key', "policy:{$p->id}:commission:2026-11-10")->value('amount');
        self::assertSame('70.00', $pegar($vida));
        self::assertSame('40.00', $pegar($carro));
    }

    public function test_nome_da_seguradora_na_regra_casa_com_a_grafia_da_apolice(): void
    {
        $this->service()->saveRule($this->corretor, null, ['counterparty' => 'porto', 'insurance_type' => '', 'first_year_percent' => '30']);
        $apolice = $this->apolice(['insurer_name' => 'PORTO SEGURO', 'start_date' => '2026-11-10']);
        PolicyCommission::factory()->create(['professional_id' => $this->corretor->id, 'insurance_policy_id' => $apolice->id]);

        $this->service()->reconcile($this->corretor);

        self::assertSame('Porto Seguro', CommissionRule::query()->sole()->counterparty);
        self::assertTrue(IncomeEntry::query()->where('origin_key', "policy:{$apolice->id}:commission:2026-11-10")->exists());
    }

    public function test_nao_aceita_duas_regras_para_a_mesma_seguradora_e_tipo(): void
    {
        $this->regra();
        $this->actingAs($this->corretor);

        Livewire::test(CommissionRules::class)->call('openRuleForm')
            ->set('ruleCounterparty', 'Porto Seguro')->set('ruleFirstYear', '30')->call('saveRule')
            ->assertHasErrors('ruleCounterparty');

        self::assertSame(1, CommissionRule::query()->count());
    }

    // --- Vínculo das apólices ------------------------------------------------------------------------------------------

    public function test_corretor_recebe_o_vinculo_das_apolices_ligadas_a_ele_e_so_dessas(): void
    {
        $dele = $this->apolice();
        $deOutro = $this->apolice(['broker_id' => User::factory()->broker()->create()->id, 'policy_number' => 'AP-X']);
        $semCorretor = $this->apolice(['broker_id' => null, 'policy_number' => 'AP-Y']);

        self::assertSame(1, $this->service()->linkSoldPolicies($this->corretor));
        self::assertSame(0, $this->service()->linkSoldPolicies($this->corretor), 'idempotente');

        $ids = PolicyCommission::query()->where('professional_id', $this->corretor->id)->pluck('insurance_policy_id')->all();
        self::assertSame([$dele->id], $ids);
        self::assertNotContains($deOutro->id, $ids);
        self::assertNotContains($semCorretor->id, $ids);
    }

    public function test_consultor_recebe_o_vinculo_da_apolice_que_nasceu_de_uma_cotacao_de_lead_dele(): void
    {
        $consultor = User::factory()->consultant()->create();
        $lead = Lead::factory()->insurance()->create(['consultant_id' => $consultor->id]);
        $cotacao = LeadQuote::factory()->create(['lead_id' => $lead->id]);
        $leadDeOutro = Lead::factory()->insurance()->create(['consultant_id' => User::factory()->consultant()->create()->id]);
        $cotacaoDeOutro = LeadQuote::factory()->create(['lead_id' => $leadDeOutro->id]);

        $vendida = $this->apolice(['broker_id' => null, 'source_lead_quote_id' => $cotacao->id]);
        $this->apolice(['broker_id' => null, 'source_lead_quote_id' => $cotacaoDeOutro->id, 'policy_number' => 'AP-Z']);

        $this->service()->linkSoldPolicies($consultor);

        self::assertSame([$vendida->id], PolicyCommission::query()->where('professional_id', $consultor->id)->pluck('insurance_policy_id')->all());
    }

    public function test_consultor_so_inclui_apolice_de_cliente_ativo_dele(): void
    {
        $consultor = User::factory()->consultant()->create();
        $meu = $this->perfil;
        ConsultantClient::factory()->create(['consultant_id' => $consultor->id, 'client_id' => $meu->owner_user_id, 'status' => ConsultantClientStatus::Active, 'accepted_at' => now()]);
        $perfilAlheio = $this->perfilDeCliente('Cliente alheio');

        $minha = $this->apolice(['broker_id' => null]);
        $alheia = $this->apolice(['profile_id' => $perfilAlheio->id, 'broker_id' => null, 'policy_number' => 'AP-W']);

        self::assertSame([$minha->id], array_keys($this->service()->includablePolicies($consultor)));

        $this->service()->includePolicy($consultor, $minha->id);
        self::assertSame('manual', PolicyCommission::query()->sole()->origin);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->service()->includePolicy($consultor, $alheia->id);
    }

    public function test_vinculo_desligado_nao_e_reativado_pela_rotina(): void
    {
        $apolice = $this->apolice();
        $this->service()->linkSoldPolicies($this->corretor);
        $vinculo = PolicyCommission::query()->sole();
        $this->service()->setLinkActive($this->corretor, $vinculo->id, false);

        $this->service()->linkSoldPolicies($this->corretor);

        self::assertFalse($vinculo->fresh()->is_active);
        self::assertSame(1, PolicyCommission::query()->count());
    }

    // --- Previsão no livro -----------------------------------------------------------------------------------------------

    public function test_reconciliar_cria_a_previsao_e_rodar_de_novo_nao_duplica(): void
    {
        $this->regra(['first_year_percent' => '30.000']);
        $this->apolice(['start_date' => '2026-11-12']);
        $this->service()->linkSoldPolicies($this->corretor);

        $primeira = $this->service()->reconcile($this->corretor);
        $segunda = $this->service()->reconcile($this->corretor);

        self::assertGreaterThan(0, $primeira['criados']);
        self::assertSame(['criados' => 0, 'atualizados' => 0, 'removidos' => 0], $segunda);
        self::assertSame($primeira['criados'], IncomeEntry::query()->where('professional_id', $this->corretor->id)->count());
        self::assertSame(IncomeSource::InsuranceCommission, IncomeEntry::query()->first()->source);
        self::assertSame($this->perfil->id, IncomeEntry::query()->first()->profile_id);
    }

    public function test_mudar_a_regra_corrige_so_o_que_ainda_e_previsto_e_preserva_o_recebido(): void
    {
        $regra = $this->regra(['first_year_percent' => '30.000']);
        $this->apolice(['start_date' => '2026-11-12']);
        $this->service()->linkSoldPolicies($this->corretor);
        $this->service()->reconcile($this->corretor);

        $primeiro = IncomeEntry::query()->orderBy('due_on')->first();
        app(\App\Services\ProfessionalIncomeService::class)->markReceived($this->corretor, $primeiro->id, '58', '2026-11-30');

        $this->service()->saveRule($this->corretor, $regra->id, ['counterparty' => 'Porto Seguro', 'insurance_type' => '', 'first_year_percent' => '40']);

        self::assertSame('60.00', $primeiro->fresh()->amount, 'o recebido não muda');
        self::assertSame('58.00', $primeiro->fresh()->received_amount);
        $segundo = IncomeEntry::query()->orderBy('due_on')->skip(1)->first();
        self::assertSame('80.00', $segundo->amount);
    }

    public function test_pausar_ou_excluir_a_regra_tira_a_previsao_mas_nao_o_recebido(): void
    {
        $regra = $this->regra();
        $this->apolice(['start_date' => '2026-11-12']);
        $this->service()->linkSoldPolicies($this->corretor);
        $this->service()->reconcile($this->corretor);
        $primeiro = IncomeEntry::query()->orderBy('due_on')->first();
        app(\App\Services\ProfessionalIncomeService::class)->markReceived($this->corretor, $primeiro->id, '60', '2026-11-30');

        $this->service()->setRuleActive($this->corretor, $regra->id, false);

        self::assertSame(1, IncomeEntry::query()->count());
        self::assertSame(IncomeStatus::Received, IncomeEntry::query()->sole()->status);
    }

    public function test_previsto_cancelado_nao_ressuscita(): void
    {
        $this->regra();
        $this->apolice(['start_date' => '2026-11-12']);
        $this->service()->linkSoldPolicies($this->corretor);
        $this->service()->reconcile($this->corretor);
        $primeiro = IncomeEntry::query()->orderBy('due_on')->first();
        app(\App\Services\ProfessionalIncomeService::class)->cancel($this->corretor, $primeiro->id);

        $this->service()->reconcile($this->corretor);

        self::assertSame(IncomeStatus::Cancelled, $primeiro->fresh()->status);
        self::assertSame(1, IncomeEntry::query()->where('origin_key', $primeiro->origin_key)->count());
    }

    public function test_apolice_encerrada_ou_excluida_tira_a_previsao_dela(): void
    {
        $this->regra();
        $apolice = $this->apolice(['start_date' => '2026-11-12']);
        $this->service()->linkSoldPolicies($this->corretor);
        $this->service()->reconcile($this->corretor);
        self::assertGreaterThan(0, IncomeEntry::query()->count());

        $apolice->update(['is_active' => false]);
        $this->service()->reconcile($this->corretor);
        self::assertSame(0, IncomeEntry::query()->count());
    }

    public function test_rotina_diaria_vincula_e_preve_para_cada_profissional(): void
    {
        $this->regra();
        $this->apolice(['start_date' => '2026-11-12']);

        $r = $this->service()->runDailyMaintenance();

        self::assertSame(1, $r['vinculos_novos']);
        self::assertGreaterThan(0, $r['criados']);
        self::assertSame($r['criados'], IncomeEntry::query()->count());
        self::assertSame(['criados' => 0, 'atualizados' => 0, 'removidos' => 0], $this->service()->reconcile($this->corretor));
    }

    public function test_lancamento_gerado_por_regra_nao_se_edita_nem_exclui(): void
    {
        $this->regra();
        $this->apolice(['start_date' => '2026-11-12']);
        $this->service()->linkSoldPolicies($this->corretor);
        $this->service()->reconcile($this->corretor);
        $entrada = IncomeEntry::query()->first();

        self::assertTrue($entrada->isGenerated());
        self::assertFalse(app(\App\Services\ProfessionalIncomeService::class)->delete($this->corretor, $entrada->id));
    }

    // --- Sigilo e tela -----------------------------------------------------------------------------------------------------

    public function test_a_regra_de_um_profissional_nao_aparece_nem_se_mexe_por_outro(): void
    {
        $regraDele = $this->regra(['counterparty' => 'Seguradora Sigilosa', 'counterparty_key' => 'seguradora sigilosa']);
        $outro = User::factory()->broker()->create();
        $this->actingAs($outro);

        Livewire::test(CommissionRules::class)->assertDontSee('Seguradora Sigilosa');

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->service()->deleteRule($outro, $regraDele->id);
    }

    public function test_cliente_nao_abre_as_regras(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CommissionRules::class)->assertForbidden();
    }

    public function test_aba_de_regras_aparece_para_corretor_e_consultor_e_lista_apolice_sem_regra(): void
    {
        $this->apolice(['insurer_name' => 'Azos']);
        $this->service()->linkSoldPolicies($this->corretor);
        $this->actingAs($this->corretor);

        Livewire::test(ProfessionalIncome::class)->assertSee('Regras de comissionamento')->call('setAba', 'regras')->assertSet('aba', 'regras')
            ->assertSeeLivewire(CommissionRules::class);

        Livewire::test(CommissionRules::class)->assertSee('Apólices sem regra de comissão')->assertSee('Azos');
    }

    public function test_salvar_regra_pela_tela_ja_refaz_a_previsao(): void
    {
        $this->apolice(['start_date' => '2026-11-12']);
        $this->service()->linkSoldPolicies($this->corretor);
        $this->actingAs($this->corretor);

        Livewire::test(CommissionRules::class)->call('openRuleForm')
            ->set('ruleCounterparty', 'Porto Seguro')->set('ruleFirstYear', '30')->set('ruleDelay', '15')
            ->call('saveRule')->assertHasNoErrors();

        $primeiro = IncomeEntry::query()->orderBy('due_on')->first();
        self::assertSame('60.00', $primeiro->amount);
        self::assertSame('2026-11-27', $primeiro->due_on->toDateString());
    }
}
