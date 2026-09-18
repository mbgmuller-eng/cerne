<?php

namespace Tests\Feature;

use App\Enums\FixedBillPaymentStatus;
use App\Enums\RecurringIncomeStatus;
use App\Livewire\FixedBills\FixedBillsIndex;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Models\FinancialProfile;
use App\Models\FixedBill;
use App\Models\FixedBillPayment;
use App\Models\IncomeCategory;
use App\Models\ProfileMember;
use App\Models\RecurringIncome;
use App\Models\RecurringIncomeOccurrence;
use App\Models\User;
use App\Services\FixedBillService;
use App\Services\RecurringIncomeService;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Editar e excluir conta fixa/receita recorrente — antes desta tela só
 * dava pra cadastrar. "Excluir" é is_active=false, nunca DELETE de
 * verdade: fixed_bill_payments/recurring_income_occurrences têm
 * cascadeOnDelete, um DELETE de verdade apagaria histórico já pago junto.
 */
class FixedBillsEditDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_editar_conta_fixa_atualiza_os_campos(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $categoria = ExpenseCategory::factory()->create();
        $subcategoria = ExpenseSubcategory::factory()->create(['category_id' => $categoria->id]);
        $novaCategoria = ExpenseCategory::factory()->create();
        $novaSubcategoria = ExpenseSubcategory::factory()->create(['category_id' => $novaCategoria->id]);

        $conta = FixedBill::factory()->for($perfil, 'profile')->create([
            'name' => 'Aluguel', 'amount' => '2000.00', 'due_day' => 5,
            'necessity' => 'essential', 'category_id' => $categoria->id, 'subcategory_id' => $subcategoria->id,
        ]);

        Livewire::test(FixedBillsIndex::class)
            ->call('editBill', $conta->id)
            ->assertSet('billName', 'Aluguel')
            ->assertSet('billAmount', '2000.00')
            ->set('billName', 'Aluguel do apê novo')
            ->set('billAmount', '2200.00')
            ->set('billDueDay', '10')
            ->set('billCategoryId', $novaCategoria->id)
            ->set('billSubcategoryId', $novaSubcategoria->id)
            ->call('saveBill')
            ->assertHasNoErrors();

        $conta->refresh();
        self::assertSame('Aluguel do apê novo', $conta->name);
        self::assertSame('2200.00', $conta->amount);
        self::assertSame(10, $conta->due_day);
        self::assertSame($novaCategoria->id, $conta->category_id);

        // Edição não duplica — continua sendo a MESMA conta fixa.
        self::assertSame(1, FixedBill::withoutProfileScope()->where('profile_id', $perfil->id)->count());
    }

    public function test_excluir_conta_fixa_desativa_e_pula_vencimentos_em_aberto_mas_preserva_o_ja_pago(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $categoria = ExpenseCategory::factory()->create();
        $conta = FixedBill::factory()->for($perfil, 'profile')->create([
            'name' => 'Internet', 'amount' => '120.00', 'due_day' => 10, 'category_id' => $categoria->id,
        ]);

        app(FixedBillService::class)->generateForMonth(2026, 9);
        $vencimento = FixedBillPayment::withoutProfileScope()->where('fixed_bill_id', $conta->id)->sole();
        app(FixedBillService::class)->pay($vencimento, null, null, $membro->user_id);

        app(FixedBillService::class)->generateForMonth(2026, 10);
        $proximoVencimento = FixedBillPayment::withoutProfileScope()
            ->where('fixed_bill_id', $conta->id)->where('year', 2026)->where('month', 10)->sole();

        Livewire::test(FixedBillsIndex::class)
            ->call('deleteBill', $conta->id)
            ->assertHasNoErrors();

        self::assertFalse($conta->fresh()->is_active);

        // O que já foi pago é fluxo de caixa real — intocado.
        self::assertSame(FixedBillPaymentStatus::Paid, $vencimento->fresh()->status);

        // O que ainda estava em aberto vira "pulado" — não cobra mais.
        self::assertSame(FixedBillPaymentStatus::Skipped, $proximoVencimento->fresh()->status);

        // E não gera vencimento novo pra mês nenhum daqui pra frente.
        $criados = app(FixedBillService::class)->generateForMonth(2026, 11);
        self::assertSame(0, $criados);
    }

    public function test_editar_receita_recorrente_atualiza_os_campos(): void
    {
        [$perfil] = $this->criarPerfil();
        $categoria = IncomeCategory::factory()->create();
        $novaCategoria = IncomeCategory::factory()->create();

        $receita = RecurringIncome::factory()->for($perfil, 'profile')->create([
            'name' => 'Salário', 'amount' => '5000.00', 'due_day' => 5, 'category_id' => $categoria->id,
        ]);

        Livewire::test(FixedBillsIndex::class)
            ->call('editIncome', $receita->id)
            ->assertSet('incomeName', 'Salário')
            ->set('incomeName', 'Salário CLT')
            ->set('incomeAmount', '5500.00')
            ->set('incomeCategoryId', $novaCategoria->id)
            ->call('saveIncome')
            ->assertHasNoErrors();

        $receita->refresh();
        self::assertSame('Salário CLT', $receita->name);
        self::assertSame('5500.00', $receita->amount);
        self::assertSame($novaCategoria->id, $receita->category_id);
    }

    public function test_excluir_receita_recorrente_desativa_e_pula_ocorrencias_em_aberto_mas_preserva_a_ja_recebida(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $categoria = IncomeCategory::factory()->create();
        $receita = RecurringIncome::factory()->for($perfil, 'profile')->create([
            'name' => 'Freela mensal', 'amount' => '800.00', 'due_day' => 5, 'category_id' => $categoria->id,
        ]);

        app(RecurringIncomeService::class)->generateForMonth(2026, 9);
        $ocorrencia = RecurringIncomeOccurrence::withoutProfileScope()->where('recurring_income_id', $receita->id)->sole();
        app(RecurringIncomeService::class)->receive($ocorrencia, null, null, $membro->user_id);

        app(RecurringIncomeService::class)->generateForMonth(2026, 10);
        $proximaOcorrencia = RecurringIncomeOccurrence::withoutProfileScope()
            ->where('recurring_income_id', $receita->id)->where('year', 2026)->where('month', 10)->sole();

        Livewire::test(FixedBillsIndex::class)
            ->call('deleteIncome', $receita->id)
            ->assertHasNoErrors();

        self::assertFalse($receita->fresh()->is_active);
        self::assertSame(RecurringIncomeStatus::Received, $ocorrencia->fresh()->status);
        self::assertSame(RecurringIncomeStatus::Skipped, $proximaOcorrencia->fresh()->status);
    }

    public function test_excluir_conta_fixa_de_outro_perfil_nao_e_permitido(): void
    {
        $outroPerfil = FinancialProfile::factory()->create();
        $contaDeOutroPerfil = FixedBill::factory()->for($outroPerfil, 'profile')->create();

        $this->criarPerfil();

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::test(FixedBillsIndex::class)->call('deleteBill', $contaDeOutroPerfil->id);
    }

    /** @return array{0: FinancialProfile, 1: ProfileMember} */
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
