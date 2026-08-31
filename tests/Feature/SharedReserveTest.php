<?php

namespace Tests\Feature;

use App\Enums\EmploymentType;
use App\Enums\InvestorType;
use App\Enums\MemberRole;
use App\Enums\Necessity;
use App\Enums\ReserveType;
use App\Livewire\Investments\InvestmentsIndex;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\FinancialReserve;
use App\Models\InvestorProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Casal onde alguém marca gasto essencial como oculto do outro: cada um
 * que TEM gasto oculto ganha sua própria reserva de paz/oportunidade,
 * baseada só no que é privado dele, e existe uma TERCEIRA reserva — a do
 * casal (member_id nulo em financial_reserves), baseada no que é visível
 * aos dois — que os dois "vêem".
 */
class SharedReserveTest extends TestCase
{
    use RefreshDatabase;

    public function test_reserva_individual_usa_so_o_gasto_privado_de_cada_um(): void
    {
        [$perfil, $ana, $bruno] = $this->criarCasal();

        $this->lancarEssencial($perfil, $ana->id, '1000.00', isPrivate: true);
        $this->lancarEssencial($perfil, $bruno->id, '2000.00', isPrivate: true);
        $this->lancarEssencial($perfil, null, '500.00'); // família — não entra na individual

        $perfilAna = InvestorProfile::create(['member_id' => $ana->id, 'investor_type' => InvestorType::Moderate, 'employment_type' => EmploymentType::Clt]);
        $perfilBruno = InvestorProfile::create(['member_id' => $bruno->id, 'investor_type' => InvestorType::Conservative, 'employment_type' => EmploymentType::SelfEmployed]);

        self::assertSame('9000.00', $perfilAna->peaceReserveTarget()); // 1000 x 9, sem divisão
        self::assertSame('24000.00', $perfilBruno->peaceReserveTarget()); // 2000 x 12, sem divisão
    }

    public function test_quem_nao_tem_gasto_oculto_nao_ganha_reserva_individual(): void
    {
        [$perfil, $ana, $bruno] = $this->criarCasal();

        $this->lancarEssencial($perfil, $ana->id, '1000.00', isPrivate: true);
        $this->lancarEssencial($perfil, $bruno->id, '2000.00'); // Bruno não esconde nada
        $this->lancarEssencial($perfil, null, '500.00');

        $perfilAna = InvestorProfile::create(['member_id' => $ana->id, 'investor_type' => InvestorType::Moderate, 'employment_type' => EmploymentType::Clt]);
        $perfilBruno = InvestorProfile::create(['member_id' => $bruno->id, 'investor_type' => InvestorType::Conservative, 'employment_type' => EmploymentType::SelfEmployed]);

        self::assertSame('9000.00', $perfilAna->peaceReserveTarget()); // tem oculto: 1000 x 9
        self::assertSame('0.00', $perfilBruno->peaceReserveTarget()); // sem oculto: coberto pela reserva do casal
    }

    public function test_reserva_do_casal_soma_a_fatia_de_cada_provedor_sobre_o_gasto_compartilhado(): void
    {
        [$perfil, $ana, $bruno] = $this->criarCasal();

        $this->lancarEssencial($perfil, $ana->id, '1000.00', isPrivate: true);
        $this->lancarEssencial($perfil, $bruno->id, '2000.00', isPrivate: true);
        $this->lancarEssencial($perfil, null, '500.00'); // família — base da reserva do casal

        $perfilAna = InvestorProfile::create(['member_id' => $ana->id, 'investor_type' => InvestorType::Moderate, 'employment_type' => EmploymentType::Clt]);
        InvestorProfile::create(['member_id' => $bruno->id, 'investor_type' => InvestorType::Conservative, 'employment_type' => EmploymentType::SelfEmployed]);

        // Fatia de cada um: 500 / 2 = 250. Ana (CLT, 9x) = 2250; Bruno (autônomo, 12x) = 3000.
        self::assertSame('5250.00', $perfilAna->sharedPeaceReserveTarget());
        self::assertSame('1750.00', $perfilAna->sharedOpportunityReserveTarget());
    }

    public function test_sem_gasto_oculto_nao_existe_reserva_do_casal(): void
    {
        [$perfil, $ana, $bruno] = $this->criarCasal();

        $this->lancarEssencial($perfil, null, '2000.00');

        $perfilAna = InvestorProfile::create(['member_id' => $ana->id, 'investor_type' => InvestorType::Moderate, 'employment_type' => EmploymentType::Clt]);
        InvestorProfile::create(['member_id' => $bruno->id, 'investor_type' => InvestorType::Conservative, 'employment_type' => EmploymentType::SelfEmployed]);

        self::assertSame('0.00', $perfilAna->sharedPeaceReserveTarget());
        self::assertSame('0.00', $perfilAna->sharedOpportunityReserveTarget());
    }

