<?php

namespace Tests\Feature;

use App\Livewire\CashFlow\CashFlowIndex;
use App\Models\BankAccount;
use App\Models\CreditCard;
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
 * Cadastro manual de despesa/receita no fluxo de caixa. Cobre dinheiro
 * (saldo de conta precisa bater exato) e tenancy (um member_id ou
 * category_id de outro perfil nunca pode vazar pro lançamento — CLAUDE.md
 * regra 1).
 */
class CashFlowManualEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_despesa_debitada_em_conta_atualiza_o_saldo_exato(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['current_balance' => '1000.00']);
        $categoria = ExpenseCategory::factory()->create();
        $subcategoria = ExpenseSubcategory::factory()->create(['category_id' => $categoria->id]);

        Livewire::test(CashFlowIndex::class)
            ->set('expenseDescription', 'Supermercado')
            ->set('expenseAmount', '123.45')
            ->set('expenseDate', '2026-08-10')
            ->set('expenseNecessity', 'essential')
            ->set('expenseCategoryId', $categoria->id)
            ->set('expenseSubcategoryId', $subcategoria->id)
            ->set('expenseBankAccountId', $conta->id)
            ->call('saveExpense')
            ->assertHasNoErrors();

        $conta->refresh();
        self::assertSame('876.55', $conta->current_balance);

        $lancamento = ExpenseRecord::withoutProfileScope()->where('description', 'Supermercado')->firstOrFail();
        self::assertSame('123.45', $lancamento->amount);
        self::assertSame(2026, $lancamento->year);
        self::assertSame(8, $lancamento->month);
        self::assertSame($conta->id, $lancamento->bank_account_id);
    }

    public function test_despesa_sem_conta_selecionada_nao_mexe_em_saldo_nenhum(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['current_balance' => '1000.00']);
        $categoria = ExpenseCategory::factory()->create();
        $subcategoria = ExpenseSubcategory::factory()->create(['category_id' => $categoria->id]);

        Livewire::test(CashFlowIndex::class)
            ->set('expenseDescription', 'Dinheiro no bolso')
            ->set('expenseAmount', '50.00')
            ->set('expenseDate', '2026-08-10')
            ->set('expenseNecessity', 'discretionary')
            ->set('expenseCategoryId', $categoria->id)
            ->set('expenseSubcategoryId', $subcategoria->id)
            ->call('saveExpense')
            ->assertHasNoErrors();

        $conta->refresh();
        self::assertSame('1000.00', $conta->current_balance);
    }

    public function test_despesa_no_cartao_cria_uma_parcela_por_mes_via_installment_service(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $cartao = CreditCard::factory()->for($perfil, 'profile')->for($membro, 'member')->create();
        $categoria = ExpenseCategory::factory()->create();
        $subcategoria = ExpenseSubcategory::factory()->create(['category_id' => $categoria->id]);

        Livewire::test(CashFlowIndex::class)
            ->set('expenseDescription', 'Geladeira nova')
            ->set('expenseAmount', '1000.00')
            ->set('expenseDate', '2026-08-15')
            ->set('expenseNecessity', 'essential')
            ->set('expenseCategoryId', $categoria->id)
            ->set('expenseSubcategoryId', $subcategoria->id)
            ->set('expensePaymentMethod', 'cartao')
            ->set('expenseCreditCardId', $cartao->id)
            ->set('expenseInstallments', 4)
            ->call('saveExpense')
            ->assertHasNoErrors();

        $parcelas = ExpenseRecord::withoutProfileScope()
            ->where('description', 'like', 'Geladeira nova%')
            ->orderBy('installment_number')
            ->get();

        self::assertCount(4, $parcelas);
        self::assertSame(['250.00', '250.00', '250.00', '250.00'], $parcelas->pluck('amount')->all());
        self::assertSame([8, 9, 10, 11], $parcelas->pluck('month')->all());
        // Cada parcela numa fatura diferente — o motor de ciclo funcionando.
        self::assertCount(4, $parcelas->pluck('credit_card_invoice_id')->unique());
    }

    public function test_despesa_marcada_oculta_grava_is_private_e_fica_escondida_do_conjuge(): void
    {
        $titular = User::factory()->create();
        $perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $titular->id]);
        $membroTitular = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $titular->id]);
        $conjuge = User::factory()->create();
        $membroConjuge = ProfileMember::factory()->secondary()->create(['profile_id' => $perfil->id, 'user_id' => $conjuge->id]);
        $this->actingAs($titular);
        app(ProfileContext::class)->set($perfil, $membroTitular);

        $categoria = ExpenseCategory::factory()->create();
        $subcategoria = ExpenseSubcategory::factory()->create(['category_id' => $categoria->id]);

        Livewire::test(CashFlowIndex::class)
            ->set('expenseDescription', 'Tratamento particular')
            ->set('expenseAmount', '400.00')
            ->set('expenseDate', '2026-08-10')
            ->set('expenseNecessity', 'essential')
            ->set('expenseCategoryId', $categoria->id)
            ->set('expenseSubcategoryId', $subcategoria->id)
            ->set('expenseMemberId', $membroTitular->id)
            ->set('expenseIsPrivate', true)
            ->call('saveExpense')
            ->assertHasNoErrors();

        $lancamento = ExpenseRecord::withoutProfileScope()->where('description', 'Tratamento particular')->firstOrFail();
        self::assertTrue($lancamento->is_private);

        $this->actingAs($conjuge);
        app(ProfileContext::class)->set($perfil, $membroConjuge);
        self::assertFalse(ExpenseRecord::all()->contains($lancamento));
    }

    public function test_despesa_da_familia_nunca_grava_como_oculta_mesmo_marcando_o_campo(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $categoria = ExpenseCategory::factory()->create();
        $subcategoria = ExpenseSubcategory::factory()->create(['category_id' => $categoria->id]);

        Livewire::test(CashFlowIndex::class)
            ->set('expenseDescription', 'Aluguel')
            ->set('expenseAmount', '1500.00')
            ->set('expenseDate', '2026-08-10')
            ->set('expenseNecessity', 'essential')
            ->set('expenseCategoryId', $categoria->id)
            ->set('expenseSubcategoryId', $subcategoria->id)
            ->set('expenseMemberId', '') // conjunta/família
            ->set('expenseIsPrivate', true)
            ->call('saveExpense')
            ->assertHasNoErrors();

        $lancamento = ExpenseRecord::withoutProfileScope()->where('description', 'Aluguel')->firstOrFail();
        self::assertFalse($lancamento->is_private);
    }

    public function test_receita_creditada_em_conta_atualiza_o_saldo_exato(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['current_balance' => '500.00']);
        $categoria = IncomeCategory::create(['name' => 'Salário', 'is_default' => true, 'is_active' => true]);

        Livewire::test(CashFlowIndex::class)
            ->set('incomeAmount', '2000.00')
            ->set('incomeDate', '2026-08-05')
            ->set('incomeCategoryId', $categoria->id)
            ->set('incomeBankAccountId', $conta->id)
            ->call('saveIncome')
            ->assertHasNoErrors();

        $conta->refresh();
        self::assertSame('2500.00', $conta->current_balance);

        $lancamento = IncomeRecord::withoutProfileScope()->where('category_id', $categoria->id)->firstOrFail();
        self::assertSame('2000.00', $lancamento->amount);
    }

    public function test_receita_marcada_oculta_grava_is_private(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $categoria = IncomeCategory::create(['name' => 'Freela', 'is_default' => true, 'is_active' => true]);

        Livewire::test(CashFlowIndex::class)
            ->set('incomeAmount', '800.00')
            ->set('incomeDate', '2026-08-05')
            ->set('incomeCategoryId', $categoria->id)
            ->set('incomeMemberId', $membro->id)
            ->set('incomeIsPrivate', true)
            ->call('saveIncome')
            ->assertHasNoErrors();

        $lancamento = IncomeRecord::withoutProfileScope()->where('category_id', $categoria->id)->firstOrFail();
        self::assertTrue($lancamento->is_private);
    }

    public function test_membro_de_outro_perfil_nao_vaza_para_o_lancamento(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $categoria = ExpenseCategory::factory()->create();
        $subcategoria = ExpenseSubcategory::factory()->create(['category_id' => $categoria->id]);

        // NÃO chama criarPerfil() de novo aqui — isso trocaria o
        // ProfileContext ativo pro perfil errado. Só cria o outro perfil e
        // o membro dele, sem mexer no contexto.
        $outroPerfil = FinancialProfile::factory()->create();
        $membroDeOutroPerfil = ProfileMember::factory()->create(['profile_id' => $outroPerfil->id]);

        Livewire::test(CashFlowIndex::class)
            ->set('expenseDescription', 'Tentativa de vazamento')
            ->set('expenseAmount', '10.00')
            ->set('expenseDate', '2026-08-10')
            ->set('expenseNecessity', 'essential')
            ->set('expenseCategoryId', $categoria->id)
            ->set('expenseSubcategoryId', $subcategoria->id)
            ->set('expenseMemberId', $membroDeOutroPerfil->id)
            ->call('saveExpense')
            ->assertHasNoErrors();

        $lancamento = ExpenseRecord::withoutProfileScope()->where('description', 'Tentativa de vazamento')->firstOrFail();
        self::assertNull($lancamento->member_id);
        self::assertSame($perfil->id, $lancamento->profile_id);
    }

    public function test_categoria_de_outro_perfil_nao_permite_salvar(): void
    {
        $outroPerfil = FinancialProfile::factory()->create();
        $categoriaDeOutroPerfil = ExpenseCategory::factory()->custom($outroPerfil)->create();

        // Contexto ativo é OUTRO perfil — a categoria acima não é dele.
        $this->criarPerfil();

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::test(CashFlowIndex::class)
            ->set('expenseDescription', 'Categoria alheia')
            ->set('expenseAmount', '10.00')
            ->set('expenseDate', '2026-08-10')
            ->set('expenseNecessity', 'essential')
            ->set('expenseCategoryId', $categoriaDeOutroPerfil->id)
            // Subcategoria só precisa estar preenchida pra passar da
            // checagem de obrigatoriedade — quem tem que barrar aqui é a
            // categoria de outro perfil, não a subcategoria.
            ->set('expenseSubcategoryId', 'qualquer-coisa')
            ->call('saveExpense');
    }

    /** @return array{0: FinancialProfile, 1: ProfileMember} perfil ativo no ProfileContext + o membro titular */
    private function criarPerfil(): array
    {
        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id]);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($perfil, $membro);

        return [$perfil, $membro];
    }
}
