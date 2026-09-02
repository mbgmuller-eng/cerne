<?php

namespace Tests\Feature;

use App\Enums\MemberRole;
use App\Enums\Necessity;
use App\Livewire\CashFlow\CashFlowIndex;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\ExpenseSubcategory;
use App\Models\FinancialProfile;
use App\Models\IncomeCategory;
use App\Models\IncomeRecord;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Editar um lançamento pode oferecer aplicar a mesma categoria a outros com
 * a mesma descrição e valor — nunca sozinho (descrição igual não garante
 * ser a mesma coisa de verdade), sempre com confirmação explícita.
 */
class CashFlowApplyToDuplicatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_editar_despesa_detecta_outros_com_mesma_descricao_e_valor(): void
    {
        [$perfil] = $this->criarPerfil();
        $categoriaAntiga = ExpenseCategory::factory()->create();
        $categoriaNova = ExpenseCategory::factory()->create();
        $subcategoriaNova = ExpenseSubcategory::create([
            'profile_id' => $perfil->id, 'category_id' => $categoriaNova->id, 'name' => 'Diarista',
            'is_customizada' => false, 'is_active' => true,
        ]);

        $editada = ExpenseRecord::factory()->for($perfil, 'profile')->create([
            'description' => 'Pix Adriana', 'amount' => '230.00', 'category_id' => $categoriaAntiga->id,
        ]);
        $irmao1 = ExpenseRecord::factory()->for($perfil, 'profile')->create([
            'description' => 'Pix Adriana', 'amount' => '230.00', 'category_id' => $categoriaAntiga->id,
        ]);
        $irmao2 = ExpenseRecord::factory()->for($perfil, 'profile')->create([
            'description' => 'Pix Adriana', 'amount' => '230.00', 'category_id' => $categoriaAntiga->id,
        ]);

        $component = Livewire::test(CashFlowIndex::class)
            ->call('editExpense', $editada->id)
            ->set('expenseCategoryId', $categoriaNova->id)
            ->set('expenseSubcategoryId', $subcategoriaNova->id)
            ->call('saveExpense')
            ->assertHasNoErrors();

        $duplicatas = $component->get('duplicatas');
        self::assertNotNull($duplicatas);
        self::assertSame('despesa', $duplicatas['tipo']);
        self::assertSame(2, $duplicatas['quantidade']);
        self::assertEqualsCanonicalizing([$irmao1->id, $irmao2->id], $duplicatas['ids']);
        self::assertSame($categoriaNova->id, $duplicatas['categoria_id']);

        // Os irmãos ainda não mudaram — só a oferta apareceu.
        self::assertSame($categoriaAntiga->id, $irmao1->fresh()->category_id);
    }

    public function test_aplicar_atualiza_categoria_subcategoria_e_necessidade_dos_duplicados(): void
    {
        [$perfil] = $this->criarPerfil();
        $categoriaAntiga = ExpenseCategory::factory()->create();
        $categoriaNova = ExpenseCategory::factory()->create();
        $subcategoriaNova = ExpenseSubcategory::create([
            'profile_id' => $perfil->id, 'category_id' => $categoriaNova->id, 'name' => 'Diarista',
            'is_customizada' => false, 'is_active' => true,
        ]);

        $editada = ExpenseRecord::factory()->for($perfil, 'profile')->create([
            'description' => 'Pix Adriana', 'amount' => '230.00', 'category_id' => $categoriaAntiga->id,
            'necessity' => Necessity::Discretionary,
        ]);
        $irmao = ExpenseRecord::factory()->for($perfil, 'profile')->create([
            'description' => 'Pix Adriana', 'amount' => '230.00', 'category_id' => $categoriaAntiga->id,
            'necessity' => Necessity::Discretionary,
        ]);

        Livewire::test(CashFlowIndex::class)
            ->call('editExpense', $editada->id)
            ->set('expenseCategoryId', $categoriaNova->id)
            ->set('expenseSubcategoryId', $subcategoriaNova->id)
            ->set('expenseNecessity', 'essential')
            ->call('saveExpense')
            ->call('aplicarCategoriaAosDuplicados')
            ->assertSet('duplicatas', null);

        $irmao->refresh();
        self::assertSame($categoriaNova->id, $irmao->category_id);
        self::assertSame($subcategoriaNova->id, $irmao->subcategory_id);
        self::assertSame(Necessity::Essential, $irmao->necessity);
    }

    public function test_descartar_nao_altera_os_duplicados(): void
    {
        [$perfil] = $this->criarPerfil();
        $categoriaAntiga = ExpenseCategory::factory()->create();
        $categoriaNova = ExpenseCategory::factory()->create();

        $editada = ExpenseRecord::factory()->for($perfil, 'profile')->create([
            'description' => 'Pix Adriana', 'amount' => '230.00', 'category_id' => $categoriaAntiga->id,
        ]);
        $irmao = ExpenseRecord::factory()->for($perfil, 'profile')->create([
            'description' => 'Pix Adriana', 'amount' => '230.00', 'category_id' => $categoriaAntiga->id,
        ]);

        Livewire::test(CashFlowIndex::class)
            ->call('editExpense', $editada->id)
            ->set('expenseCategoryId', $categoriaNova->id)
            ->call('saveExpense')
            ->call('descartarDuplicatas')
            ->assertSet('duplicatas', null);

        self::assertSame($categoriaAntiga->id, $irmao->fresh()->category_id);
    }

    public function test_valor_diferente_nao_conta_como_duplicata(): void
    {
        [$perfil] = $this->criarPerfil();
        $categoria = ExpenseCategory::factory()->create();

        $editada = ExpenseRecord::factory()->for($perfil, 'profile')->create([
            'description' => 'Pix Adriana', 'amount' => '230.00', 'category_id' => $categoria->id,
        ]);
        ExpenseRecord::factory()->for($perfil, 'profile')->create([
            'description' => 'Pix Adriana', 'amount' => '50.00', 'category_id' => $categoria->id,
        ]);

        $component = Livewire::test(CashFlowIndex::class)
            ->call('editExpense', $editada->id)
            ->set('expenseAmount', '230.00')
            ->call('saveExpense');

        self::assertNull($component->get('duplicatas'));
    }

    public function test_receita_tambem_detecta_e_aplica(): void
    {
        [$perfil] = $this->criarPerfil();
        $categoriaAntiga = IncomeCategory::factory()->create();
        $categoriaNova = IncomeCategory::factory()->create();

        $editada = IncomeRecord::factory()->for($perfil, 'profile')->create([
            'description' => 'Reembolso', 'amount' => '100.00', 'category_id' => $categoriaAntiga->id,
        ]);
        $irmao = IncomeRecord::factory()->for($perfil, 'profile')->create([
            'description' => 'Reembolso', 'amount' => '100.00', 'category_id' => $categoriaAntiga->id,
        ]);

        Livewire::test(CashFlowIndex::class)
            ->call('editIncome', $editada->id)
            ->set('incomeCategoryId', $categoriaNova->id)
            ->call('saveIncome')
            ->call('aplicarCategoriaAosDuplicados');

        self::assertSame($categoriaNova->id, $irmao->fresh()->category_id);
    }

    public function test_isolamento_por_perfil_nao_conta_lancamento_de_outro_perfil(): void
    {
        $outroPerfil = FinancialProfile::factory()->create();
        ExpenseRecord::factory()->for($outroPerfil, 'profile')->create([
            'description' => 'Pix Adriana', 'amount' => '230.00',
        ]);

        [$perfil] = $this->criarPerfil();
        $categoria = ExpenseCategory::factory()->create();
        $editada = ExpenseRecord::factory()->for($perfil, 'profile')->create([
            'description' => 'Pix Adriana', 'amount' => '230.00', 'category_id' => $categoria->id,
        ]);

        $component = Livewire::test(CashFlowIndex::class)
            ->call('editExpense', $editada->id)
            ->set('expenseAmount', '230.00')
            ->call('saveExpense');

        self::assertNull($component->get('duplicatas'));
    }

    /** @return array{0: FinancialProfile, 1: ProfileMember} */
    private function criarPerfil(): array
    {
        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id, 'role' => MemberRole::Primary]);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($perfil, $membro);

        return [$perfil, $membro];
    }
}
