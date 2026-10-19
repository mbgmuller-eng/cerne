<?php

namespace Tests\Feature;

use App\Enums\AssetClass;
use App\Enums\AssetFeeMode;
use App\Enums\BillingPeriod;
use App\Enums\ConsultantClientStatus;
use App\Enums\IncomeSource;
use App\Enums\IncomeStatus;
use App\Enums\MemberRole;
use App\Livewire\Consultant\AssetFeeContracts;
use App\Livewire\Consultant\InvestmentRules;
use App\Models\AssetFeeAgreement;
use App\Models\CommissionRule;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\IncomeEntry;
use App\Models\InvestmentRecord;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\AssetFeeCalculator;
use App\Services\AssetFeeService;
use App\Services\InsuranceCommissionService;
use App\Services\ProfessionalIncomeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Percentual sobre patrimônio do consultor: a conta (todo o patrimônio ou só os produtos das regras), os períodos de
 * cobrança, a previsão no livro e o sigilo.
 */
class AssetFeeTest extends TestCase
{
    use RefreshDatabase;

    private User $consultor;

    private FinancialProfile $perfil;

    private ProfileMember $membro;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-10 09:00:00'));

        $this->consultor = User::factory()->consultant()->create();
        [$this->perfil, $this->membro] = $this->clienteVinculado($this->consultor, 'Helen Ferry');

