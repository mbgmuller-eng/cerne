<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\InviteStatus;
use App\Enums\MemberRole;
use App\Enums\ProfileType;
use App\Mail\PartnerInviteMail;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\InvestmentRecord;
use App\Models\PartnerInvite;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\ClientOnboardingService;
use App\Services\PartnerInviteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * Convite de cônjuge — titular ou consultor vinculado convida a segunda
 * pessoa do casal (ver PartnerInviteService, ClientOnboardingService::
 * addPartner()/acceptPartnerInvite()).
 */
class PartnerInviteTest extends TestCase
{
    use RefreshDatabase;

    public function test_titular_convida_o_conjuge_e_recebe_o_link(): void
    {
        Mail::fake();
        [$profile, $titular] = $this->criarPerfilIndividual();

        $link = app(PartnerInviteService::class)->send($profile, $titular, 'Helen Müller', 'helen@exemplo.com');

        self::assertNotEmpty($link);

        $invite = PartnerInvite::query()->where('profile_id', $profile->id)->sole();
        self::assertSame($titular->id, $invite->invited_by_user_id);
        self::assertSame('Helen Müller', $invite->partner_name);
        self::assertSame(InviteStatus::Pending, $invite->status);

        Mail::assertQueued(PartnerInviteMail::class);
    }

    public function test_nao_convida_de_novo_se_ja_tem_conjuge_com_login(): void
    {
        [$profile, $titular] = $this->criarPerfilIndividual();
        $profile->update(['profile_type' => ProfileType::Couple]);
        ProfileMember::factory()->secondary()->create(['profile_id' => $profile->id, 'user_id' => User::factory()]);

        $this->expectException(RuntimeException::class);

        app(PartnerInviteService::class)->send($profile, $titular, 'Helen Müller', 'helen@exemplo.com');
    }

    /**
     * Cônjuge cadastrado sem login (ver ClientOnboardingService::
     * addPartnerWithoutLogin(), ou membro importado de planilha — como o
     * caso real que motivou este teste: Manu, cônjuge do Hugo, veio de
     * uma importação e a tela não oferecia jeito nenhum de convidá-la
     * depois) PODE receber convite — é o caminho pra dar acesso a
     * alguém que entrou sem login no começo.
     */
    public function test_convida_conjuge_que_ja_existe_mas_ainda_nao_tem_login(): void
    {
        Mail::fake();
        [$profile, $titular] = $this->criarPerfilIndividual();
        $profile->update(['profile_type' => ProfileType::Couple]);
        $semLogin = ProfileMember::factory()->secondary()->create(['profile_id' => $profile->id, 'name' => 'Manu']);

        $link = app(PartnerInviteService::class)->send($profile, $titular, 'Manu', 'manu@exemplo.com');

        self::assertNotEmpty($link);
        Mail::assertQueued(PartnerInviteMail::class);

        // Nenhum ProfileMember novo foi criado — só o convite, o membro
        // sem login continua o mesmo até o convite ser aceito (ver
        // ClientOnboardingService::addPartner()).
        self::assertSame(1, ProfileMember::query()->where('profile_id', $profile->id)->where('role', MemberRole::Secondary)->count());
        self::assertNull($semLogin->fresh()->user_id);
    }

    public function test_convidar_de_novo_expira_o_convite_anterior_em_vez_de_empilhar(): void
    {
        Mail::fake();
        [$profile, $titular] = $this->criarPerfilIndividual();

        app(PartnerInviteService::class)->send($profile, $titular, 'Helen Müller', 'helen-errado@exemplo.com');
        app(PartnerInviteService::class)->send($profile, $titular, 'Helen Müller', 'helen@exemplo.com');

        $convites = PartnerInvite::query()->where('profile_id', $profile->id)->get();
        self::assertCount(2, $convites);
        self::assertSame(1, $convites->where('status', InviteStatus::Pending)->count());
        self::assertSame('helen@exemplo.com', $convites->firstWhere('status', InviteStatus::Pending)->partner_email);
    }

    public function test_aceitar_convite_cria_usuario_e_promove_o_perfil_a_casal(): void
    {
        [$profile, $titular] = $this->criarPerfilIndividual();
        ['token' => $token] = PartnerInvite::issue($profile, $titular, 'Helen Müller', 'helen@exemplo.com');

        $this->post(route('partner-invite.store', ['token' => $token]), [
            'password' => 'Senha123',
            'password_confirmation' => 'Senha123',
        ])->assertRedirect(route('dashboard'));

        $parceiro = User::query()->where('email', 'helen@exemplo.com')->sole();
        self::assertTrue($parceiro->isClient());

        $profile->refresh();
        self::assertSame(ProfileType::Couple, $profile->profile_type);

        self::assertTrue(ProfileMember::query()
            ->where('profile_id', $profile->id)
            ->where('user_id', $parceiro->id)
            ->where('role', MemberRole::Secondary)
            ->exists());

        $invite = PartnerInvite::query()->where('partner_email', 'helen@exemplo.com')->sole();
        self::assertSame(InviteStatus::Accepted, $invite->status);
    }

