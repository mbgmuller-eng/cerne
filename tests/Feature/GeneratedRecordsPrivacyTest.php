<?php

namespace Tests\Feature;

use App\Enums\MemberRole;
use App\Models\BankAccount;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\FixedBill;
use App\Models\FixedBillPayment;
use App\Models\IncomeRecord;
use App\Models\ProfileMember;
use App\Models\RecurringIncome;
use App\Models\RecurringIncomeOccurrence;
use App\Models\User;
use App\Services\FixedBillService;
use App\Services\RecurringIncomeService;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pagar uma conta fixa ou receber uma receita recorrente CRIA um lançamento no fluxo de caixa. Quem marcou "Ocultar do meu cônjuge" na conta fixa ou na
 * receita esperava que o lançamento gerado também ficasse oculto; sem isto, ele aparecia para o cônjuge ao pagar (regra 2 do CLAUDE.md).
 */
class GeneratedRecordsPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private User $bruno;

    private FinancialProfile $perfil;

    private ProfileMember $membroAna;

    private ProfileMember $membroBruno;

    private BankAccount $conta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ana = User::factory()->create();
        $this->bruno = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $this->ana->id]);
        $this->membroAna = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->ana->id, 'role' => MemberRole::Primary, 'name' => 'Ana']);
        $this->membroBruno = ProfileMember::factory()->secondary()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->bruno->id, 'name' => 'Bruno']);

        $this->entrar($this->ana, $this->membroAna);
        $this->conta = BankAccount::factory()->for($this->perfil, 'profile')->create(['member_id' => $this->membroAna->id, 'current_balance' => '5000.00', 'is_joint' => true]);
    }

    private function entrar(User $usuario, ProfileMember $membro): void
    {
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($this->perfil, $membro);
    }

    private function pagarContaFixa(bool $oculta): void
    {
        $conta = FixedBill::factory()->for($this->perfil, 'profile')->create([
            'member_id' => $this->membroAna->id, 'is_private' => $oculta, 'due_day' => 5, 'amount' => '300.00', 'bank_account_id' => $this->conta->id,
        ]);

        $servico = app(FixedBillService::class);
        $servico->generateForMonth(2026, 8);
        $servico->pay(FixedBillPayment::withoutProfileScope()->where('fixed_bill_id', $conta->id)->sole(), null, null, null);
    }

    private function receberReceitaRecorrente(bool $oculta): void
    {
        $receita = RecurringIncome::factory()->for($this->perfil, 'profile')->create([
            'member_id' => $this->membroAna->id, 'is_private' => $oculta, 'due_day' => 5, 'amount' => '900.00', 'bank_account_id' => $this->conta->id,
        ]);

        $servico = app(RecurringIncomeService::class);
        $servico->generateForMonth(2026, 8);
        $servico->receive(RecurringIncomeOccurrence::withoutProfileScope()->where('recurring_income_id', $receita->id)->sole(), null, null, null);
    }

    public function test_despesa_gerada_ao_pagar_conta_fixa_oculta_nao_aparece_para_o_conjuge(): void
    {
        $this->pagarContaFixa(oculta: true);

        $this->assertSame(1, ExpenseRecord::query()->count(), 'a dona da conta fixa vê a despesa');
        $this->assertTrue(ExpenseRecord::query()->sole()->is_private);

        $this->entrar($this->bruno, $this->membroBruno);
        $this->assertSame(0, ExpenseRecord::query()->count(), 'o cônjuge não vê a despesa que a Ana escondeu');
    }

    public function test_despesa_gerada_ao_pagar_conta_fixa_comum_continua_visivel_aos_dois(): void
    {
        $this->pagarContaFixa(oculta: false);

        $this->entrar($this->bruno, $this->membroBruno);
        $this->assertSame(1, ExpenseRecord::query()->count());
        $this->assertFalse(ExpenseRecord::query()->sole()->is_private);
    }

    public function test_receita_gerada_ao_receber_receita_recorrente_oculta_nao_aparece_para_o_conjuge(): void
    {
        $this->receberReceitaRecorrente(oculta: true);

        $this->assertSame(1, IncomeRecord::query()->count(), 'a dona da receita vê o lançamento');
        $this->assertTrue(IncomeRecord::query()->sole()->is_private);

        $this->entrar($this->bruno, $this->membroBruno);
        $this->assertSame(0, IncomeRecord::query()->count(), 'o cônjuge não vê a receita que a Ana escondeu');
    }

    public function test_receita_gerada_ao_receber_receita_recorrente_comum_continua_visivel_aos_dois(): void
    {
        $this->receberReceitaRecorrente(oculta: false);

        $this->entrar($this->bruno, $this->membroBruno);
        $this->assertSame(1, IncomeRecord::query()->count());
    }
}