    public function test_financial_reserve_compartilhada_usa_o_calculo_do_casal(): void
    {
        [$perfil, $ana, $bruno] = $this->criarCasal();

        $this->lancarEssencial($perfil, $ana->id, '1000.00', isPrivate: true);
        $this->lancarEssencial($perfil, $bruno->id, '2000.00', isPrivate: true);
        $this->lancarEssencial($perfil, null, '500.00');

        InvestorProfile::create(['member_id' => $ana->id, 'investor_type' => InvestorType::Moderate, 'employment_type' => EmploymentType::Clt]);
        InvestorProfile::create(['member_id' => $bruno->id, 'investor_type' => InvestorType::Conservative, 'employment_type' => EmploymentType::SelfEmployed]);

        $reservaCasal = FinancialReserve::create([
            'profile_id' => $perfil->id,
            'member_id' => null,
            'reserve_type' => ReserveType::Paz,
            'target_amount' => '0.00',
            'current_amount' => '1000.00',
        ]);

        self::assertTrue($reservaCasal->isShared());
        self::assertSame('5250.00', $reservaCasal->targetAmount());
    }

    public function test_indice_unico_impede_duas_reservas_compartilhadas_do_mesmo_tipo(): void
    {
        [$perfil] = $this->criarCasal();

        FinancialReserve::create([
            'profile_id' => $perfil->id,
            'member_id' => null,
            'reserve_type' => ReserveType::Paz,
        ]);

        $this->expectException(QueryException::class);

        FinancialReserve::create([
            'profile_id' => $perfil->id,
            'member_id' => null,
            'reserve_type' => ReserveType::Paz,
        ]);
    }

    public function test_salvar_perfil_dos_dois_provedores_cria_a_reserva_do_casal(): void
    {
        [$perfil, $ana, $bruno] = $this->criarCasal();

        $this->lancarEssencial($perfil, $ana->id, '1000.00', isPrivate: true);
        $this->lancarEssencial($perfil, $bruno->id, '2000.00', isPrivate: true);
        $this->lancarEssencial($perfil, null, '500.00');

        $componente = Livewire::test(InvestmentsIndex::class)
            ->call('toggleInvestorProfileForm', $ana->id)
            ->set('investorTypeInput', InvestorType::Moderate->value)
            ->set('employmentTypeInput', EmploymentType::Clt->value)
            ->call('saveInvestorProfile');

        // Só a Ana tem perfil ainda — não há segundo provedor, sem reserva do casal.
        self::assertSame(0, FinancialReserve::query()->whereNull('member_id')->count());

        $componente
            ->call('toggleInvestorProfileForm', $bruno->id)
            ->set('investorTypeInput', InvestorType::Conservative->value)
            ->set('employmentTypeInput', EmploymentType::SelfEmployed->value)
            ->call('saveInvestorProfile')
            ->assertHasNoErrors();

        $reservasCasal = FinancialReserve::query()->whereNull('member_id')->get();
        self::assertCount(2, $reservasCasal); // paz + oportunidade
        self::assertTrue($reservasCasal->contains(fn (FinancialReserve $r) => $r->reserve_type === ReserveType::Paz));
        self::assertTrue($reservasCasal->contains(fn (FinancialReserve $r) => $r->reserve_type === ReserveType::Oportunidade));
    }

    private function lancarEssencial(FinancialProfile $perfil, ?string $memberId, string $valor, bool $isPrivate = false): void
    {
        ExpenseRecord::factory()->for($perfil, 'profile')->create([
            'member_id' => $memberId,
            'necessity' => Necessity::Essential,
            'amount' => $valor,
            'expense_date' => CarbonImmutable::now()->subMonth(),
            'is_private' => $isPrivate,
        ]);
    }

    /** @return array{0: FinancialProfile, 1: ProfileMember, 2: ProfileMember} */
    private function criarCasal(): array
    {
        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $usuario->id]);
        $ana = ProfileMember::factory()->create([
            'profile_id' => $perfil->id,
            'user_id' => $usuario->id,
            'role' => MemberRole::Primary,
            'name' => 'Ana',
        ]);
        $bruno = ProfileMember::factory()->secondary()->create([
            'profile_id' => $perfil->id,
            'name' => 'Bruno',
        ]);

        $this->actingAs($usuario);
        app(ProfileContext::class)->set($perfil, $ana);

        return [$perfil, $ana, $bruno];
    }
}
