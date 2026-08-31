<?php

namespace Tests\Feature;

use App\Livewire\FixedBills\FixedBillsIndex;
use App\Models\ExpenseCategory;
use App\Models\FinancialProfile;
use App\Models\FixedBill;
use App\Models\IncomeCategory;
use App\Models\ProfileMember;
use App\Models\RecurringIncome;
use App\Models\User;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cadastro manual de conta fixa e receita recorrente — cobre o campo
 * "ocultar do meu cônjuge" (is_private), sem cobertura de Livewire
 * nenhuma antes disso (FixedBillServiceTest cobre só a geração mensal).
 */
class FixedBillsManualEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_conta_fixa_marcada_oculta_grava_is_private(): void
    {
        [, $membro] = $this->criarPerfil();
        $categoria = ExpenseCategory::factory()->create();

        Livewire::test(FixedBillsIndex::class)
            ->set('billName', 'Plano de saúde particular')
            ->set('billAmount', '450.00')
            ->set('billDueDay', '10')
            ->set('billCategoryId', $categoria->id)
            ->set('billMemberId', $membro->id)
            ->set('billIsPrivate', true)
            ->call('saveBill')
            ->assertHasNoErrors();

        $conta = FixedBill::withoutProfileScope()->where('name', 'Plano de saúde particular')->sole();
        self::assertTrue($conta->is_private);
    }

    public function test_receita_recorrente_marcada_oculta_grava_is_private(): void
    {
        [, $membro] = $this->criarPerfil();
        $categoria = IncomeCategory::factory()->create();

        Livewire::test(FixedBillsIndex::class)
            ->set('incomeName', 'Consultoria paralela')
            ->set('incomeAmount', '1200.00')
            ->set('incomeDueDay', '5')
            ->set('incomeCategoryId', $categoria->id)
            ->set('incomeMemberId', $membro->id)
            ->set('incomeIsPrivate', true)
            ->call('saveIncome')
            ->assertHasNoErrors();

        $receita = RecurringIncome::withoutProfileScope()->where('name', 'Consultoria paralela')->sole();
        self::assertTrue($receita->is_private);
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
