<?php

namespace Tests\Feature;

use App\Enums\MemberRole;
use App\Enums\Necessity;
use App\Livewire\CategorizationRules\CategorizationRulesIndex;
use App\Models\ExpenseCategorizationRule;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\ExpenseSubcategory;
use App\Models\FinancialProfile;
use App\Models\IncomeCategorizationRule;
use App\Models\IncomeCategory;
use App\Models\IncomeRecord;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CategorizationRulesManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_regra_de_despesa(): void
    {
        [$perfil] = $this->criarPerfil();
        $categoria = ExpenseCategory::factory()->create();
        $subcategoria = ExpenseSubcategory::factory()->create(['category_id' => $categoria->id]);

        Livewire::test(CategorizationRulesIndex::class)
            ->set('expensePattern', 'ADRIANA')
            ->set('expenseCategoryId', $categoria->id)
            ->set('expenseSubcategoryId', $subcategoria->id)
            ->set('expenseNecessity', 'essential')
            ->call('saveExpenseRule')
            ->assertHasNoErrors();

        $regra = ExpenseCategorizationRule::sole();
        self::assertSame('ADRIANA', $regra->pattern);
        self::assertSame($categoria->id, $regra->category_id);
        self::assertSame($perfil->id, $regra->profile_id);
    }

    /** Motivado por um caso real: PIX mensal pra si mesmo — o valor exato evita que a regra casse qualquer PIX daquele nome. */
    public function test_cria_regra_de_despesa_com_valor_exato(): void
    {
        [$perfil] = $this->criarPerfil();
        $categoria = ExpenseCategory::factory()->create();
        $subcategoria = ExpenseSubcategory::factory()->create(['category_id' => $categoria->id]);

        Livewire::test(CategorizationRulesIndex::class)
            ->set('expensePattern', 'PIX MARCELO')
            ->set('expenseAmount', '199.58')
            ->set('expenseCategoryId', $categoria->id)
            ->set('expenseSubcategoryId', $subcategoria->id)
            ->set('expenseNecessity', 'essential')
            ->call('saveExpenseRule')
            ->assertHasNoErrors();

        self::assertSame('199.58', ExpenseCategorizationRule::sole()->amount);
    }

    public function test_valor_exato_e_opcional_e_fica_nulo_quando_nao_preenchido(): void
    {
        [$perfil] = $this->criarPerfil();
        $categoria = ExpenseCategory::factory()->create();
        $subcategoria = ExpenseSubcategory::factory()->create(['category_id' => $categoria->id]);

        Livewire::test(CategorizationRulesIndex::class)
            ->set('expensePattern', 'ADRIANA')
            ->set('expenseCategoryId', $categoria->id)
            ->set('expenseSubcategoryId', $subcategoria->id)
            ->set('expenseNecessity', 'essential')
            ->call('saveExpenseRule')
            ->assertHasNoErrors();

        self::assertNull(ExpenseCategorizationRule::sole()->amount);
    }

    public function test_editar_regra_de_despesa_preenche_o_valor_exato_ja_cadastrado(): void
    {
        [$perfil] = $this->criarPerfil();
        $regra = ExpenseCategorizationRule::factory()->for($perfil, 'profile')->create(['amount' => '199.58']);

        Livewire::test(CategorizationRulesIndex::class)
            ->call('editExpenseRule', $regra->id)
            ->assertSet('expenseAmount', '199.58');
    }

    public function test_cria_regra_de_receita_com_valor_exato(): void
    {
        [$perfil] = $this->criarPerfil();
        $categoria = IncomeCategory::factory()->create();

        Livewire::test(CategorizationRulesIndex::class)
            ->set('incomePattern', 'REEMBOLSO')
            ->set('incomeAmount', '500.00')
            ->set('incomeCategoryId', $categoria->id)
            ->call('saveIncomeRule')
            ->assertHasNoErrors();

        self::assertSame('500.00', IncomeCategorizationRule::sole()->amount);
    }

    public function test_editar_regra_de_despesa_atualiza_o_registro_existente(): void
    {
        [$perfil] = $this->criarPerfil();
        $categoria = ExpenseCategory::factory()->create();
        $regra = ExpenseCategorizationRule::factory()->for($perfil, 'profile')->create(['pattern' => 'ORIGINAL', 'category_id' => $categoria->id]);

        Livewire::test(CategorizationRulesIndex::class)
            ->call('editExpenseRule', $regra->id)
            ->set('expensePattern', 'EDITADO')
            ->call('saveExpenseRule')
            ->assertHasNoErrors();

        self::assertSame(1, ExpenseCategorizationRule::count());
        self::assertSame('EDITADO', $regra->fresh()->pattern);
    }

    public function test_excluir_regra_de_despesa(): void
    {
        [$perfil] = $this->criarPerfil();
        $regra = ExpenseCategorizationRule::factory()->for($perfil, 'profile')->create();

        Livewire::test(CategorizationRulesIndex::class)
            ->call('confirmDeleteExpenseRule', $regra->id)
            ->call('deleteExpenseRule', $regra->id);

        self::assertSame(0, ExpenseCategorizationRule::count());
    }

    public function test_regra_de_despesa_exige_padrao_e_categoria(): void
    {
        $this->criarPerfil();

        Livewire::test(CategorizationRulesIndex::class)
            ->set('expensePattern', '')
            ->set('expenseCategoryId', '')
            ->call('saveExpenseRule')
            ->assertHasErrors(['expensePattern', 'expenseCategoryId']);
    }

    public function test_regra_de_despesa_sem_subcategoria_e_bloqueada_fora_de_investimento(): void
    {
        $this->criarPerfil();
        $categoria = ExpenseCategory::factory()->create();

        Livewire::test(CategorizationRulesIndex::class)
            ->set('expensePattern', 'ADRIANA')
            ->set('expenseCategoryId', $categoria->id)
            ->set('expenseNecessity', 'essential')
            ->call('saveExpenseRule')
            ->assertHasErrors(['expenseSubcategoryId']);

        self::assertSame(0, ExpenseCategorizationRule::count());
    }

    public function test_regra_de_despesa_de_investimento_nao_exige_subcategoria(): void
    {
        [$perfil] = $this->criarPerfil();
        $investimentos = ExpenseCategory::factory()->create(['necessity' => Necessity::Investment]);

        Livewire::test(CategorizationRulesIndex::class)
            ->set('expensePattern', 'APORTE')
            ->set('expenseCategoryId', $investimentos->id)
            ->set('expenseNecessity', 'investment')
            ->call('saveExpenseRule')
            ->assertHasNoErrors();

        $regra = ExpenseCategorizationRule::sole();
        self::assertNull($regra->subcategory_id);
        self::assertSame(Necessity::Investment, $regra->necessity);
    }

    public function test_cria_regra_de_receita(): void
    {
        [$perfil] = $this->criarPerfil();
        $categoria = IncomeCategory::factory()->create();

        Livewire::test(CategorizationRulesIndex::class)
            ->set('incomePattern', 'SALARIO')
            ->set('incomeCategoryId', $categoria->id)
            ->call('saveIncomeRule')
            ->assertHasNoErrors();

        $regra = IncomeCategorizationRule::sole();
        self::assertSame('SALARIO', $regra->pattern);
        self::assertSame($perfil->id, $regra->profile_id);
    }

    public function test_excluir_regra_de_receita(): void
    {
        [$perfil] = $this->criarPerfil();
        $regra = IncomeCategorizationRule::factory()->for($perfil, 'profile')->create();

        Livewire::test(CategorizationRulesIndex::class)
            ->call('confirmDeleteIncomeRule', $regra->id)
            ->call('deleteIncomeRule', $regra->id);

        self::assertSame(0, IncomeCategorizationRule::count());
    }

    public function test_criar_regra_detecta_lancamentos_existentes_que_batem_com_o_padrao(): void
    {
        [$perfil] = $this->criarPerfil();
        $categoriaAntiga = ExpenseCategory::factory()->create();
        $categoriaNova = ExpenseCategory::factory()->create();
        $subcategoriaNova = ExpenseSubcategory::factory()->create(['category_id' => $categoriaNova->id]);
        $bate1 = ExpenseRecord::factory()->for($perfil, 'profile')->create(['description' => 'Pix Adriana', 'category_id' => $categoriaAntiga->id]);
        $bate2 = ExpenseRecord::factory()->for($perfil, 'profile')->create(['description' => 'PIX ADRIANA RODRIGUES', 'category_id' => $categoriaAntiga->id]);
        $naoBate = ExpenseRecord::factory()->for($perfil, 'profile')->create(['description' => 'Mercado', 'category_id' => $categoriaAntiga->id]);

        $component = Livewire::test(CategorizationRulesIndex::class)
            ->set('expensePattern', 'adriana')
            ->set('expenseCategoryId', $categoriaNova->id)
            ->set('expenseSubcategoryId', $subcategoriaNova->id)
            ->set('expenseNecessity', 'essential')
            ->call('saveExpenseRule')
            ->assertHasNoErrors();

        $oferta = $component->get('regraAplicavelExistentes');
        self::assertNotNull($oferta);
        self::assertSame(2, $oferta['quantidade']);
        self::assertEqualsCanonicalizing([$bate1->id, $bate2->id], $oferta['ids']);

        // Ainda não mudou nada — só a oferta apareceu.
        self::assertSame($categoriaAntiga->id, $bate1->fresh()->category_id);
        self::assertSame($categoriaAntiga->id, $naoBate->fresh()->category_id);
    }

    public function test_aplicar_regra_recategoriza_os_lancamentos_existentes(): void
    {
        [$perfil] = $this->criarPerfil();
        $categoriaAntiga = ExpenseCategory::factory()->create();
        $categoriaNova = ExpenseCategory::factory()->create();
        $subcategoriaNova = ExpenseSubcategory::factory()->create(['category_id' => $categoriaNova->id]);
        $bate = ExpenseRecord::factory()->for($perfil, 'profile')->create([
            'description' => 'Pix Adriana', 'category_id' => $categoriaAntiga->id, 'necessity' => Necessity::Discretionary,
        ]);

        Livewire::test(CategorizationRulesIndex::class)
            ->set('expensePattern', 'adriana')
            ->set('expenseCategoryId', $categoriaNova->id)
            ->set('expenseSubcategoryId', $subcategoriaNova->id)
            ->set('expenseNecessity', 'essential')
            ->call('saveExpenseRule')
            ->call('aplicarRegraAosExistentes')
            ->assertSet('regraAplicavelExistentes', null);

        $bate->refresh();
        self::assertSame($categoriaNova->id, $bate->category_id);
        self::assertSame(Necessity::Essential, $bate->necessity);
    }

    public function test_descartar_nao_altera_os_lancamentos_existentes(): void
    {
        [$perfil] = $this->criarPerfil();
        $categoriaAntiga = ExpenseCategory::factory()->create();
        $categoriaNova = ExpenseCategory::factory()->create();
        $subcategoriaNova = ExpenseSubcategory::factory()->create(['category_id' => $categoriaNova->id]);
        $bate = ExpenseRecord::factory()->for($perfil, 'profile')->create(['description' => 'Pix Adriana', 'category_id' => $categoriaAntiga->id]);

        Livewire::test(CategorizationRulesIndex::class)
            ->set('expensePattern', 'adriana')
            ->set('expenseCategoryId', $categoriaNova->id)
            ->set('expenseSubcategoryId', $subcategoriaNova->id)
            ->call('saveExpenseRule')
            ->assertHasNoErrors()
            ->call('descartarAplicacaoAosExistentes')
            ->assertSet('regraAplicavelExistentes', null);

        self::assertSame($categoriaAntiga->id, $bate->fresh()->category_id);
    }

    public function test_sem_lancamento_existente_batendo_nao_mostra_oferta(): void
    {
        [$perfil] = $this->criarPerfil();
        $categoria = ExpenseCategory::factory()->create();
        $subcategoria = ExpenseSubcategory::factory()->create(['category_id' => $categoria->id]);

        $component = Livewire::test(CategorizationRulesIndex::class)
            ->set('expensePattern', 'PADRAO-INEDITO')
            ->set('expenseCategoryId', $categoria->id)
            ->set('expenseSubcategoryId', $subcategoria->id)
            ->call('saveExpenseRule')
            ->assertHasNoErrors();

        self::assertNull($component->get('regraAplicavelExistentes'));
    }

    public function test_regra_de_receita_tambem_detecta_e_aplica_aos_existentes(): void
    {
        [$perfil] = $this->criarPerfil();
        $categoriaAntiga = IncomeCategory::factory()->create();
        $categoriaNova = IncomeCategory::factory()->create();
        $bate = IncomeRecord::factory()->for($perfil, 'profile')->create(['description' => 'Salario Empresa X', 'category_id' => $categoriaAntiga->id]);

        Livewire::test(CategorizationRulesIndex::class)
            ->set('incomePattern', 'salario')
            ->set('incomeCategoryId', $categoriaNova->id)
            ->call('saveIncomeRule')
            ->call('aplicarRegraAosExistentes');

        self::assertSame($categoriaNova->id, $bate->fresh()->category_id);
    }

    /** Regra de outro perfil não aparece nem pode ser editada — mesmo isolamento de BelongsToProfile. */
    public function test_isolamento_por_perfil(): void
    {
        $outroPerfil = FinancialProfile::factory()->create();
        $regraDeOutroPerfil = ExpenseCategorizationRule::factory()->for($outroPerfil, 'profile')->create();

        $this->criarPerfil();

        $component = Livewire::test(CategorizationRulesIndex::class);
        self::assertTrue($component->get('expenseRules')->doesntContain('id', $regraDeOutroPerfil->id));

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $component->call('editExpenseRule', $regraDeOutroPerfil->id);
    }

    /** @return array{0: FinancialProfile} */
    private function criarPerfil(): array
    {
        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id, 'role' => MemberRole::Primary]);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($perfil, $membro);

        return [$perfil];
    }
}
