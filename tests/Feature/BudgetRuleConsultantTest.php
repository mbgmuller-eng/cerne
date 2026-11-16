<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\MemberRole;
use App\Enums\Necessity;
use App\Livewire\Budget\RuleSummary;
use App\Livewire\CashFlow\CashFlowIndex;
use App\Livewire\Consultant\BudgetTemplates;
use App\Livewire\Consultant\PortfolioBudget;
use App\Livewire\Dashboard;
use App\Models\BudgetRule;
use App\Models\BudgetRuleTemplate;
use App\Models\ConsultantClient;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\IncomeRecord;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\BudgetPortfolioService;
use App\Support\Budget\BudgetStructure;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A regra de orçamento do lado do consultor (modelos, aplicar a clientes, a carteira) e o cartão-resumo do painel e do fluxo de caixa. Hoje é 12/11/2026.
 */
class BudgetRuleConsultantTest extends TestCase
{
    use RefreshDatabase;

    private User $consultor;

    private FinancialProfile $perfilA;

    private FinancialProfile $perfilB;

    private User $donoA;

    private User $donoB;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('pt_BR');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-12 09:00:00'));

        $this->consultor = User::factory()->consultant()->create(['name' => 'Marcos Consultor']);
        [$this->donoA, $this->perfilA] = $this->cliente('Ana Silva', ConsultantClientStatus::Active);
        [$this->donoB, $this->perfilB] = $this->cliente('Bia Souza', ConsultantClientStatus::Active);

        foreach (['Seguro de Vida', 'Reserva de Paz', 'Reserva de Oportunidade', 'Previdência Privada', 'Ações', 'FIIs', 'Consórcio', 'Poupança', 'Fundos', 'Dólar', 'ETFs', 'ETFs Exterior'] as $nome) {
            ExpenseCategory::factory()->shared()->create(['name' => $nome, 'necessity' => Necessity::Investment]);
        }

