<?php

namespace Tests\Feature;

use App\Enums\MemberRole;
use App\Enums\Necessity;
use App\Livewire\CashFlow\CashFlowIndex;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Categoria sem `necessity` fixa (a maioria) aparece pra qualquer
 * necessidade escolhida; uma categoria com `necessity` fixa (ex.:
 * "Investimentos") só aparece quando essa for a necessidade escolhida —
 * sem isso, quem marcava necessidade "Investimento" não tinha categoria
 * nenhuma que fizesse sentido pra escolher.
 */
class ExpenseCategoryByNecessityTest extends TestCase
{
    use RefreshDatabase;

    public function test_categoria_sem_necessidade_fixa_aparece_pra_qualquer_necessidade(): void
    {
        $this->criarPerfil();
        $generica = ExpenseCategory::factory()->create(['necessity' => null]);

        $component = Livewire::test(CashFlowIndex::class)->set('expenseNecessity', 'essential');
        self::assertTrue($component->get('expenseFormCategories')->contains('id', $generica->id));

        $component->set('expenseNecessity', 'investment');
        self::assertTrue($component->get('expenseFormCategories')->contains('id', $generica->id));
    }

    public function test_categoria_de_investimento_so_aparece_quando_necessidade_e_investimento(): void
    {
        $this->criarPerfil();
        $investimentos = ExpenseCategory::factory()->create(['necessity' => Necessity::Investment]);

        $component = Livewire::test(CashFlowIndex::class)->set('expenseNecessity', 'essential');
        self::assertFalse($component->get('expenseFormCategories')->contains('id', $investimentos->id));

        $component->set('expenseNecessity', 'investment');
        self::assertTrue($component->get('expenseFormCategories')->contains('id', $investimentos->id));
    }

    public function test_sem_necessidade_escolhida_categoria_de_investimento_fica_fora(): void
    {
        $this->criarPerfil();
        $investimentos = ExpenseCategory::factory()->create(['necessity' => Necessity::Investment]);

        $component = Livewire::test(CashFlowIndex::class);

        self::assertFalse($component->get('expenseFormCategories')->contains('id', $investimentos->id));
    }

    /**
     * Só limpa quando a categoria escolhida DEIXA de valer pra nova
     * necessidade — categoria sem necessidade fixa continua válida pra
     * qualquer necessidade, então não deveria ser apagada à toa (isso
     * quebraria, por exemplo, o formulário de editar chamando
     * `set('expenseNecessity', ...)` com o mesmo valor de sempre).
     */
    public function test_trocar_necessidade_limpa_categoria_que_deixou_de_valer(): void
    {
        [$perfil] = $this->criarPerfil();
        $investimentos = ExpenseCategory::factory()->create(['necessity' => Necessity::Investment]);

        Livewire::test(CashFlowIndex::class)
            ->set('expenseNecessity', 'investment')
            ->set('expenseCategoryId', $investimentos->id)
            ->set('expenseSubcategoryId', 'algum-id')
            ->set('expenseNecessity', 'essential')
            ->assertSet('expenseCategoryId', '')
            ->assertSet('expenseSubcategoryId', '');
    }

    public function test_trocar_necessidade_mantem_categoria_que_continua_valida(): void
    {
        [$perfil] = $this->criarPerfil();
        $categoria = ExpenseCategory::factory()->create(['necessity' => null]);

        Livewire::test(CashFlowIndex::class)
            ->set('expenseCategoryId', $categoria->id)
            ->set('expenseNecessity', 'investment')
            ->assertSet('expenseCategoryId', $categoria->id);
    }

    public function test_editar_despesa_de_investimento_mostra_a_categoria_certa(): void
    {
        [$perfil] = $this->criarPerfil();
        $investimentos = ExpenseCategory::factory()->create(['necessity' => Necessity::Investment]);
        $despesa = ExpenseRecord::factory()->for($perfil, 'profile')->create([
            'necessity' => Necessity::Investment,
            'category_id' => $investimentos->id,
        ]);

        $component = Livewire::test(CashFlowIndex::class)->call('editExpense', $despesa->id);

        self::assertTrue($component->get('expenseFormCategories')->contains('id', $investimentos->id));
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
