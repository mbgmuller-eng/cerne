<?php

namespace Tests\Feature;

use App\Enums\MemberRole;
use App\Livewire\CashFlow\CashFlowIndex;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\ExpenseSubcategory;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Filtro por subcategoria no Fluxo de caixa. "Delivery" é subcategoria de Alimentação e de Lazer: o filtro é pelo NOME, então a soma
 * junta as duas, e o total do mês (o cartão "Despesas") acompanha o recorte.
 */
class CashFlowSubcategoryFilterTest extends TestCase
{
    use RefreshDatabase;

    private FinancialProfile $perfil;

    private ExpenseCategory $alimentacao;

    private ExpenseCategory $lazer;

    private ExpenseSubcategory $deliveryAlimentacao;

    private ExpenseSubcategory $deliveryLazer;

    private ExpenseSubcategory $mercado;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-18 10:00:00'));

        $usuario = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $usuario->id, 'role' => MemberRole::Primary]);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($this->perfil, $membro);

        $this->alimentacao = ExpenseCategory::factory()->create(['name' => 'Alimentação']);
        $this->lazer = ExpenseCategory::factory()->create(['name' => 'Lazer']);
        $this->deliveryAlimentacao = ExpenseSubcategory::factory()->create(['category_id' => $this->alimentacao->id, 'name' => 'Delivery']);
        $this->deliveryLazer = ExpenseSubcategory::factory()->create(['category_id' => $this->lazer->id, 'name' => 'Delivery']);
        $this->mercado = ExpenseSubcategory::factory()->create(['category_id' => $this->alimentacao->id, 'name' => 'Supermercado']);
    }

    private function despesa(ExpenseSubcategory $sub, string $valor, string $data = '2026-10-10'): ExpenseRecord
    {
        return ExpenseRecord::factory()->for($this->perfil, 'profile')->create([
            'category_id' => $sub->category_id,
            'subcategory_id' => $sub->id,
            'amount' => $valor,
            'expense_date' => $data,
        ]);
    }

    public function test_o_filtro_por_nome_junta_a_subcategoria_de_todas_as_categorias_e_o_total_acompanha(): void
    {
        $this->despesa($this->deliveryAlimentacao, '120.50');
        $this->despesa($this->deliveryLazer, '80.00');
        $this->despesa($this->mercado, '400.00');
        $this->despesa($this->deliveryAlimentacao, '55.00', '2026-09-20'); // outro mês: fica de fora

        $tela = Livewire::test(CashFlowIndex::class, ['year' => 2026, 'month' => 10]);

        $tela->assertSet('subcategory', '')->assertSee('R$ 600,50');

        $tela->set('subcategory', 'Delivery');

        self::assertSame('200.50', $tela->get('totalExpense'));
        self::assertCount(2, $tela->get('expenses'));
        $tela->assertSee('R$ 200,50');
    }

    public function test_com_categoria_escolhida_a_lista_do_filtro_so_traz_as_subcategorias_dela(): void
    {
        $tela = Livewire::test(CashFlowIndex::class, ['year' => 2026, 'month' => 10]);

        $tela->assertSeeInOrder(['Subcategoria', 'Delivery', 'Supermercado']);

        $tela->set('categoryId', $this->lazer->id);

        self::assertSame(['Delivery'], $this->opcoes($tela));
    }

    public function test_categoria_e_subcategoria_juntas_estreitam_o_recorte(): void
    {
        $this->despesa($this->deliveryAlimentacao, '120.50');
        $this->despesa($this->deliveryLazer, '80.00');

        $tela = Livewire::test(CashFlowIndex::class, ['year' => 2026, 'month' => 10])
            ->set('categoryId', $this->lazer->id)
            ->set('subcategory', 'Delivery');

        self::assertSame('80.00', $tela->get('totalExpense'));
    }

    public function test_trocar_a_categoria_solta_a_subcategoria_que_nao_existe_nela(): void
    {
        $tela = Livewire::test(CashFlowIndex::class, ['year' => 2026, 'month' => 10])
            ->set('subcategory', 'Supermercado')
            ->set('categoryId', $this->lazer->id)
            ->assertSet('subcategory', '');

        // Uma que existe nas duas categorias continua escolhida.
        $tela->set('subcategory', 'Delivery')->set('categoryId', $this->alimentacao->id)->assertSet('subcategory', 'Delivery');
    }

    public function test_limpar_filtros_tira_tambem_a_subcategoria(): void
    {
        Livewire::test(CashFlowIndex::class, ['year' => 2026, 'month' => 10])
            ->set('subcategory', 'Delivery')
            ->assertSee('Limpar filtros')
            ->call('clearFilters')
            ->assertSet('subcategory', '');
    }

    public function test_nao_mistura_despesa_de_outro_perfil(): void
    {
        $this->despesa($this->deliveryAlimentacao, '100.00');

        $outroUsuario = User::factory()->create();
        $outroPerfil = FinancialProfile::factory()->create(['owner_user_id' => $outroUsuario->id]);
        ExpenseRecord::factory()->for($outroPerfil, 'profile')->create([
            'category_id' => $this->alimentacao->id,
            'subcategory_id' => $this->deliveryAlimentacao->id,
            'amount' => '999.00',
            'expense_date' => '2026-10-11',
        ]);

        $tela = Livewire::test(CashFlowIndex::class, ['year' => 2026, 'month' => 10])->set('subcategory', 'Delivery');

        self::assertSame('100.00', $tela->get('totalExpense'));
    }

    /** @return list<string> nomes oferecidos no filtro (as opções do select, sem a "Todas") */
    private function opcoes($tela): array
    {
        preg_match('/<select[^>]*data-subcategory-filter[^>]*>(.*?)<\/select>/s', $tela->html(), $m);
        preg_match_all('/<option value="([^"]+)">/', $m[1] ?? '', $opcoes);

        return $opcoes[1];
    }
}
