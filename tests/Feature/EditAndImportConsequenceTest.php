<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\InvoiceStatus;
use App\Enums\MemberRole;
use App\Enums\ProcessingStatus;
use App\Livewire\CashFlow\CashFlowIndex;
use App\Livewire\Debts\DebtShow;
use App\Livewire\Documents\DocumentsIndex;
use App\Livewire\FixedBills\FixedBillsIndex;
use App\Livewire\Insurance\InsuranceIndex;
use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\Debt;
use App\Models\DebtInstallment;
use App\Models\DocumentUpload;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\ExpenseSubcategory;
use App\Models\FinancialProfile;
use App\Models\FixedBill;
use App\Models\IncomeCategory;
use App\Models\IncomeRecord;
use App\Models\InsurancePolicy;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\DebtService;
use App\Support\Debts\DebtClassification;
use App\Support\Forms\FormConsequence;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Telas de edição e revisão da importação: o quadro "Ao salvar" diz o que a edição faz de fato (saldo desfeito e refeito, fatura que não troca,
 * dono da dívida que vale para as parcelas) e o que "Importar selecionados" vai criar. Cada teste confere também o que é gravado.
 */
class EditAndImportConsequenceTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private FinancialProfile $perfil;

    private ProfileMember $membro;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('pt_BR');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 09:00:00'));

        $this->usuario = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->usuario->id]);
        $this->membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuario->id, 'role' => MemberRole::Primary, 'name' => 'Marcelo']);
        $this->actingAs($this->usuario);
        app(ProfileContext::class)->set($this->perfil, $this->membro);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ fluxo de caixa

    public function test_editar_despesa_na_mesma_conta_diz_o_saldo_depois_de_desfazer_e_refazer(): void
    {
        $conta = BankAccount::factory()->for($this->perfil, 'profile')->create(['current_balance' => '1000.00']);
        $despesa = ExpenseRecord::factory()->for($this->perfil, 'profile')->create(['amount' => '100.00', 'bank_account_id' => $conta->id, 'expense_date' => '2026-10-10']);
        $conta->applyToBalance('-100.00');
        $conta->refresh();

        // Saldo de hoje 900: desfaz os 100 (1000) e tira os 250 (750).
        Livewire::test(CashFlowIndex::class)
            ->call('editExpense', $despesa->id)
            ->set('expenseAmount', '250.00')
            ->assertSee('O saldo de '.$conta->displayName().' passa de R$ 900,00 para R$ 750,00 ao salvar.')
            ->call('saveExpense')->assertHasNoErrors();

        $this->assertSame('750.00', $conta->refresh()->current_balance);
    }

    public function test_editar_despesa_trocando_de_conta_diz_o_que_acontece_com_as_duas(): void
    {
        $antiga = BankAccount::factory()->for($this->perfil, 'profile')->create(['current_balance' => '500.00']);
        $nova = BankAccount::factory()->for($this->perfil, 'profile')->create(['current_balance' => '2000.00']);
        $despesa = ExpenseRecord::factory()->for($this->perfil, 'profile')->create(['amount' => '100.00', 'bank_account_id' => $antiga->id, 'expense_date' => '2026-10-10']);

        Livewire::test(CashFlowIndex::class)
            ->call('editExpense', $despesa->id)
            ->set('expenseBankAccountId', $nova->id)
            ->assertSee('O lançamento deixa '.$antiga->displayName().': o saldo passa de R$ 500,00 para R$ 600,00.')
            ->assertSee('E passa para '.$nova->displayName().': o saldo vai de R$ 2.000,00 para R$ 1.900,00.')
            ->call('saveExpense')->assertHasNoErrors();

        $this->assertSame('600.00', $antiga->refresh()->current_balance);
        $this->assertSame('1900.00', $nova->refresh()->current_balance);
    }

    public function test_editar_despesa_de_cartao_diz_que_a_fatura_nao_muda(): void
    {
        $cartao = CreditCard::factory()->for($this->perfil, 'profile')->create();
        $aberta = $this->fatura($cartao, 8, InvoiceStatus::Open);
        $livre = ExpenseRecord::factory()->for($this->perfil, 'profile')->create(['credit_card_id' => $cartao->id, 'credit_card_invoice_id' => $aberta->id, 'expense_date' => '2026-10-05']);

        Livewire::test(CashFlowIndex::class)
            ->call('editExpense', $livre->id)
            ->assertSee('Esta compra continua na fatura de '.FormConsequence::monthYear(2026, 8).', mesmo que você mude a data.');
    }

    public function test_editar_receita_diz_o_saldo_depois_de_desfazer_o_credito_antigo(): void
    {
        $conta = BankAccount::factory()->for($this->perfil, 'profile')->create(['current_balance' => '1000.00']);
        $receita = IncomeRecord::factory()->for($this->perfil, 'profile')->create([
            'amount' => '500.00', 'bank_account_id' => $conta->id, 'category_id' => IncomeCategory::factory()->create()->id, 'received_date' => '2026-10-10',
        ]);

        // O saldo de hoje (1000) já tem os 500: tira e põe 800 => 1300.
        Livewire::test(CashFlowIndex::class)
            ->call('editIncome', $receita->id)
            ->set('incomeAmount', '800.00')
            ->assertSee('O saldo de '.$conta->displayName().' passa de R$ 1.000,00 para R$ 1.300,00 ao salvar.')
            ->call('saveIncome')->assertHasNoErrors();

        $this->assertSame('1300.00', $conta->refresh()->current_balance);
    }

    private function fatura(CreditCard $cartao, int $mes, InvoiceStatus $status): CreditCardInvoice
    {
        return CreditCardInvoice::create([
            'profile_id' => $this->perfil->id, 'credit_card_id' => $cartao->id, 'year' => 2026, 'month' => $mes,
            'closing_date' => '2026-'.$mes.'-20', 'due_date' => '2026-'.$mes.'-27', 'total_amount' => '330.00', 'status' => $status,
            'paid_at' => $status === InvoiceStatus::Paid ? now() : null, 'paid_amount' => $status === InvoiceStatus::Paid ? '330.00' : null,
        ]);
    }

    // ------------------------------------------------------------------ conta fixa e apólice

    public function test_editar_conta_fixa_avisa_que_ocultar_nao_muda_o_que_ja_foi_lancado(): void
    {
        $this->seed(TaxonomySeeder::class);
        $conta = FixedBill::factory()->for($this->perfil, 'profile')->create(['member_id' => $this->membro->id, 'is_private' => false]);

        Livewire::test(FixedBillsIndex::class)
            ->call('editBill', $conta->id)
            ->assertDontSee('Ocultar ou mostrar vale já para os vencimentos')
            ->set('billIsPrivate', true)
            ->assertSee('Ocultar ou mostrar vale já para os vencimentos. As despesas que pagamentos anteriores criaram no fluxo de caixa ficam como estão.');
    }

    public function test_editar_apolice_avisa_que_a_mudanca_entra_na_linha_do_tempo(): void
    {
        $apolice = InsurancePolicy::factory()->for($this->perfil, 'profile')->create(['member_id' => $this->membro->id]);

        Livewire::test(InsuranceIndex::class)
            ->call('editPolicy', $apolice->id)
            ->assertSee('a alteração entra na linha do tempo dela, valendo a partir de hoje.');
    }

    // ------------------------------------------------------------------ dívida

    public function test_mudar_o_dono_da_divida_leva_as_parcelas_e_os_pagamentos_ja_feitos(): void
    {
        $this->seed(TaxonomySeeder::class);
        $bruno = ProfileMember::factory()->secondary()->create(['profile_id' => $this->perfil->id, 'name' => 'Bruno']);
        $classificacao = DebtClassification::suggest(\App\Enums\DebtKind::Loan, null);
        $divida = app(DebtService::class)->create([
            'kind' => 'loan', 'name' => 'Empréstimo', 'amortization' => 'fixed', 'installment_amount' => '650.00', 'term' => 3,
            'first_due_date' => '2026-10-25', 'paid_before' => 0, 'member_id' => null,
            'category_id' => $classificacao['category_id'], 'subcategory_id' => $classificacao['subcategory_id'],
        ]);
        app(DebtService::class)->pay($divida->installments[0], CarbonImmutable::parse('2026-10-20'), '650.00');

        Livewire::test(DebtShow::class, ['debt' => $divida])
            ->call('openEdit')
            ->set('editMemberId', $bruno->id)
            ->assertSee('Mudar de quem é vale para a dívida inteira: as parcelas e as despesas dos pagamentos já feitos passam para Bruno.')
            // O 1º "Salvar" só pede a confirmação (há parcelas e uma despesa para levar junto); o 2º grava.
            ->call('saveEdit')->assertSet('editAwaitingOwnerConfirm', true)
            ->call('saveEdit')->assertHasNoErrors();

        $this->assertSame(3, DebtInstallment::withoutProfileScope()->where('debt_id', $divida->id)->where('member_id', $bruno->id)->count());
        $this->assertSame($bruno->id, ExpenseRecord::withoutProfileScope()->sole()->member_id);
    }

    public function test_ocultar_a_divida_oculta_as_parcelas_e_os_pagamentos(): void
    {
        $this->seed(TaxonomySeeder::class);
        $classificacao = DebtClassification::suggest(\App\Enums\DebtKind::Loan, null);
        $divida = app(DebtService::class)->create([
            'kind' => 'loan', 'name' => 'Empréstimo', 'amortization' => 'fixed', 'installment_amount' => '650.00', 'term' => 2,
            'first_due_date' => '2026-10-25', 'paid_before' => 0, 'member_id' => $this->membro->id,
            'category_id' => $classificacao['category_id'], 'subcategory_id' => $classificacao['subcategory_id'],
        ]);
        app(DebtService::class)->pay($divida->installments[0], CarbonImmutable::parse('2026-10-20'), '650.00');

        app(DebtService::class)->update($divida->refresh(), ['is_private' => true, 'member_id' => $this->membro->id]);

        $this->assertSame(2, DebtInstallment::withoutProfileScope()->where('debt_id', $divida->id)->where('is_private', true)->count());
        $this->assertTrue(ExpenseRecord::withoutProfileScope()->sole()->is_private);
    }

    public function test_editar_a_classificacao_da_divida_diz_o_que_acontece_com_os_pagamentos_ja_feitos(): void
    {
        $this->seed(TaxonomySeeder::class);
        $classificacao = DebtClassification::suggest(\App\Enums\DebtKind::Loan, null);
        $divida = app(DebtService::class)->create([
            'kind' => 'loan', 'name' => 'Empréstimo', 'amortization' => 'fixed', 'installment_amount' => '650.00', 'term' => 3,
            'first_due_date' => '2026-10-25', 'paid_before' => 0, 'member_id' => $this->membro->id,
            'category_id' => $classificacao['category_id'], 'subcategory_id' => $classificacao['subcategory_id'],
        ]);
        app(DebtService::class)->pay($divida->installments[0], CarbonImmutable::parse('2026-10-20'), '650.00');
        $outra = ExpenseCategory::query()->whereNull('necessity')->where('id', '!=', $classificacao['category_id'])->firstOrFail();

        Livewire::test(DebtShow::class, ['debt' => $divida])
            ->call('openEdit')
            ->set('editCategoryId', $outra->id)
            ->assertSee('A nova classificação vale a partir do próximo pagamento. A despesa da parcela já paga fica como está.')
            ->set('editReclassify', true)
            ->assertSee('A nova classificação vale para os próximos pagamentos e também para a despesa da parcela já paga.');
    }

    // ------------------------------------------------------------------ revisão da importação

    /** @param  list<array<string, mixed>>  $itens */
    private function extrato(BankAccount $conta, array $itens): DocumentUpload
    {
        return DocumentUpload::withoutProfileScope()->create([
            'profile_id' => $this->perfil->id, 'uploaded_by_user_id' => $this->usuario->id, 'member_id' => $this->membro->id,
            'bank_account_id' => $conta->id, 'document_type' => DocumentType::BankStatement, 'original_filename' => 'extrato.pdf',
            'storage_path' => 'documentos/x.pdf', 'processing_status' => ProcessingStatus::Completed, 'extraction_summary' => ['itens' => $itens],
            'applies_to_balance' => true,
        ]);
    }

    public function test_revisao_do_extrato_conta_o_que_sera_criado_e_diz_do_saldo(): void
    {
        $conta = BankAccount::factory()->for($this->perfil, 'profile')->for($this->membro, 'member')->create(['current_balance' => '5000.00', 'is_joint' => true]);
        $categoria = ExpenseCategory::factory()->create(['necessity' => null]);
        $subcategoria = ExpenseSubcategory::factory()->create(['category_id' => $categoria->id]);
        $receita = IncomeCategory::factory()->create();

        $doc = $this->extrato($conta, [
            ['data' => '2026-10-10', 'descricao' => 'Mercado', 'valor' => '120.00', 'tipo' => 'despesa', 'categoria_sugerida' => null],
            ['data' => '2026-10-11', 'descricao' => 'Padaria', 'valor' => '30.00', 'tipo' => 'despesa', 'categoria_sugerida' => null],
            ['data' => '2026-10-12', 'descricao' => 'Freela', 'valor' => '900.00', 'tipo' => 'receita', 'categoria_sugerida' => null],
        ]);

        Livewire::test(DocumentsIndex::class)
            ->call('revisar', $doc->id)
            ->set('necessidadePorItem.0', 'essential')->set('categoriaPorItem.0', $categoria->id)->set('subcategoriaPorItem.0', $subcategoria->id)
            ->set('necessidadePorItem.1', 'essential')->set('categoriaPorItem.1', $categoria->id)->set('subcategoriaPorItem.1', $subcategoria->id)
            ->set('categoriaPorItem.2', $receita->id)
            ->assertSee('Ao importar: 2 despesas novas, 1 receita nova.')
            ->assertSee('O saldo de '.$conta->displayName().' é atualizado pelas linhas importadas.')
            ->assertDontSee('fica oculto do seu cônjuge');
    }

    public function test_revisao_diz_quando_o_que_vai_ser_criado_fica_oculto_do_conjuge(): void
    {
        $conta = BankAccount::factory()->for($this->perfil, 'profile')->for($this->membro, 'member')->create(['is_joint' => false, 'visible_to_partner' => false]);
        $receita = IncomeCategory::factory()->create();
        $doc = $this->extrato($conta, [['data' => '2026-10-12', 'descricao' => 'Freela', 'valor' => '900.00', 'tipo' => 'receita', 'categoria_sugerida' => null]]);

        Livewire::test(DocumentsIndex::class)
            ->call('revisar', $doc->id)->set('categoriaPorItem.0', $receita->id)
            ->assertSee('O que for criado fica oculto do seu cônjuge, porque a conta não é visível a ele.');
    }

    public function test_revisao_de_fatura_diz_que_nenhuma_conta_e_debitada(): void
    {
        $cartao = CreditCard::factory()->for($this->perfil, 'profile')->for($this->membro, 'member')->create(['is_joint' => true]);
        $categoria = ExpenseCategory::factory()->create(['necessity' => null]);
        $subcategoria = ExpenseSubcategory::factory()->create(['category_id' => $categoria->id]);
        $doc = DocumentUpload::withoutProfileScope()->create([
            'profile_id' => $this->perfil->id, 'uploaded_by_user_id' => $this->usuario->id, 'member_id' => $this->membro->id,
            'credit_card_id' => $cartao->id, 'document_type' => DocumentType::CreditCardInvoice, 'original_filename' => 'fatura.pdf',
            'storage_path' => 'documentos/x.pdf', 'processing_status' => ProcessingStatus::Completed,
            'extraction_summary' => ['itens' => [['data' => '2026-09-10', 'descricao' => 'Supermercado', 'valor' => '150.00', 'categoria_sugerida' => null]]],
        ]);

        Livewire::test(DocumentsIndex::class)
            ->call('revisar', $doc->id)
            ->set('necessidadePorItem.0', 'essential')->set('categoriaPorItem.0', $categoria->id)->set('subcategoriaPorItem.0', $subcategoria->id)
            ->assertSee('Ao importar: 1 despesa nova.')
            ->assertSee('Nenhuma conta é debitada agora: o dinheiro só sai quando a fatura é paga.');
    }
}
