<?php

namespace Tests\Feature;

use App\Enums\FundingMethod;
use App\Enums\GoalStatus;
use App\Enums\MemberRole;
use App\Enums\Necessity;
use App\Livewire\Budget\BudgetRuleIndex;
use App\Models\BudgetRule;
use App\Models\BudgetRuleTemplate;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\Goal;
use App\Models\IncomeRecord;
use App\Models\InsurancePolicy;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\Budget\BudgetStructure;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A tela da regra de orçamento: começar por um modelo, editar e salvar com mês de início, o painel do mês (áreas, itens, fixos, sem destino), a divisão do
 * balde de objetivos e o que é recusado. Hoje é 12/11/2026.
 */
class BudgetRuleIndexTest extends TestCase
{
    use RefreshDatabase;

    private FinancialProfile $perfil;

    private ProfileMember $ana;

    private User $usuarioAna;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('pt_BR');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-12 09:00:00'));

        $this->usuarioAna = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $this->usuarioAna->id]);
        $this->ana = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuarioAna->id, 'role' => MemberRole::Primary, 'name' => 'Ana']);
        $this->actingAs($this->usuarioAna);
        app(ProfileContext::class)->set($this->perfil, $this->ana);

        foreach (['Seguro de Vida', 'Reserva de Paz', 'Reserva de Oportunidade', 'Previdência Privada', 'Ações', 'FIIs', 'Consórcio', 'Poupança', 'Fundos', 'Cripto'] as $nome) {
            ExpenseCategory::factory()->shared()->create(['name' => $nome, 'necessity' => Necessity::Investment]);
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function regra(string $de = '2026-11'): BudgetRule
    {
        [$ano, $mes] = array_map('intval', explode('-', $de));

        return BudgetRule::create(['profile_id' => $this->perfil->id, 'effective_year' => $ano, 'effective_month' => $mes, 'structure' => BudgetStructure::normalize(BudgetStructure::default())]);
    }

    private function receita(string $valor): void
    {
        IncomeRecord::factory()->create(['profile_id' => $this->perfil->id, 'member_id' => null, 'amount' => $valor, 'received_date' => '2026-11-05']);
    }

    private function investimento(string $categoria, string $valor): void
    {
        $cat = ExpenseCategory::query()->where('name', $categoria)->firstOrFail();
        ExpenseRecord::factory()->create(['profile_id' => $this->perfil->id, 'member_id' => null, 'necessity' => 'investment', 'category_id' => $cat->id, 'subcategory_id' => null, 'amount' => $valor, 'expense_date' => '2026-11-05']);
    }

    // ------------------------------------------------------------------ começar e salvar

    public function test_sem_regra_a_tela_oferece_os_modelos_prontos(): void
    {
        Livewire::test(BudgetRuleIndex::class)
            ->assertSee('Defina como a sua receita se divide')
            ->assertSee('Cerne 50/20/30')
            ->assertSee('Clássico 50/30/20')
            ->assertDontSeeHtml('data-edit-rule');
    }

    public function test_comecar_por_um_modelo_abre_o_editor_com_os_percentuais_e_salva_a_partir_do_mes(): void
    {
        Livewire::test(BudgetRuleIndex::class)
            ->call('startWith', 'cerne')
            ->assertSet('showEditor', true)
            ->assertSet('draft.areas.essential', '50.00')
            ->assertSet('draft.areas.investment', '30.00')
            ->assertSet('startYm', '2026-11')
            ->assertSee('Fechou em 100%.')
            ->call('saveRule')
            ->assertSet('showEditor', false)
            ->assertSee('Regra salva. Vale a partir de 11/2026');

        $regra = BudgetRule::query()->sole();
        self::assertSame(2026, $regra->effective_year);
        self::assertSame(11, $regra->effective_month);
        self::assertSame($this->usuarioAna->id, $regra->created_by_user_id);
        self::assertSame(BudgetStructure::normalize(BudgetStructure::default()), BudgetStructure::normalize($regra->structure));
    }

    public function test_a_tela_sem_regra_oferece_o_personalizado_que_comeca_em_branco(): void
    {
        Livewire::test(BudgetRuleIndex::class)
            ->assertSee('Personalizado')
            ->call('startWith', 'personalizado')
            ->assertSet('showEditor', true)
            ->assertSet('draft.areas.essential', '50.00')
            ->assertSet('draft.areas.discretionary', '30.00')
            ->assertSet('draft.areas.investment', '20.00')
            ->assertSet('draft.groups', []);
    }

    public function test_o_personalizado_pode_ficar_so_com_as_tres_areas_e_o_painel_funciona_sem_grupos(): void
    {
        $this->receita('10000.00');
        $this->investimento('Ações', '700.00'); // sem grupos não há onde mapear: não vira "sem item na regra"

        Livewire::test(BudgetRuleIndex::class)
            ->call('startWith', 'personalizado')
            ->call('saveRule')->assertSet('showEditor', false)
            ->assertSee('Investimentos · 20%')
            ->assertSee('R$ 700,00')
            ->assertSee('Sem grupos: a meta vale para o investimento como um todo')
            ->assertDontSeeHtml('data-unmapped')
            ->assertDontSeeHtml('data-goal-allocation');

        self::assertSame('2000.00', app(\App\Services\BudgetRuleService::class)->report(2026, 11)['areas']['investment']['target']);
    }

    public function test_montar_a_regra_do_zero_com_grupo_e_item_proprios(): void
    {
        $c = Livewire::test(BudgetRuleIndex::class)->call('startWith', 'personalizado')
            ->call('addGroup')
            ->set('draft.groups.0.name', 'Tudo em renda variável')->set('draft.groups.0.percent', '100')
            ->call('addItem', 0)
            ->set('draft.groups.0.items.0.name', 'Ações')->set('draft.groups.0.items.0.percent', '100')
            ->set('draft.groups.0.items.0.categories', ['Ações', 'FIIs'])
            ->call('saveRule')->assertSet('showEditor', false);

        $regra = BudgetRule::query()->sole();
        $grupo = BudgetStructure::normalize($regra->structure)['groups'][0];
        self::assertSame('Tudo em renda variável', $grupo['name']);
        self::assertSame(['Ações', 'FIIs'], $grupo['items'][0]['categories']);
    }

    public function test_salvar_o_mesmo_mes_de_novo_atualiza_a_mesma_regra(): void
    {
        $this->regra('2026-11');

        Livewire::test(BudgetRuleIndex::class)
            ->call('openEditor')
            ->set('draft.areas.essential', '40')
            ->set('draft.areas.discretionary', '30')
            ->call('saveRule')->assertHasNoErrors();

        self::assertSame(1, BudgetRule::query()->count(), 'índice único: uma versão por mês');
        self::assertSame('40.00', BudgetStructure::normalize(BudgetRule::query()->sole()->structure)['areas']['essential']);
    }

    public function test_mudar_a_regra_de_um_mes_novo_nao_reescreve_os_meses_passados(): void
    {
        $this->regra('2026-01');
        $this->receita('10000.00');

        Livewire::test(BudgetRuleIndex::class)
            ->call('openEditor')
            ->set('draft.areas.essential', '40')->set('draft.areas.discretionary', '30')
            ->set('startYm', '2026-12')
            ->call('saveRule')
            ->assertSet('ym', '2026-12');

        self::assertSame(2, BudgetRule::query()->count());
        self::assertSame('50.00', BudgetStructure::normalize(app(\App\Services\BudgetRuleService::class)->ruleFor(2026, 11)->structure)['areas']['essential'], 'novembro segue a regra de janeiro');
        self::assertSame('40.00', BudgetStructure::normalize(app(\App\Services\BudgetRuleService::class)->ruleFor(2026, 12)->structure)['areas']['essential']);
    }

    public function test_regra_invalida_nao_salva_e_a_tela_diz_o_porque(): void
    {
        Livewire::test(BudgetRuleIndex::class)
            ->call('startWith', 'cerne')
            ->set('draft.areas.essential', '70')
            ->assertSee('somam 120%')
            ->call('saveRule')
            ->assertSet('showEditor', true);

        self::assertSame(0, BudgetRule::query()->count());
    }

    public function test_mes_de_inicio_invalido_e_recusado(): void
    {
        Livewire::test(BudgetRuleIndex::class)
            ->call('startWith', 'cerne')->set('startYm', '2026-13')
            ->call('saveRule')->assertHasErrors(['startYm']);

        Livewire::test(BudgetRuleIndex::class)
            ->call('startWith', 'cerne')->set('startYm', '1999-01')
            ->call('saveRule')->assertHasErrors(['startYm']);

        self::assertSame(0, BudgetRule::query()->count());
    }

    public function test_adicionar_e_tirar_grupo_e_item_no_editor(): void
    {
        $c = Livewire::test(BudgetRuleIndex::class)->call('startWith', 'cerne');

        $c->call('addGroup')->assertSet('draft.groups.3.name', '')
            ->call('addItem', 3)->assertSet('draft.groups.3.items.0.kind', 'percent')
            ->call('removeItem', 3, 0)->assertSet('draft.groups.3.items', [])
            ->call('removeGroup', 3);

        self::assertCount(3, $c->get('draft')['groups']);

        $c->call('removeItem', 0, 0); // tira o seguro de vida do primeiro grupo
        self::assertSame('Reserva de paz', $c->get('draft')['groups'][0]['items'][0]['name']);
    }

    public function test_o_limite_de_grupos_e_itens_e_respeitado(): void
    {
        $c = Livewire::test(BudgetRuleIndex::class)->call('startWith', 'cerne');

        foreach (range(1, 12) as $_) {
            $c->call('addGroup');
        }
        self::assertCount(BudgetStructure::MAX_GROUPS, $c->get('draft')['groups']);

        foreach (range(1, 15) as $_) {
            $c->call('addItem', 0);
        }
        self::assertCount(BudgetStructure::MAX_ITEMS, $c->get('draft')['groups'][0]['items']);
    }

    // ------------------------------------------------------------------ o painel do mês

    public function test_o_painel_mostra_as_areas_os_itens_e_o_cadastrado_x_lancado(): void
    {
        $this->regra();
        $this->receita('10000.00');
        InsurancePolicy::factory()->life()->create(['profile_id' => $this->perfil->id, 'monthly_premium' => '150.00']);
        $this->investimento('Seguro de Vida', '150.00');
        $this->investimento('Reserva de Paz', '400.00');
        $this->investimento('Cripto', '50.00');

        Livewire::test(BudgetRuleIndex::class)
            ->assertSee('Receita de novembro de 2026')
            ->assertSee('R$ 10.000,00')
            ->assertSee('Essencial · 50%')
            ->assertSee('Investimentos · 30%')
            ->assertSee('Reserva de paz')
            ->assertSee('meta R$ 425,00', false)
            ->assertSee('compromisso fixo')
            ->assertSee('cadastrado R$ 150,00 (1 apólice de vida)')
            ->assertSee('Lançado: cadastrado R$ 150,00 (1 apólice de vida), lançado R$ 150,00.')
            ->assertSeeHtml('data-unmapped')
            ->assertSee('Cripto: R$ 50,00')
            ->assertSeeHtml('data-goal-allocation');
    }

    public function test_o_painel_sem_receita_avisa_que_as_metas_ficam_zeradas(): void
    {
        $this->regra();

        Livewire::test(BudgetRuleIndex::class)->assertSee('Sem receita neste mês, as metas ficam zeradas');
    }

    public function test_navegar_entre_os_meses_e_voltar_ao_atual(): void
    {
        $this->regra('2026-01');

        Livewire::test(BudgetRuleIndex::class)
            ->assertSet('ym', '2026-11')
            ->call('previousMonth')->assertSet('ym', '2026-10')
            ->call('previousMonth')->assertSet('ym', '2026-09')
            ->assertSee('Mês atual')
            ->call('currentMonth')->assertSet('ym', '2026-11')
            ->call('nextMonth')->assertSet('ym', '2026-12');
    }

    public function test_url_com_mes_invalido_cai_no_mes_atual(): void
    {
        Livewire::withQueryParams(['mes' => 'lixo'])->test(BudgetRuleIndex::class)->assertSet('ym', '2026-11');
    }

    public function test_remover_a_regra_volta_a_valer_a_anterior(): void
    {
        $antiga = $this->regra('2026-01');
        $this->regra('2026-11');

        Livewire::test(BudgetRuleIndex::class)->call('deleteRule');

        self::assertSame(1, BudgetRule::query()->count());
        self::assertSame($antiga->id, app(\App\Services\BudgetRuleService::class)->ruleFor(2026, 11)->id);
    }

    // ------------------------------------------------------------------ objetivos

    public function test_a_divisao_do_balde_de_objetivos_usa_o_que_sobra_depois_do_consorcio_e_aplica_no_objetivo(): void
    {
        $this->regra();
        $this->receita('10000.00');

        $meta = Goal::create([
            'name' => 'Apartamento', 'priority' => 1, 'estimated_value' => '60000.00', 'current_amount' => '0.00', 'funding_method' => FundingMethod::LumpSum,
            'status' => GoalStatus::Active, 'created_by_user_id' => $this->usuarioAna->id, 'target_date' => '2027-11-12',
        ]);

        $c = Livewire::test(BudgetRuleIndex::class)
            ->assertSee('Dividir o balde de objetivos')
            ->assertSee('R$ 1.000,00 por mês para repartir')
            ->assertSee('Apartamento')
            ->assertSee('Apartamento: de sem valor definido para R$ 1.000,00 por mês.')
            ->call('applyGoals')
            ->assertSee('1 objetivo atualizado com o valor sugerido por mês.');

        self::assertSame('1000.00', (string) $meta->fresh()->installment_amount);

        $c->set('goalMethod', 'proportional')->assertSet('goalMethod', 'proportional');
    }

    public function test_sem_item_de_objetivos_na_regra_a_divisao_nao_aparece(): void
    {
        $estrutura = BudgetStructure::default();
        $estrutura['groups'][2]['items'][1]['kind'] = 'percent';
        BudgetRule::create(['profile_id' => $this->perfil->id, 'effective_year' => 2026, 'effective_month' => 1, 'structure' => BudgetStructure::normalize($estrutura)]);
        $this->receita('10000.00');

        Livewire::test(BudgetRuleIndex::class)->assertDontSeeHtml('data-goal-allocation');
    }

    // ------------------------------------------------------------------ quem entra e o que é do perfil

    public function test_corretor_nao_entra(): void
    {
        $corretor = User::factory()->broker()->create();
        $this->actingAs($corretor);

        Livewire::test(BudgetRuleIndex::class)->assertForbidden();
    }

    public function test_a_regra_de_outro_perfil_nao_aparece(): void
    {
        $outro = FinancialProfile::factory()->create();
        BudgetRule::withoutProfileScope()->create(['profile_id' => $outro->id, 'effective_year' => 2026, 'effective_month' => 1, 'structure' => BudgetStructure::normalize(BudgetStructure::default())]);

        Livewire::test(BudgetRuleIndex::class)->assertSee('Defina como a sua receita se divide');
    }

    public function test_o_consultor_usa_o_modelo_dele_no_editor_e_o_cliente_nao_ve_modelos(): void
    {
        $consultor = User::factory()->consultant()->create();
        $this->actingAs($consultor);
        app(ProfileContext::class)->set($this->perfil, null, true);

        $estrutura = BudgetStructure::preset('classico');
        $modelo = BudgetRuleTemplate::create(['name' => 'Meu 50/30/20', 'description' => 'Para quem começa', 'structure' => $estrutura]);

        Livewire::test(BudgetRuleIndex::class)
            ->assertSee('Meu modelo: Meu 50/30/20')
            ->call('useTemplate', $modelo->id)
            ->assertSet('showEditor', true)
            ->assertSet('draft.areas.discretionary', '30.00');

        // O cliente (não consultor) nem vê o botão, e a ação é negada.
        $this->actingAs($this->usuarioAna);
        app(ProfileContext::class)->set($this->perfil, $this->ana);
        Livewire::test(BudgetRuleIndex::class)->assertDontSee('Meu modelo')->call('useTemplate', $modelo->id)->assertForbidden();
    }
}
