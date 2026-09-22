<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Livewire\Consultant\ImportantDates;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\InsurancePolicy;
use App\Models\InsurancePolicyRenewal;
use App\Models\InvestmentRecord;
use App\Models\ProfileMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tela "Datas importantes" — aniversário de titular/cônjuge, aniversário
 * (renovação) e vencimento de apólice, vencimento de investimento. Só pro
 * profissional; nunca pro cliente (ver ImportantDatesService).
 */
class ImportantDatesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_cliente_recebe_403(): void
    {
        $cliente = User::factory()->create();
        $this->actingAs($cliente);

        Livewire::test(ImportantDates::class)->assertStatus(403);
    }

    public function test_consultor_acessa_a_tela(): void
    {
        $consultor = User::factory()->consultant()->create();
        $this->actingAs($consultor);

        Livewire::test(ImportantDates::class)->assertStatus(200);
    }

    public function test_corretor_acessa_a_tela(): void
    {
        $corretor = User::factory()->broker()->create();
        $this->actingAs($corretor);

        Livewire::test(ImportantDates::class)->assertStatus(200);
    }

    public function test_corretor_ve_aniversario_do_cliente_vinculado_nos_proximos_7_dias(): void
    {
        Carbon::setTestNow('2026-01-10');
        [$corretor, , $membro] = $this->criarClienteVinculado();
        $membro->update(['birthdate' => '1990-01-15']);

        $lista = Livewire::test(ImportantDates::class)->get('birthdays');

        self::assertCount(1, $lista);
        self::assertSame($membro->id, $lista->first()['member']->id);
        self::assertSame(36, $lista->first()['turning_age']);
    }

    public function test_aniversario_fora_do_periodo_nao_aparece(): void
    {
        Carbon::setTestNow('2026-01-10');
        [$corretor, , $membro] = $this->criarClienteVinculado();
        $membro->update(['birthdate' => '1990-06-15']);

        $lista = Livewire::test(ImportantDates::class)->get('birthdays');

        self::assertCount(0, $lista);
    }

    public function test_aniversariantes_mostra_premio_de_cada_apolice_nao_a_soma(): void
    {
        Carbon::setTestNow('2026-01-10');
        [$corretor, $perfil, $membro] = $this->criarClienteVinculado();
        $membro->update(['birthdate' => '1990-01-12']);

        InsurancePolicy::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['insurer_name' => 'AZOS', 'monthly_premium' => '150.00', 'broker_id' => $corretor->id]);
        InsurancePolicy::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['insurer_name' => 'ICATU', 'monthly_premium' => '90.00', 'broker_id' => $corretor->id]);

        $lista = Livewire::test(ImportantDates::class)->get('birthdays');

        $apolices = $lista->first()['policies'];
        self::assertCount(2, $apolices);
        self::assertEqualsCanonicalizing(['150.00', '90.00'], $apolices->pluck('monthly_premium')->all());
    }

    public function test_corretor_so_ve_premio_da_apolice_compartilhada_com_ele_no_aniversariante(): void
    {
        Carbon::setTestNow('2026-01-10');
        [$corretor, $perfil, $membro] = $this->criarClienteVinculado();
        $membro->update(['birthdate' => '1990-01-12']);

        InsurancePolicy::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['insurer_name' => 'Compartilhada', 'broker_id' => $corretor->id]);
        InsurancePolicy::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['insurer_name' => 'De outro corretor', 'broker_id' => User::factory()->broker()->create()->id]);

        $lista = Livewire::test(ImportantDates::class)->get('birthdays');

        self::assertCount(1, $lista->first()['policies']);
        self::assertSame('Compartilhada', $lista->first()['policies']->first()->insurer_name);
    }

    public function test_aniversario_de_apolice_calcula_anos_completos_a_partir_do_inicio(): void
    {
        Carbon::setTestNow('2026-01-10');
        [$corretor, $perfil, $membro] = $this->criarClienteVinculado();

        InsurancePolicy::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['start_date' => '2023-01-15', 'broker_id' => $corretor->id]);

        $lista = Livewire::test(ImportantDates::class)->get('policyAnniversaries');

        self::assertCount(1, $lista);
        self::assertSame(3, $lista->first()['years_completing']);
    }

    public function test_vencimento_de_apolice_so_aparece_quando_expiry_date_preenchido(): void
    {
        Carbon::setTestNow('2026-01-10');
        [$corretor, $perfil, $membro] = $this->criarClienteVinculado();

        InsurancePolicy::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['expiry_date' => '2026-01-14', 'broker_id' => $corretor->id]);
        InsurancePolicy::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['expiry_date' => null, 'broker_id' => $corretor->id]);

        $lista = Livewire::test(ImportantDates::class)->get('policyExpiries');

        self::assertCount(1, $lista);
    }

    public function test_corretor_nao_ve_vencimento_de_investimento(): void
    {
        Carbon::setTestNow('2026-01-10');
        [$corretor, $perfil, $membro] = $this->criarClienteVinculado();

        InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['maturity_date' => '2029-07-13']);

        $lista = Livewire::test(ImportantDates::class)->get('investmentMaturities');

        self::assertCount(0, $lista);
    }

    public function test_consultor_ve_vencimento_de_investimento(): void
    {
        Carbon::setTestNow('2026-01-10');
        [, $perfil, $membro] = $this->criarClienteVinculado(consultor: true);

        InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['maturity_date' => '2029-07-13']);

        $lista = Livewire::test(ImportantDates::class)->get('investmentMaturities');

        self::assertCount(1, $lista);
        self::assertSame('2029-07-13', $lista->first()['occurrence_date']->toDateString());
    }

    public function test_registrar_renovacao_atualiza_apolice_e_grava_historico(): void
    {
        Carbon::setTestNow('2026-01-10');
        [$corretor, $perfil, $membro] = $this->criarClienteVinculado();

        $apolice = InsurancePolicy::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['monthly_premium' => '100.00', 'coverage_amount' => '50000.00', 'broker_id' => $corretor->id]);

        Livewire::test(ImportantDates::class)
            ->call('startRenewal', $apolice->id)
            ->set('renewalPremium', '120.00')
            ->set('renewalCoverage', '60000.00')
            ->set('renewalNotes', 'Reajuste anual')
            ->call('saveRenewal')
            ->assertHasNoErrors();

        $apolice->refresh();
        self::assertSame('120.00', $apolice->monthly_premium);
        self::assertSame('60000.00', $apolice->coverage_amount);

        $renovacao = InsurancePolicyRenewal::query()->where('insurance_policy_id', $apolice->id)->sole();
        self::assertSame('100.00', $renovacao->previous_monthly_premium);
        self::assertSame('120.00', $renovacao->new_monthly_premium);
        self::assertSame('50000.00', $renovacao->previous_coverage_amount);
        self::assertSame($corretor->id, $renovacao->recorded_by_user_id);
    }

    /**
     * Regressão: um corretor vinculado ao MESMO cliente (por outro tipo de
     * seguro) não pode renovar uma apólice que não é a dele só porque
     * `authorize('view', profile)` sozinho passaria — ver
     * ImportantDates::apoliceVinculada().
     */
    public function test_corretor_nao_pode_renovar_apolice_de_outro_corretor_do_mesmo_cliente(): void
    {
        Carbon::setTestNow('2026-01-10');
        $titular = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $titular->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $titular->id]);

        $corretorDono = User::factory()->broker()->create();
        $corretorIntruso = User::factory()->broker()->create();

        ConsultantClient::factory()->create(['consultant_id' => $corretorDono->id, 'client_id' => $titular->id, 'status' => ConsultantClientStatus::Active]);
        ConsultantClient::factory()->create(['consultant_id' => $corretorIntruso->id, 'client_id' => $titular->id, 'status' => ConsultantClientStatus::Active]);

        $apolice = InsurancePolicy::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['monthly_premium' => '75.00', 'broker_id' => $corretorDono->id]);

        $this->actingAs($corretorIntruso);

        Livewire::test(ImportantDates::class)
            ->call('startRenewal', $apolice->id)
            ->assertStatus(403);

        self::assertSame('75.00', $apolice->fresh()->monthly_premium);
        self::assertSame(0, InsurancePolicyRenewal::query()->count());
    }

    public function test_adicionar_aniversario_de_membro_sem_data(): void
    {
        [$corretor, , $membro] = $this->criarClienteVinculado();
        self::assertNull($membro->birthdate);

        Livewire::test(ImportantDates::class)
            ->call('startAddingBirthdate', $membro->id)
            ->set('birthdateInput', '1985-03-20')
            ->call('saveBirthdate')
            ->assertHasNoErrors();

        self::assertSame('1985-03-20', $membro->fresh()->birthdate->toDateString());
    }

    /** @return array{0: User, 1: FinancialProfile, 2: ProfileMember} */
    private function criarClienteVinculado(bool $consultor = false): array
    {
        $titular = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $titular->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $titular->id]);

        $profissional = $consultor ? User::factory()->consultant()->create() : User::factory()->broker()->create();

        ConsultantClient::factory()->create([
            'consultant_id' => $profissional->id, 'client_id' => $titular->id, 'status' => ConsultantClientStatus::Active,
        ]);

        $this->actingAs($profissional);

        return [$profissional, $perfil, $membro];
    }
}
