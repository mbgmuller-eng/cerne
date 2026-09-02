<?php

namespace Tests\Feature;

use App\Enums\MemberRole;
use App\Enums\Necessity;
use App\Livewire\FixedBills\FixedBillsIndex;
use App\Models\BankAccount;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\FixedBill;
use App\Models\FixedBillPayment;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\FixedBillService;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Conta fixa ganhou necessidade/categoria/subcategoria próprias (antes só
 * tinha categoria, sem necessidade nenhuma) — mesmas regras de
 * ExpenseCategoryByNecessityTest: categoria filtra por necessidade,
 * subcategoria é obrigatória fora de Investimento.
 */
class FixedBillNecessityTest extends TestCase
{
    use RefreshDatabase;

    public function test_categoria_de_investimento_so_aparece_quando_necessidade_e_investimento(): void
    {
        $this->criarPerfil();
        $investimentos = ExpenseCategory::factory()->create(['necessity' => Necessity::Investment]);

        $component = Livewire::test(FixedBillsIndex::class)->set('billNecessity', 'essential');
        self::assertFalse($component->get('billFormCategories')->contains('id', $investimentos->id));

        $component->set('billNecessity', 'investment');
        self::assertTrue($component->get('billFormCategories')->contains('id', $investimentos->id));
    }

    public function test_salvar_conta_fixa_sem_subcategoria_e_bloqueado_fora_de_investimento(): void
    {
        $this->criarPerfil();
        $categoria = ExpenseCategory::factory()->create(['necessity' => null]);

        Livewire::test(FixedBillsIndex::class)
            ->set('billName', 'Internet')
            ->set('billAmount', '120.00')
            ->set('billDueDay', '15')
            ->set('billNecessity', 'essential')
            ->set('billCategoryId', $categoria->id)
            ->call('saveBill')
            ->assertHasErrors(['billSubcategoryId']);

        self::assertSame(0, FixedBill::withoutProfileScope()->where('name', 'Internet')->count());
    }

    public function test_salvar_conta_fixa_de_investimento_nao_exige_subcategoria(): void
    {
        $this->criarPerfil();
        $investimentos = ExpenseCategory::factory()->create(['necessity' => Necessity::Investment]);

        Livewire::test(FixedBillsIndex::class)
            ->set('billName', 'Aporte mensal CDB')
            ->set('billAmount', '500.00')
            ->set('billDueDay', '5')
            ->set('billNecessity', 'investment')
            ->set('billCategoryId', $investimentos->id)
            ->call('saveBill')
            ->assertHasNoErrors();

        $conta = FixedBill::withoutProfileScope()->where('name', 'Aporte mensal CDB')->sole();
        self::assertSame(Necessity::Investment, $conta->necessity);
        self::assertNull($conta->subcategory_id);
    }

    public function test_trocar_necessidade_limpa_categoria_que_deixou_de_valer(): void
    {
        $this->criarPerfil();
        $investimentos = ExpenseCategory::factory()->create(['necessity' => Necessity::Investment]);

        Livewire::test(FixedBillsIndex::class)
            ->set('billNecessity', 'investment')
            ->set('billCategoryId', $investimentos->id)
            ->set('billSubcategoryId', 'algum-id')
            ->set('billNecessity', 'essential')
            ->assertSet('billCategoryId', '')
            ->assertSet('billSubcategoryId', '');
    }

    /**
     * FixedBillService::pay() cravava Necessity::Essential no lançamento
     * gerado, não importava a necessidade real da conta — corrigido pra
     * usar a necessidade cadastrada na própria conta fixa.
     */
    public function test_necessidade_da_conta_fixa_propaga_pro_lancamento_gerado_no_pagamento(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $investimentos = ExpenseCategory::factory()->create(['necessity' => Necessity::Investment]);
        $contaFixa = FixedBill::factory()->for($perfil, 'profile')->create([
            'necessity' => Necessity::Investment,
            'category_id' => $investimentos->id,
            'subcategory_id' => null,
            'bank_account_id' => BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')->create()->id,
        ]);

        app(FixedBillService::class)->generateForMonth(
            $contaFixa->fresh()->created_at->year,
            $contaFixa->fresh()->created_at->month,
        );
        $pagamento = FixedBillPayment::withoutProfileScope()->where('fixed_bill_id', $contaFixa->id)->firstOrFail();

        app(FixedBillService::class)->pay($pagamento, null, null, $membro->user_id);

        $lancamento = ExpenseRecord::where('bank_account_id', $contaFixa->bank_account_id)->sole();
        self::assertSame(Necessity::Investment, $lancamento->necessity);
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
