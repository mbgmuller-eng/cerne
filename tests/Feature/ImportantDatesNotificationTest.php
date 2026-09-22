<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\InsurancePolicy;
use App\Models\InvestmentRecord;
use App\Models\ProfileMember;
use App\Models\User;
use App\Notifications\ClientBirthdayUpcoming;
use App\Notifications\InsurancePolicyAnniversaryUpcoming;
use App\Notifications\InsurancePolicyExpiringUpcoming;
use App\Notifications\InvestmentMaturityUpcoming;
use App\Services\ImportantDatesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Rotina de cron de "Datas importantes" — quem recebe cada tipo é a parte
 * mais sensível (ver ImportantDatesService::policyRecipients()): aniversário
 * vai pra todo profissional vinculado, apólice só pro corretor DAQUELA
 * apólice, investimento nunca pro corretor.
 */
class ImportantDatesNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $hoje;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = Carbon::parse('2026-01-01');
    }

    public function test_aniversario_notifica_consultor_e_qualquer_corretor_vinculado(): void
    {
        Notification::fake();
        [$perfil, $titular] = $this->criarPerfil();
        $membro = ProfileMember::query()->where('profile_id', $perfil->id)->sole();
        $membro->update(['birthdate' => '1990-01-08']); // 7 dias de antecedência

        $consultor = User::factory()->consultant()->create();
        $corretor = User::factory()->broker()->create();
        $this->vincular($consultor, $titular);
        $this->vincular($corretor, $titular);

        app(ImportantDatesService::class)->notifyUpcomingBirthdays($this->hoje);

        Notification::assertSentTo($consultor, ClientBirthdayUpcoming::class);
        Notification::assertSentTo($corretor, ClientBirthdayUpcoming::class);
    }

    public function test_aniversario_fora_da_antecedencia_configurada_nao_notifica(): void
    {
        Notification::fake();
        [$perfil, $titular] = $this->criarPerfil();
        $membro = ProfileMember::query()->where('profile_id', $perfil->id)->sole();
        $membro->update(['birthdate' => '1990-01-20']); // bem além dos 7 dias

        $consultor = User::factory()->consultant()->create();
        $this->vincular($consultor, $titular);

        app(ImportantDatesService::class)->notifyUpcomingBirthdays($this->hoje);

        Notification::assertNotSentTo($consultor, ClientBirthdayUpcoming::class);
    }

    public function test_aniversario_reexecutado_no_mesmo_dia_nao_duplica(): void
    {
        [$perfil, $titular] = $this->criarPerfil();
        $membro = ProfileMember::query()->where('profile_id', $perfil->id)->sole();
        $membro->update(['birthdate' => '1990-01-08']);

        $consultor = User::factory()->consultant()->create();
        $this->vincular($consultor, $titular);

        app(ImportantDatesService::class)->notifyUpcomingBirthdays($this->hoje);
        app(ImportantDatesService::class)->notifyUpcomingBirthdays($this->hoje);

        self::assertSame(1, $consultor->notifications()->where('type', ClientBirthdayUpcoming::class)->count());
    }

    public function test_aniversario_de_apolice_notifica_consultor_e_so_o_corretor_daquela_apolice(): void
    {
        Notification::fake();
        [$perfil, $titular, $membro] = $this->criarPerfilComMembro();

        $consultor = User::factory()->consultant()->create();
        $corretorDono = User::factory()->broker()->create();
        $corretorDeOutroProduto = User::factory()->broker()->create();
        $this->vincular($consultor, $titular);
        $this->vincular($corretorDono, $titular);
        $this->vincular($corretorDeOutroProduto, $titular);

        InsurancePolicy::factory()->for($perfil, 'profile')->for($membro, 'member')->create([
            'start_date' => '2023-01-08', // 7 dias de antecedência, 3 anos completos
            'broker_id' => $corretorDono->id,
        ]);

        app(ImportantDatesService::class)->notifyUpcomingPolicyAnniversaries($this->hoje);

        Notification::assertSentTo($consultor, InsurancePolicyAnniversaryUpcoming::class);
        Notification::assertSentTo($corretorDono, InsurancePolicyAnniversaryUpcoming::class);
        Notification::assertNotSentTo($corretorDeOutroProduto, InsurancePolicyAnniversaryUpcoming::class);
    }

    public function test_vencimento_de_apolice_notifica_consultor_e_corretor_daquela_apolice(): void
    {
        Notification::fake();
        [$perfil, $titular, $membro] = $this->criarPerfilComMembro();

        $corretorDono = User::factory()->broker()->create();
        $this->vincular($corretorDono, $titular);

        InsurancePolicy::factory()->for($perfil, 'profile')->for($membro, 'member')->create([
            'expiry_date' => '2026-01-08',
            'broker_id' => $corretorDono->id,
        ]);

        app(ImportantDatesService::class)->notifyUpcomingPolicyExpiries($this->hoje);

        Notification::assertSentTo($corretorDono, InsurancePolicyExpiringUpcoming::class);
    }

    public function test_vencimento_de_investimento_nunca_notifica_corretor(): void
    {
        Notification::fake();
        [$perfil, $titular, $membro] = $this->criarPerfilComMembro();

        $consultor = User::factory()->consultant()->create();
        $corretor = User::factory()->broker()->create();
        $this->vincular($consultor, $titular);
        $this->vincular($corretor, $titular);

        InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')->create([
            'maturity_date' => '2026-01-08',
        ]);

        app(ImportantDatesService::class)->notifyUpcomingInvestmentMaturities($this->hoje);

        Notification::assertSentTo($consultor, InvestmentMaturityUpcoming::class);
        Notification::assertNotSentTo($corretor, InvestmentMaturityUpcoming::class);
    }

    private function vincular(User $profissional, User $cliente): void
    {
        ConsultantClient::factory()->create([
            'consultant_id' => $profissional->id, 'client_id' => $cliente->id, 'status' => ConsultantClientStatus::Active,
        ]);
    }

    /** @return array{0: FinancialProfile, 1: User} */
    private function criarPerfil(): array
    {
        $titular = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $titular->id]);
        ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $titular->id]);

        return [$perfil, $titular];
    }

    /** @return array{0: FinancialProfile, 1: User, 2: ProfileMember} */
    private function criarPerfilComMembro(): array
    {
        [$perfil, $titular] = $this->criarPerfil();
        $membro = ProfileMember::query()->where('profile_id', $perfil->id)->sole();

        return [$perfil, $titular, $membro];
    }
}