    /**
     * O caso real que motivou este teste: Manu (cônjuge do Hugo) foi
     * cadastrada sem login por uma importação, com investimentos e
     * contas já vinculados ao `member_id` dela. Aceitar o convite não
     * pode criar um SEGUNDO ProfileMember — isso deixaria tudo que já
     * existe (o InvestmentRecord daqui, por exemplo) órfão do login
     * novo, como se o histórico dela tivesse sumido.
     */
    public function test_aceitar_convite_de_conjuge_que_ja_existia_sem_login_reaproveita_o_mesmo_membro(): void
    {
        [$profile, $titular] = $this->criarPerfilIndividual();
        $profile->update(['profile_type' => ProfileType::Couple]);
        $semLogin = app(ClientOnboardingService::class)->addPartnerWithoutLogin($profile, 'Manu');

        $investimento = InvestmentRecord::factory()->for($profile, 'profile')->for($semLogin, 'member')->create([
            'current_amount' => '5000.00',
        ]);

        ['token' => $token] = PartnerInvite::issue($profile, $titular, 'Manu', 'manu@exemplo.com');

        $this->post(route('partner-invite.store', ['token' => $token]), [
            'password' => 'Senha123',
            'password_confirmation' => 'Senha123',
        ])->assertRedirect(route('dashboard'));

        $parceira = User::query()->where('email', 'manu@exemplo.com')->sole();

        // Continua sendo o MESMO id de membro — não um segundo.
        self::assertSame(1, ProfileMember::query()->where('profile_id', $profile->id)->where('role', MemberRole::Secondary)->count());
        $semLogin->refresh();
        self::assertSame($parceira->id, $semLogin->user_id);

        // O investimento cadastrado antes do login continua vinculado a ela.
        self::assertSame($semLogin->id, $investimento->fresh()->member_id);
    }

    public function test_convite_expirado_nao_cria_conta(): void
    {
        [$profile, $titular] = $this->criarPerfilIndividual();
        ['token' => $token] = PartnerInvite::issue($profile, $titular, 'Helen Müller', 'helen@exemplo.com');
        PartnerInvite::query()->where('profile_id', $profile->id)->update(['expires_at' => now()->subDay()]);

        $this->post(route('partner-invite.store', ['token' => $token]), [
            'password' => 'Senha123',
            'password_confirmation' => 'Senha123',
        ])->assertSessionHasErrors('password');

        self::assertSame(0, User::query()->where('email', 'helen@exemplo.com')->count());
    }

    public function test_consultor_vinculado_tambem_pode_convidar(): void
    {
        Mail::fake();
        [$profile, $titular] = $this->criarPerfilIndividual();
        $consultor = User::factory()->consultant()->create();
        ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id,
            'client_id' => $titular->id,
            'status' => ConsultantClientStatus::Active,
        ]);

        self::assertTrue($consultor->can('manageMembers', $profile));

        $link = app(PartnerInviteService::class)->send($profile, $consultor, 'Helen Müller', 'helen@exemplo.com');

        self::assertNotEmpty($link);
        $invite = PartnerInvite::query()->where('profile_id', $profile->id)->sole();
        self::assertSame($consultor->id, $invite->invited_by_user_id);
    }

    public function test_consultor_sem_vinculo_nao_pode_convidar(): void
    {
        [$profile] = $this->criarPerfilIndividual();
        $outroConsultor = User::factory()->consultant()->create();

        self::assertFalse($outroConsultor->can('manageMembers', $profile));
    }

    /**
     * Não faz actingAs de propósito: o aceite de convite (partner-invite.
     * store) é rota de visitante — se o teste continuasse "logado" como o
     * titular, o middleware guest redirecionaria antes de a request
     * chegar no controller, e a asserção falharia sem dizer por quê.
     *
     * @return array{0: FinancialProfile, 1: User}
     */
    private function criarPerfilIndividual(): array
    {
        $titular = User::factory()->create();
        $profile = FinancialProfile::factory()->create(['owner_user_id' => $titular->id]);
        ProfileMember::factory()->create([
            'profile_id' => $profile->id,
            'user_id' => $titular->id,
            'role' => MemberRole::Primary,
        ]);

        return [$profile, $titular];
    }
}
