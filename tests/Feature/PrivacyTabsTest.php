<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\InsuranceType;
use App\Enums\MemberRole;
use App\Enums\Necessity;
use App\Enums\Visibility;
use App\Livewire\Accounts\AccountsIndex;
use App\Livewire\CashFlow\CashFlowIndex;
use App\Livewire\FixedBills\FixedBillsIndex;
use App\Livewire\Insurance\InsuranceIndex;
use App\Livewire\Investments\InvestmentsIndex;
use App\Models\BankAccount;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\FixedBill;
use App\Models\InsurancePolicy;
use App\Models\InvestmentRecord;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * As 3 abas (Casal / cada membro) só existem pra casal que de fato tem
 * algo marcado como oculto (own_only) — pra quem nunca mexeu na
 * privacidade, a tela continua igual, sem aba nenhuma. Testa a
 * decisão de mostrar/esconder e o filtro em cada padrão de tela
 * (coluna direta, relação, is_joint).
 */
class PrivacyTabsTest extends TestCase
{
    use RefreshDatabase;

    public function test_abas_nao_aparecem_para_casal_transparente(): void
    {
        [, , $ana] = $this->criarCasal();

        $this->actingAs($ana->user);
        app(ProfileContext::class)->set($ana->profile, $ana->member);

        Livewire::test(CashFlowIndex::class)->assertSet('showPrivacyTabs', false);
    }

