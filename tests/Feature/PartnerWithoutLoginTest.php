<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\MemberRole;
use App\Enums\ProfileType;
use App\Livewire\Profile\MyAccount;
use App\Models\ConsultantClient;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\ClientOnboardingService;
use App\Services\PartnerInviteService;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Cônjuge sem login: um `ProfileMember` com `user_id` nulo dentro de um
 * casal — ele nunca pode logar, mas continua dono de contas/gastos/
 * investimentos por `member_id`, igual a qualquer outro membro.
 */
class PartnerWithoutLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_addpartnerwithoutlogin_cria_membro_sem_usuario_e_promove_o_perfil(): void
    {
        $owner = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $owner->id, 'profile_type' => 'single']);

        $membro = app(ClientOnboardingService::class)->addPartnerWithoutLogin($perfil, 'Mariana');

        self::assertNull($membro->user_id);
        self::assertSame('Mariana', $membro->name);
        self::assertSame(MemberRole::Secondary, $membro->role);
        self::assertSame(ProfileType::Couple, $perfil->fresh()->profile_type);
    }

    /**
     * `partner`/`canInvitePartner` são dado de view (passados só pro
     * `view(...)->with([...])` de render()), não propriedade pública do
     * componente — `Testable::get()` não os enxerga. A forma certa de
     * conferir aqui é pelo HTML renderizado, não por `->get(...)`.
     */
    public function test_myaccount_encontra_o_conjuge_sem_login_como_partner(): void
    {
        [$owner, $perfil, $membro] = $this->criarTitular();
        app(ClientOnboardingService::class)->addPartnerWithoutLogin($perfil, 'Mariana');

        Livewire::test(MyAccount::class)
            ->assertSee('Mariana')
            ->assertSee('Cadastrado sem login')
            ->assertDontSee('Convidar por e-mail');
    }

    public function test_convidar_por_email_depois_e_bloqueado(): void
    {
        [$owner, $perfil, $membro] = $this->criarTitular();
        app(ClientOnboardingService::class)->addPartnerWithoutLogin($perfil, 'Mariana');

        $this->expectException(RuntimeException::class);

        app(PartnerInviteService::class)->send($perfil, $owner, 'Mariana', 'mariana@example.com');
    }

    public function test_lancamento_privado_do_conjuge_sem_login_fica_invisivel_pro_titular(): void
    {
        [$owner, $perfil, $ownerMember] = $this->criarTitular();
        $marianaMember = app(ClientOnboardingService::class)->addPartnerWithoutLogin($perfil, 'Mariana');

        $privado = ExpenseRecord::factory()->for($perfil, 'profile')->create([
            'member_id' => $marianaMember->id, 'is_private' => true,
        ]);
        $visivel = ExpenseRecord::factory()->for($perfil, 'profile')->create([
            'member_id' => $marianaMember->id, 'is_private' => false,
        ]);

        app(ProfileContext::class)->set($perfil, $ownerMember);
        $visiveis = ExpenseRecord::all();

        self::assertFalse($visiveis->contains($privado));
        self::assertTrue($visiveis->contains($visivel));
    }

    /**
     * Bug real: um consultor vendo o perfil do cliente não é membro
     * nenhum (memberId() vem nulo). Sem essa distinção,
     * `where('id', '!=', null)` do Eloquent vira `WHERE id IS NOT NULL`
     * (Laravel converte comparação com null pra whereNull/whereNotNull) —
     * ou seja, "qualquer membro" — e o consultor via o próprio titular
     * listado como cônjuge dele mesmo, mesmo sem cônjuge nenhum aceito
     * ainda (só convite pendente).
     */
    public function test_consultor_vendo_convite_pendente_nao_mostra_o_titular_como_conjuge(): void
    {
        $owner = User::factory()->create(['name' => 'André Albuquerque', 'email' => 'andre.owner@example.com']);
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $owner->id]);
        ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $owner->id, 'role' => MemberRole::Primary]);

        $consultor = User::factory()->consultant()->create();
        ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id, 'client_id' => $owner->id, 'status' => ConsultantClientStatus::Active,
        ]);
        app(PartnerInviteService::class)->send($perfil, $owner, 'Chantal', 'chantal@example.com');

        $this->actingAs($consultor);
        app(ProfileContext::class)->set($perfil, member: null, asConsultant: true);

        // "Meus dados" mostra o e-mail do André normalmente (ver teste
        // dedicado abaixo) — o que este teste prova é que a seção Cônjuge
        // não é ELE de novo: mostra o convite pendente da Chantal.
        Livewire::test(MyAccount::class)
            ->assertSeeInOrder(['Cônjuge', 'Convite enviado', 'Chantal', 'aguardando aceite']);
    }

    /** Mesmo cenário, mas o cônjuge já é um membro de verdade (aceitou ou foi cadastrado sem login) — o consultor precisa ver ELE, não o titular. */
    public function test_consultor_vendo_conjuge_ja_cadastrado_mostra_o_conjuge_certo(): void
    {
        $owner = User::factory()->create(['name' => 'André Albuquerque']);
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $owner->id]);
        ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $owner->id, 'role' => MemberRole::Primary]);
        app(ClientOnboardingService::class)->addPartnerWithoutLogin($perfil, 'Chantal');

        $consultor = User::factory()->consultant()->create();

        $this->actingAs($consultor);
        app(ProfileContext::class)->set($perfil, member: null, asConsultant: true);

        Livewire::test(MyAccount::class)
            ->assertSee('Chantal')
            ->assertSee('Cadastrado sem login');
    }

    /**
     * "Meus dados" precisa mostrar o TITULAR do perfil sendo visto, não
     * quem está logado — pro consultor, isso é o cliente (André), não o
     * próprio consultor. Antes deste ajuste, render() sempre usava
     * auth()->user() direto, então o consultor via o próprio nome/e-mail
     * em "Meus dados" mesmo estando dentro do perfil do cliente.
     */
    public function test_consultor_ve_os_dados_do_titular_em_meus_dados_nao_os_proprios(): void
    {
        $owner = User::factory()->create(['name' => 'André Albuquerque', 'email' => 'andre.owner@example.com']);
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $owner->id]);
        ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $owner->id, 'role' => MemberRole::Primary]);

        $consultor = User::factory()->consultant()->create(['name' => 'Marcelo Müller', 'email' => 'marcelo@example.com']);

        $this->actingAs($consultor);
        app(ProfileContext::class)->set($perfil, member: null, asConsultant: true);

        Livewire::test(MyAccount::class)
            ->assertSee('André Albuquerque')
            ->assertSee('andre.owner@example.com')
            ->assertDontSee('Marcelo Müller')
            ->assertDontSee('marcelo@example.com');
    }

    /** O titular vendo a própria conta continua vendo os próprios dados — não muda com este ajuste. */
    public function test_titular_continua_vendo_os_proprios_dados(): void
    {
        [$owner, , $ownerMember] = $this->criarTitular();
        $owner->update(['name' => 'André Albuquerque', 'email' => 'andre.owner@example.com']);

        Livewire::test(MyAccount::class)
            ->assertSee('André Albuquerque')
            ->assertSee('andre.owner@example.com');
    }

    public function test_livewire_cadastra_conjuge_sem_login_pela_tela(): void
    {
        [$owner, $perfil] = $this->criarTitular();

        Livewire::test(MyAccount::class)
            ->set('partnerOnlyName', 'Mariana')
            ->call('addPartnerWithoutLogin')
            ->assertHasNoErrors();

        $membro = ProfileMember::query()->where('profile_id', $perfil->id)->where('name', 'Mariana')->sole();
        self::assertNull($membro->user_id);
    }

    /** @return array{0: User, 1: FinancialProfile, 2: ProfileMember} */
    private function criarTitular(): array
    {
        $owner = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $owner->id]);
        $ownerMember = ProfileMember::factory()->create([
            'profile_id' => $perfil->id, 'user_id' => $owner->id, 'role' => MemberRole::Primary,
        ]);
        $this->actingAs($owner);
        app(ProfileContext::class)->set($perfil, $ownerMember);

        return [$owner, $perfil, $ownerMember];
    }
}