        $this->actingAs($this->consultor);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: FinancialProfile} */
    private function cliente(string $nome, ConsultantClientStatus $vinculo): array
    {
        $dono = User::factory()->create(['name' => $nome]);
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $dono->id]);
        ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $dono->id, 'role' => MemberRole::Primary, 'name' => $nome]);
        ConsultantClient::factory()->create(['consultant_id' => $this->consultor->id, 'client_id' => $dono->id, 'status' => $vinculo]);

        return [$dono, $perfil];
    }

    private function regra(FinancialProfile $perfil, string $de = '2026-01'): BudgetRule
    {
        [$ano, $mes] = array_map('intval', explode('-', $de));

        return BudgetRule::withoutProfileScope()->create(['profile_id' => $perfil->id, 'effective_year' => $ano, 'effective_month' => $mes, 'structure' => BudgetStructure::normalize(BudgetStructure::default())]);
    }

    private function receita(FinancialProfile $perfil, string $valor): void
    {
        IncomeRecord::factory()->create(['profile_id' => $perfil->id, 'member_id' => null, 'amount' => $valor, 'received_date' => '2026-11-05']);
    }

    private function gastoInvestimento(FinancialProfile $perfil, string $valor, array $campos = []): void
    {
        ExpenseRecord::factory()->create(array_merge([
            'profile_id' => $perfil->id, 'member_id' => null, 'necessity' => 'investment', 'subcategory_id' => null, 'amount' => $valor, 'expense_date' => '2026-11-05',
            'category_id' => ExpenseCategory::query()->where('name', 'Ações')->value('id') ?? ExpenseCategory::withoutTaxonomyScope()->where('name', 'Ações')->value('id'),
        ], $campos));
    }

    // ------------------------------------------------------------------ modelos

    public function test_o_consultor_cria_edita_e_exclui_o_proprio_modelo(): void
    {
        Livewire::test(BudgetTemplates::class)
            ->assertSee('Você ainda não tem modelos')
            ->call('newTemplate')
            ->assertSet('draft.areas.investment', '30.00')
            ->set('name', 'Meu 50/20/30')->set('description', 'Para o cliente que começa')
            ->call('saveTemplate')->assertHasNoErrors()
            ->assertSee('Meu 50/20/30')->assertSee('Modelo salvo.');

        $modelo = BudgetRuleTemplate::query()->sole();
        self::assertSame($this->consultor->id, $modelo->consultant_id);

        Livewire::test(BudgetTemplates::class)
            ->call('edit', $modelo->id)
            ->set('draft.areas.essential', '40')->set('draft.areas.discretionary', '30')
            ->call('saveTemplate')->assertHasNoErrors();

        self::assertSame('40.00', BudgetStructure::normalize($modelo->fresh()->structure)['areas']['essential']);

        Livewire::test(BudgetTemplates::class)->call('delete', $modelo->id);
        self::assertSame(0, BudgetRuleTemplate::query()->count());
    }

    public function test_modelo_sem_nome_ou_com_regra_invalida_nao_salva(): void
    {
        Livewire::test(BudgetTemplates::class)->call('newTemplate')->call('saveTemplate')->assertHasErrors(['name']);

        Livewire::test(BudgetTemplates::class)->call('newTemplate')
            ->set('name', 'Quebrado')->set('draft.areas.essential', '80')
            ->assertSee('somam 130%')
            ->call('saveTemplate');

        self::assertSame(0, BudgetRuleTemplate::query()->count());
    }

    public function test_o_modelo_de_um_consultor_nao_aparece_nem_se_mexe_pelo_outro(): void
    {
        $meu = BudgetRuleTemplate::create(['name' => 'Do Marcos', 'structure' => BudgetStructure::default()]);

        $outro = User::factory()->consultant()->create();
        $this->actingAs($outro);

        Livewire::test(BudgetTemplates::class)->assertDontSee('Do Marcos')->call('delete', $meu->id)->assertNotFound();
        Livewire::test(BudgetTemplates::class)->call('edit', $meu->id)->assertNotFound();
        self::assertSame(1, BudgetRuleTemplate::withoutGlobalScopes()->count());
    }

    public function test_cliente_e_corretor_nao_entram_nos_modelos_nem_na_carteira(): void
    {
        $this->actingAs($this->donoA);
        Livewire::test(BudgetTemplates::class)->assertForbidden();
        Livewire::test(PortfolioBudget::class)->assertForbidden();

        $corretor = User::factory()->broker()->create();
        $this->actingAs($corretor);
        Livewire::test(BudgetTemplates::class)->assertForbidden();
    }

    public function test_aplicar_o_modelo_copia_a_estrutura_para_os_clientes_marcados_a_partir_do_mes(): void
    {
        $modelo = BudgetRuleTemplate::create(['name' => 'Cerne', 'structure' => BudgetStructure::preset('classico')]);

        Livewire::test(BudgetTemplates::class)
            ->call('openApply', $modelo->id)
            ->assertSee('Ana Silva')->assertSee('Bia Souza')->assertSee('sem regra')
            ->set('applyClients', [$this->perfilA->id, $this->perfilB->id])
            ->set('applyYm', '2026-12')
            ->call('applyToClients')
            ->assertSee('Modelo aplicado a 2 clientes a partir de 12/2026.');

        $regraA = BudgetRule::withoutProfileScope()->where('profile_id', $this->perfilA->id)->sole();
        self::assertSame([2026, 12], [$regraA->effective_year, $regraA->effective_month]);
        self::assertSame('30.00', BudgetStructure::normalize($regraA->structure)['areas']['discretionary'], 'é a estrutura do modelo');
        self::assertSame($this->consultor->id, $regraA->created_by_user_id);

        // Mudar o modelo depois não altera a regra do cliente (é cópia).
        $modelo->update(['structure' => BudgetStructure::preset('cerne')]);
        self::assertSame('30.00', BudgetStructure::normalize($regraA->fresh()->structure)['areas']['discretionary']);
    }

    public function test_aplicar_so_chega_a_cliente_com_vinculo_ativo_do_proprio_consultor(): void
    {
        [, $pendente] = $this->cliente('Caio Pendente', ConsultantClientStatus::Pending);
        $outroConsultor = User::factory()->consultant()->create();
        $donoDeOutro = User::factory()->create();
        $perfilDeOutro = FinancialProfile::factory()->create(['owner_user_id' => $donoDeOutro->id]);
        ConsultantClient::factory()->create(['consultant_id' => $outroConsultor->id, 'client_id' => $donoDeOutro->id, 'status' => ConsultantClientStatus::Active]);

        $modelo = BudgetRuleTemplate::create(['name' => 'Cerne', 'structure' => BudgetStructure::default()]);

        $c = Livewire::test(BudgetTemplates::class)->call('openApply', $modelo->id)
            ->assertDontSee('Caio Pendente')
            ->set('applyClients', [$pendente->id, $perfilDeOutro->id, 'inventado'])
            ->call('applyToClients')->assertHasErrors(['applyClients']);

        self::assertSame(0, BudgetRule::withoutProfileScope()->count(), 'nenhum id fora da lista de vínculos ativos recebe regra');

        $c->set('applyClients', [$pendente->id, $this->perfilA->id])->call('applyToClients');
        self::assertSame([$this->perfilA->id], BudgetRule::withoutProfileScope()->pluck('profile_id')->all());
    }

    public function test_aplicar_no_mesmo_mes_atualiza_e_o_modal_avisa_quem_ja_tem_regra(): void
    {
        $this->regra($this->perfilA, '2026-12');
        $modelo = BudgetRuleTemplate::create(['name' => 'Clássico', 'structure' => BudgetStructure::preset('classico')]);

        Livewire::test(BudgetTemplates::class)
            ->call('openApply', $modelo->id)->assertSee('já tem regra desde 12/2026')
            ->set('applyClients', [$this->perfilA->id])->set('applyYm', '2026-12')
            ->call('applyToClients');

        self::assertSame(1, BudgetRule::withoutProfileScope()->where('profile_id', $this->perfilA->id)->count(), 'índice único: o mesmo mês atualiza a mesma linha');
        self::assertSame('30.00', BudgetStructure::normalize(BudgetRule::withoutProfileScope()->where('profile_id', $this->perfilA->id)->sole()->structure)['areas']['discretionary']);
    }

    public function test_aplicar_sem_cliente_ou_com_mes_invalido_da_erro(): void
    {
        $modelo = BudgetRuleTemplate::create(['name' => 'Cerne', 'structure' => BudgetStructure::default()]);

        Livewire::test(BudgetTemplates::class)->call('openApply', $modelo->id)->call('applyToClients')->assertHasErrors(['applyClients']);
        Livewire::test(BudgetTemplates::class)->call('openApply', $modelo->id)->set('applyClients', [$this->perfilA->id])->set('applyYm', 'xx')->call('applyToClients')->assertHasErrors(['applyYm']);
    }

    // ------------------------------------------------------------------ carteira

    public function test_a_carteira_mostra_cada_cliente_com_a_receita_e_as_areas_e_enxerga_o_privado(): void
    {
        $this->regra($this->perfilA);
        $this->receita($this->perfilA, '10000.00');
        $membro = ProfileMember::query()->where('profile_id', $this->perfilA->id)->first();
        $this->gastoInvestimento($this->perfilA, '3000.00', ['member_id' => $membro->id, 'is_private' => true]); // privado: o consultor vê

        $visao = app(BudgetPortfolioService::class)->overview($this->consultor, 2026, 11);

        self::assertSame(['clients' => 2, 'with_rule' => 1, 'limits_ok' => 1, 'investment_reached' => 1], $visao['totals']);
        self::assertSame('Ana Silva', $visao['rows'][0]['name'], 'quem tem regra vem primeiro');
        self::assertSame('10000.00', $visao['rows'][0]['income']);
        self::assertSame('3000.00', $visao['rows'][0]['areas']['investment']['realized']);
        self::assertFalse($visao['rows'][1]['has_rule']);

        Livewire::test(PortfolioBudget::class)
            ->assertSee('Ana Silva')->assertSee('Bia Souza')->assertSee('Sem regra de orçamento.')
            ->assertSee('R$ 10.000,00')->assertSee('meta batida')
            ->assertSeeHtml('data-portfolio-budget');
    }

    public function test_a_carteira_so_calcula_cliente_com_vinculo_ativo_e_restaura_o_contexto(): void
    {
        [, $encerrado] = $this->cliente('Davi Encerrado', ConsultantClientStatus::Inactive);
        $this->regra($encerrado);

        $contexto = app(ProfileContext::class);
        $contexto->set($this->perfilB, null, true);

        $visao = app(BudgetPortfolioService::class)->overview($this->consultor, 2026, 11);

        self::assertNotContains('Davi Encerrado', array_column($visao['rows'], 'name'));
        self::assertSame($this->perfilB->id, $contexto->profileId(), 'o perfil que estava aberto volta ao fim');
        self::assertTrue($contexto->isConsultant());
    }

    public function test_navegar_entre_meses_na_carteira(): void
    {
        Livewire::test(PortfolioBudget::class)->assertSet('ym', '2026-11')
            ->call('previousMonth')->assertSet('ym', '2026-10')
            ->call('nextMonth')->call('nextMonth')->assertSet('ym', '2026-12')
            ->call('currentMonth')->assertSet('ym', '2026-11');
    }

    // ------------------------------------------------------------------ cartão-resumo

    public function test_o_cartao_resumo_convida_a_definir_a_regra_e_depois_mostra_as_tres_areas(): void
    {
        $membro = ProfileMember::query()->where('profile_id', $this->perfilA->id)->first();
        $this->actingAs($this->donoA);
        app(ProfileContext::class)->set($this->perfilA, $membro);

        Livewire::test(RuleSummary::class)->assertSeeHtml('data-budget-summary-empty')->assertSee('Definir');

        $this->regra($this->perfilA);
        $this->receita($this->perfilA, '10000.00');
        $this->gastoInvestimento($this->perfilA, '500.00');

        Livewire::test(RuleSummary::class)
            ->assertSeeHtml('data-summary-area="essential"')->assertSeeHtml('data-summary-area="investment"')
            ->assertSee('Regra do mês')->assertSee('R$ 500,00 de R$ 3.000,00')->assertSee('Faltam R$ 2.500,00');
    }

    public function test_o_cartao_resumo_aparece_no_painel_e_no_fluxo_de_caixa_e_nao_para_o_corretor(): void
    {
        $membro = ProfileMember::query()->where('profile_id', $this->perfilA->id)->first();
        $this->actingAs($this->donoA);
        app(ProfileContext::class)->set($this->perfilA, $membro);

        Livewire::test(Dashboard::class)->assertSeeHtml('data-budget-summary');
        Livewire::test(CashFlowIndex::class)->assertSeeHtml('data-budget-summary');

        $corretor = User::factory()->broker()->create();
        $this->actingAs($corretor);
        Livewire::test(RuleSummary::class)->assertDontSeeHtml('data-budget-summary-empty');
    }
}