        $this->ativo(['asset_class' => AssetClass::Cdb, 'institution' => 'XP Investimentos', 'name' => 'CDB Banco X', 'current_amount' => '100000.00']);
        $this->ativo(['asset_class' => AssetClass::Fii, 'institution' => 'XP Investimentos', 'ticker' => 'HGLG11', 'name' => 'Fundo logístico', 'current_amount' => '50000.00']);
        $this->ativo(['asset_class' => AssetClass::Acao, 'institution' => 'Itaú', 'name' => 'Ação ABC', 'current_amount' => '25000.00']);
        // Fora da base: inativo e consórcio.
        $this->ativo(['asset_class' => AssetClass::Cdb, 'institution' => 'XP Investimentos', 'name' => 'CDB resgatado', 'current_amount' => '999999.00', 'is_active' => false]);
        $this->ativo(['asset_class' => AssetClass::Consorcio, 'institution' => 'Administradora', 'name' => 'Cota de consórcio', 'current_amount' => '888888.00']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: FinancialProfile, 1: ProfileMember} */
    private function clienteVinculado(User $profissional, string $nome, bool $ativo = true): array
    {
        $cliente = User::factory()->create(['name' => $nome]);
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $cliente->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $cliente->id, 'role' => MemberRole::Primary, 'name' => $nome]);
        ConsultantClient::factory()->create([
            'consultant_id' => $profissional->id, 'client_id' => $cliente->id, 'accepted_at' => now(),
            'status' => $ativo ? ConsultantClientStatus::Active : ConsultantClientStatus::Inactive,
        ]);

        return [$perfil, $membro];
    }

    private function ativo(array $campos): InvestmentRecord
    {
        return InvestmentRecord::factory()->for($this->perfil, 'profile')->for($this->membro, 'member')->create($campos);
    }

    private function contrato(array $campos = []): AssetFeeAgreement
    {
        return AssetFeeAgreement::factory()->create(array_merge(['professional_id' => $this->consultor->id, 'profile_id' => $this->perfil->id], $campos));
    }

    private function regraDeInvestimento(string $escopo, string $alvo, string $percentual): CommissionRule
    {
        return app(AssetFeeService::class)->saveRule($this->consultor, null, ['scope' => $escopo, 'target' => $alvo, 'annual_percent' => $percentual]);
    }

    private function service(): AssetFeeService
    {
        return app(AssetFeeService::class);
    }

    // --- Conta ---------------------------------------------------------------------------------------------------

    public function test_modo_total_aplica_um_percentual_so_sobre_todo_o_patrimonio_e_divide_pelo_periodo(): void
    {
        $contrato = $this->contrato(['mode' => AssetFeeMode::Total, 'total_percent' => '1.0000', 'period' => BillingPeriod::Quarterly]);

        $r = $this->service()->preview($contrato);

        // 100.000 + 50.000 + 25.000; o inativo e o consórcio ficam de fora.
        self::assertSame('175000.00', $r['base']);
        self::assertSame('1750.00', $r['annual']);
        self::assertSame('437.50', $r['per_charge']);
    }

    public function test_modo_regras_usa_produto_empresa_e_tipo_nessa_ordem_e_deixa_de_fora_o_que_nao_tem_regra(): void
    {
        $this->regraDeInvestimento('institution', 'xp investimentos', '0.8');
        $this->regraDeInvestimento('product', 'HGLG11', '1.5');
        $contrato = $this->contrato(['mode' => AssetFeeMode::Rules, 'total_percent' => null, 'period' => BillingPeriod::Monthly]);

        $r = $this->service()->preview($contrato);

        // CDB (empresa XP) 100.000 x 0,8% = 800; HGLG11 (produto) 50.000 x 1,5% = 750; a ação do Itaú não tem regra.
        self::assertSame('150000.00', $r['base']);
        self::assertSame('1550.00', $r['annual']);
        self::assertSame('129.17', $r['per_charge']);

        $this->regraDeInvestimento('asset_class', 'acao', '0.5');
        $r = $this->service()->preview($contrato);

        self::assertSame('175000.00', $r['base']);
        self::assertSame('1675.00', $r['annual']);
        self::assertSame('139.58', $r['per_charge']);
    }

    public function test_regra_pausada_nao_conta(): void
    {
        $regra = $this->regraDeInvestimento('institution', 'XP Investimentos', '0.8');
        $contrato = $this->contrato(['mode' => AssetFeeMode::Rules, 'total_percent' => null]);
        $this->service()->setRuleActive($this->consultor, $regra->id, false);

        self::assertSame('0.00', $this->service()->preview($contrato)['annual']);
    }

    public function test_nome_do_produto_e_da_empresa_nao_diferencia_acento_nem_caixa(): void
    {
        $this->regraDeInvestimento('institution', 'ITAU', '2');
        $contrato = $this->contrato(['mode' => AssetFeeMode::Rules, 'total_percent' => null]);

        self::assertSame('500.00', $this->service()->preview($contrato)['annual']);
    }

    // --- Períodos -----------------------------------------------------------------------------------------------------

    public function test_periodos_trimestrais_terminam_no_ultimo_dia_de_cada_trimestre_do_contrato(): void
    {
        $contrato = $this->contrato(['period' => BillingPeriod::Quarterly, 'starts_on' => '2026-11-01']);

        $fins = array_map(fn ($p) => $p['end']->toDateString(), app(AssetFeeCalculator::class)->periods($contrato));

        self::assertSame(['2027-01-31', '2027-04-30', '2027-07-31', '2027-10-31', '2028-01-31'], $fins);
    }

    public function test_contrato_antigo_so_preve_os_periodos_que_terminam_do_mes_atual_em_diante(): void
    {
        $contrato = $this->contrato(['period' => BillingPeriod::Quarterly, 'starts_on' => '2026-01-01']);

        $fins = array_map(fn ($p) => $p['end']->toDateString(), app(AssetFeeCalculator::class)->periods($contrato));

        self::assertSame('2026-12-31', $fins[0]);
    }

    public function test_periodo_que_o_contrato_nao_completa_nao_e_cobrado(): void
    {
        $contrato = $this->contrato(['period' => BillingPeriod::Semiannual, 'starts_on' => '2026-11-01', 'ends_on' => '2028-02-28']);

        $fins = array_map(fn ($p) => $p['end']->toDateString(), app(AssetFeeCalculator::class)->periods($contrato));

        // Semestres: nov-abr (2027-04-30), mai-out (2027-10-31), nov-abr (2028-04-30, passa do término).
        self::assertSame(['2027-04-30', '2027-10-31'], $fins);
    }

    // --- Previsão no livro ----------------------------------------------------------------------------------------------

    public function test_reconciliar_cria_uma_cobranca_por_periodo_sem_duplicar(): void
    {
        $this->contrato(['period' => BillingPeriod::Quarterly, 'starts_on' => '2026-11-01', 'total_percent' => '1.0000']);

        $primeira = $this->service()->reconcile($this->consultor);
        $segunda = $this->service()->reconcile($this->consultor);

        self::assertSame(5, $primeira['criados']);
        self::assertSame(['criados' => 0, 'atualizados' => 0, 'removidos' => 0], $segunda);

        $primeiro = IncomeEntry::query()->orderBy('due_on')->first();
        self::assertSame(IncomeSource::AssetFee, $primeiro->source);
        self::assertSame('2027-01-31', $primeiro->due_on->toDateString());
        self::assertSame('437.50', $primeiro->amount);
        self::assertSame($this->perfil->id, $primeiro->profile_id);
        self::assertTrue($primeiro->isGenerated());
    }

    public function test_previsto_acompanha_o_patrimonio_mas_periodo_encerrado_fica_congelado(): void
    {
        $this->contrato(['period' => BillingPeriod::Monthly, 'starts_on' => '2026-10-04', 'total_percent' => '1.2000']);

        // Em 02/11 o período 04/10 a 03/11 ainda está aberto.
        $this->service()->reconcile($this->consultor, CarbonImmutable::parse('2026-11-02'));
        $encerrado = IncomeEntry::query()->where('due_on', '2026-11-03')->sole();
        $aberto = IncomeEntry::query()->where('due_on', '2026-12-03')->sole();
        self::assertSame('175.00', $encerrado->amount);

        InvestmentRecord::withoutGlobalScopes()->where('name', 'CDB Banco X')->update(['current_amount' => '200000.00']);
        $r = $this->service()->reconcile($this->consultor, CarbonImmutable::parse('2026-11-10'));

        self::assertSame('175.00', $encerrado->fresh()->amount, 'período encerrado em 03/11 não acompanha o patrimônio de hoje');
        self::assertSame('275.00', $aberto->fresh()->amount);
        self::assertGreaterThan(0, $r['atualizados']);
    }

    public function test_cobranca_recebida_ou_cancelada_nao_e_recalculada_nem_ressuscita(): void
    {
        $this->contrato(['period' => BillingPeriod::Monthly, 'starts_on' => '2026-11-01', 'total_percent' => '1.2000']);
        $this->service()->reconcile($this->consultor);
        $entradas = IncomeEntry::query()->orderBy('due_on')->get();
        app(ProfessionalIncomeService::class)->markReceived($this->consultor, $entradas[0]->id, '170', '2026-12-01');
        app(ProfessionalIncomeService::class)->cancel($this->consultor, $entradas[1]->id);

        InvestmentRecord::withoutGlobalScopes()->where('name', 'CDB Banco X')->update(['current_amount' => '300000.00']);
        $this->service()->reconcile($this->consultor);

        self::assertSame('175.00', $entradas[0]->fresh()->amount);
        self::assertSame('170.00', $entradas[0]->fresh()->received_amount);
        self::assertSame(IncomeStatus::Cancelled, $entradas[1]->fresh()->status);
        self::assertSame(1, IncomeEntry::query()->where('origin_key', $entradas[1]->origin_key)->count());
    }

    public function test_pausar_ou_excluir_o_contrato_tira_a_previsao_e_preserva_o_recebido(): void
    {
        $contrato = $this->contrato(['period' => BillingPeriod::Monthly, 'starts_on' => '2026-11-01']);
        $this->service()->reconcile($this->consultor);
        $primeiro = IncomeEntry::query()->orderBy('due_on')->first();
        app(ProfessionalIncomeService::class)->markReceived($this->consultor, $primeiro->id, '100', '2026-12-01');

        $this->service()->setAgreementActive($this->consultor, $contrato->id, false);
        self::assertSame(1, IncomeEntry::query()->count());

        $this->service()->setAgreementActive($this->consultor, $contrato->id, true);
        self::assertGreaterThan(1, IncomeEntry::query()->count());

        $this->service()->deleteAgreement($this->consultor, $contrato->id);
        self::assertSame(1, IncomeEntry::query()->count());
        self::assertSame(IncomeStatus::Received, IncomeEntry::query()->sole()->status);
    }

    public function test_cliente_sem_vinculo_ativo_nao_gera_previsao_nem_tem_o_patrimonio_lido(): void
    {
        [$perfilEncerrado] = $this->clienteVinculado($this->consultor, 'Ex-cliente', false);
        InvestmentRecord::factory()->for($perfilEncerrado, 'profile')->for(ProfileMember::factory()->create(['profile_id' => $perfilEncerrado->id]), 'member')->create(['current_amount' => '500000.00']);
        AssetFeeAgreement::factory()->create(['professional_id' => $this->consultor->id, 'profile_id' => $perfilEncerrado->id, 'starts_on' => '2026-11-01']);

        $this->service()->reconcile($this->consultor);

        self::assertSame(0, IncomeEntry::query()->where('profile_id', $perfilEncerrado->id)->count());
    }

    public function test_mudar_a_regra_refaz_a_previsao_dos_contratos_no_modo_regras(): void
    {
        $this->contrato(['mode' => AssetFeeMode::Rules, 'total_percent' => null, 'period' => BillingPeriod::Monthly, 'starts_on' => '2026-11-01']);
        $regra = $this->regraDeInvestimento('institution', 'XP Investimentos', '1.2');
        $primeiro = IncomeEntry::query()->orderBy('due_on')->first();
        self::assertSame('150.00', $primeiro->amount);

        $this->service()->saveRule($this->consultor, $regra->id, ['scope' => 'institution', 'target' => 'XP Investimentos', 'annual_percent' => '2.4']);

        self::assertSame('300.00', $primeiro->fresh()->amount);

        $this->service()->deleteRule($this->consultor, $regra->id);
        self::assertSame(0, IncomeEntry::query()->count());
    }

    public function test_rotina_diaria_refaz_a_previsao_de_cada_consultor(): void
    {
        $this->contrato(['period' => BillingPeriod::Monthly, 'starts_on' => '2026-11-01']);

        $r = $this->service()->runDailyMaintenance();

        self::assertSame(1, $r['profissionais']);
        self::assertGreaterThan(0, $r['criados']);
        self::assertSame(['criados' => 0, 'atualizados' => 0, 'removidos' => 0], $this->service()->reconcile($this->consultor));
    }

    // --- Regras e isolamento -------------------------------------------------------------------------------------------

    public function test_regra_de_investimento_nao_se_mistura_com_a_de_seguradora(): void
    {
        $this->regraDeInvestimento('institution', 'Porto Seguro', '1');
        app(InsuranceCommissionService::class)->saveRule($this->consultor, null, ['counterparty' => 'Porto Seguro', 'insurance_type' => '', 'first_year_percent' => '30']);

        self::assertSame(1, app(InsuranceCommissionService::class)->rules($this->consultor)->count());
        self::assertSame(1, $this->service()->rules($this->consultor)->count());
        self::assertTrue($this->service()->ruleExists($this->consultor, 'institution', 'porto seguro'));
        self::assertFalse($this->service()->ruleExists($this->consultor, 'product', 'porto seguro'));
    }

    public function test_contrato_e_regra_de_um_consultor_nunca_aparecem_nem_se_mexem_por_outro(): void
    {
        $contrato = $this->contrato();
        $regra = $this->regraDeInvestimento('institution', 'Sigilosa Corretora', '1');
        $outro = User::factory()->consultant()->create();

        self::assertCount(0, $this->service()->agreements($outro));
        self::assertCount(0, $this->service()->rules($outro));

        foreach ([fn () => $this->service()->setAgreementActive($outro, $contrato->id, false), fn () => $this->service()->deleteAgreement($outro, $contrato->id),
            fn () => $this->service()->deleteRule($outro, $regra->id), fn () => $this->service()->setRuleActive($outro, $regra->id, false)] as $acao) {
            try {
                $acao();
                self::fail('mexeu no dado de outro consultor');
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                // esperado
            }
        }

        $this->actingAs($outro);
        Livewire::test(InvestmentRules::class)->assertDontSee('Sigilosa Corretora');
        self::assertSame(1, AssetFeeAgreement::query()->count());
    }

    // --- Telas -----------------------------------------------------------------------------------------------------------

    public function test_corretor_e_cliente_nao_abrem_contratos_nem_regras_de_investimento(): void
    {
        foreach ([User::factory()->broker()->create(), User::factory()->create()] as $usuario) {
            $this->actingAs($usuario);
            Livewire::test(AssetFeeContracts::class)->assertForbidden();
            Livewire::test(InvestmentRules::class)->assertForbidden();
        }
    }

    public function test_criar_contrato_pela_tela_valida_o_cliente_e_ja_preve(): void
    {
        [$perfilAlheio] = $this->clienteVinculado(User::factory()->consultant()->create(), 'Cliente alheio');
        $this->actingAs($this->consultor);

        Livewire::test(AssetFeeContracts::class)->call('openForm')
            ->set('profileId', $perfilAlheio->id)->set('totalPercent', '1')->call('save')->assertHasErrors('profileId');

        Livewire::test(AssetFeeContracts::class)->call('openForm')
            ->set('profileId', $this->perfil->id)->set('period', 'quarterly')->set('mode', 'total')->set('totalPercent', '1')->set('startsOn', '2026-11-01')
            ->call('save')->assertHasNoErrors()->assertSee('Helen Ferry')->assertSee('437,50');

        self::assertSame(1, AssetFeeAgreement::query()->count());
        self::assertSame(5, IncomeEntry::query()->count());
    }

    public function test_contrato_no_modo_total_exige_percentual(): void
    {
        $this->actingAs($this->consultor);

        Livewire::test(AssetFeeContracts::class)->call('openForm')
            ->set('profileId', $this->perfil->id)->set('mode', 'total')->set('totalPercent', '')->call('save')->assertHasErrors('totalPercent');

        Livewire::test(AssetFeeContracts::class)->call('openForm')
            ->set('profileId', $this->perfil->id)->set('mode', 'rules')->set('totalPercent', '')->call('save')->assertHasNoErrors();
    }

    public function test_regra_de_tipo_de_ativo_so_aceita_tipo_conhecido_e_nao_duplica(): void
    {
        $this->actingAs($this->consultor);

        Livewire::test(InvestmentRules::class)->call('openForm')
            ->set('scope', 'asset_class')->set('target', 'invalido')->set('annualPercent', '1')->call('save')->assertHasErrors('target');

        Livewire::test(InvestmentRules::class)->call('openForm')
            ->set('scope', 'asset_class')->set('target', 'acao')->set('annualPercent', '0.5')->call('save')->assertHasNoErrors();

        Livewire::test(InvestmentRules::class)->call('openForm')
            ->set('scope', 'asset_class')->set('target', 'acao')->set('annualPercent', '0.7')->call('save')->assertHasErrors('target');

        self::assertSame('Ação', CommissionRule::query()->where('kind', 'investment')->sole()->counterparty);
    }
}