    public function test_abas_nao_aparecem_para_solteiro_mesmo_com_own_only(): void
    {
        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id]);
        $perfil->settings()->update(['expense_visibility' => Visibility::OwnOnly]);

        $this->actingAs($usuario);
        app(ProfileContext::class)->set($perfil, $membro);

        Livewire::test(CashFlowIndex::class)->assertSet('showPrivacyTabs', false);
    }

    public function test_abas_aparecem_quando_casal_tem_algo_oculto(): void
    {
        [$perfil, , $ana] = $this->criarCasal();
        $perfil->settings()->update(['expense_visibility' => Visibility::OwnOnly]);

        $this->actingAs($ana->user);
        app(ProfileContext::class)->set($perfil, $ana->member);

        Livewire::test(CashFlowIndex::class)->assertSet('showPrivacyTabs', true);
    }

    public function test_fluxo_de_caixa_filtra_por_coluna_direta(): void
    {
        [$perfil, $bruno, $ana] = $this->criarCasal();
        $perfil->settings()->update(['expense_visibility' => Visibility::OwnOnly]);
        $this->actingAs($ana->user);
        app(ProfileContext::class)->set($perfil, $ana->member);

        $categoria = ExpenseCategory::factory()->shared()->create();
        $hoje = \Carbon\CarbonImmutable::now();
        $deAna = ExpenseRecord::factory()->for($perfil, 'profile')->create(['member_id' => $ana->member->id, 'category_id' => $categoria->id, 'expense_date' => $hoje]);
        $deBruno = ExpenseRecord::factory()->for($perfil, 'profile')->create(['member_id' => $bruno->member->id, 'category_id' => $categoria->id, 'expense_date' => $hoje]);
        $daFamilia = ExpenseRecord::factory()->for($perfil, 'profile')->create(['member_id' => null, 'category_id' => $categoria->id, 'expense_date' => $hoje]);

        $componente = Livewire::test(CashFlowIndex::class)->set('viewAs', '');
        $casal = $componente->get('expenses');
        self::assertTrue($casal->contains($daFamilia));
        self::assertFalse($casal->contains($deAna));
        self::assertFalse($casal->contains($deBruno));

        $vistaDeAna = Livewire::test(CashFlowIndex::class)->set('viewAs', $ana->member->id)->get('expenses');
        self::assertTrue($vistaDeAna->contains($deAna));
        self::assertFalse($vistaDeAna->contains($deBruno));
        self::assertFalse($vistaDeAna->contains($daFamilia));
    }

    public function test_contas_fixas_filtra_pela_relacao(): void
    {
        [$perfil, $bruno, $ana] = $this->criarCasal();
        $perfil->settings()->update(['expense_visibility' => Visibility::OwnOnly]);
        $this->actingAs($ana->user);
        app(ProfileContext::class)->set($perfil, $ana->member);

        $categoria = ExpenseCategory::factory()->shared()->create();
        $contaDeAna = FixedBill::factory()->for($perfil, 'profile')->create(['member_id' => $ana->member->id, 'category_id' => $categoria->id, 'due_day' => 5]);
        $contaDeBruno = FixedBill::factory()->for($perfil, 'profile')->create(['member_id' => $bruno->member->id, 'category_id' => $categoria->id, 'due_day' => 5]);

        $vistaDeAna = Livewire::test(FixedBillsIndex::class)->set('viewAs', $ana->member->id)->get('payments');
        self::assertTrue($vistaDeAna->contains(fn ($p) => $p->fixedBill->id === $contaDeAna->id));
        self::assertFalse($vistaDeAna->contains(fn ($p) => $p->fixedBill->id === $contaDeBruno->id));
    }

    public function test_contas_e_cartoes_filtra_por_is_joint(): void
    {
        [$perfil, $bruno, $ana] = $this->criarCasal();
        $perfil->settings()->update(['bank_account_visibility' => Visibility::OwnOnly]);
        $this->actingAs($ana->user);
        app(ProfileContext::class)->set($perfil, $ana->member);

        $contaDeAna = BankAccount::factory()->for($perfil, 'profile')->for($ana->member, 'member')->create(['is_joint' => false]);
        $contaConjunta = BankAccount::factory()->for($perfil, 'profile')->for($bruno->member, 'member')->create(['is_joint' => true]);

        $abaCasal = Livewire::test(AccountsIndex::class)->set('viewAs', '')->get('accounts');
        self::assertTrue($abaCasal->contains($contaConjunta));
        self::assertFalse($abaCasal->contains($contaDeAna));

        $abaAna = Livewire::test(AccountsIndex::class)->set('viewAs', $ana->member->id)->get('accounts');
        self::assertTrue($abaAna->contains($contaDeAna));
        self::assertFalse($abaAna->contains($contaConjunta));
    }

    public function test_seguros_filtra_por_coluna_direta_nulavel(): void
    {
        [$perfil, $bruno, $ana] = $this->criarCasal();
        $perfil->settings()->update(['insurance_visibility' => Visibility::OwnOnly]);
        $this->actingAs($ana->user);
        app(ProfileContext::class)->set($perfil, $ana->member);

        $seguroDeAna = InsurancePolicy::factory()->for($perfil, 'profile')->create(['member_id' => $ana->member->id, 'insurance_type' => InsuranceType::Vida]);
        $seguroDaFamilia = InsurancePolicy::factory()->for($perfil, 'profile')->create(['member_id' => null, 'insurance_type' => InsuranceType::Residencia]);

        $abaCasal = Livewire::test(InsuranceIndex::class)->set('viewAs', '')->get('policies');
        self::assertTrue($abaCasal->contains($seguroDaFamilia));
        self::assertFalse($abaCasal->contains($seguroDeAna));
    }

    public function test_investimentos_aba_casal_fica_vazia_pois_investimento_sempre_tem_dono(): void
    {
        [$perfil, $bruno, $ana] = $this->criarCasal();
        $perfil->settings()->update(['investment_visibility' => Visibility::OwnOnly]);
        $this->actingAs($ana->user);
        app(ProfileContext::class)->set($perfil, $ana->member);

        InvestmentRecord::factory()->for($perfil, 'profile')->for($ana->member, 'member')->create();

        $abaCasal = Livewire::test(InvestmentsIndex::class)->set('viewAs', '')->get('bySector');
        self::assertTrue($abaCasal->isEmpty());
    }

    /**
     * @return array{0: FinancialProfile, 1: object{user: User, member: ProfileMember}, 2: object{user: User, member: ProfileMember}}
     */
    private function criarCasal(): array
    {
        $usuarioAna = User::factory()->create();
        $perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $usuarioAna->id]);
        $membroAna = ProfileMember::factory()->create([
            'profile_id' => $perfil->id,
            'user_id' => $usuarioAna->id,
            'role' => MemberRole::Primary,
            'name' => 'Ana',
        ]);
        $usuarioBruno = User::factory()->create();
        $membroBruno = ProfileMember::factory()->secondary()->create([
            'profile_id' => $perfil->id,
            'user_id' => $usuarioBruno->id,
            'name' => 'Bruno',
        ]);

        $ana = (object) ['user' => $usuarioAna, 'member' => $membroAna, 'profile' => $perfil];
        $bruno = (object) ['user' => $usuarioBruno, 'member' => $membroBruno, 'profile' => $perfil];

        return [$perfil, $bruno, $ana];
    }
}
