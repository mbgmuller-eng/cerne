<?php

namespace Tests\Feature;

use App\Enums\MemberRole;
use App\Enums\Visibility;
use App\Models\BankAccount;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Privacidade do casal (CLAUDE.md, regra 2): uma camada ACIMA do tenancy.
 * Passar no isolamento entre perfis (TenancyIsolationTest) não significa
 * respeitar o que um membro secundário pode ver dentro do próprio casal.
 */
class MemberPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_membro_secundario_so_ve_os_proprios_lancamentos_e_os_da_familia(): void
    {
        [$profile, $owner, $ownerMember, $secondaryUser, $secondaryMember] = $this->createRestrictedCouple();

        $meu = ExpenseRecord::factory()->for($profile, 'profile')->create([
            'member_id' => $secondaryMember->id,
            'created_by_user_id' => $secondaryUser->id,
        ]);
        $doOutro = ExpenseRecord::factory()->for($profile, 'profile')->create([
            'member_id' => $ownerMember->id,
            'created_by_user_id' => $owner->id,
        ]);
        $daFamilia = ExpenseRecord::factory()->for($profile, 'profile')->create([
            'member_id' => null,
            'created_by_user_id' => $owner->id,
        ]);

        $this->actingAs($secondaryUser);
        app(ProfileContext::class)->set($profile, $secondaryMember);

        $visiveis = ExpenseRecord::all();

        self::assertTrue($visiveis->contains($meu));
        self::assertTrue($visiveis->contains($daFamilia));
        self::assertFalse($visiveis->contains($doOutro));
    }

    /** Dono ignora a privacidade que ele mesmo configurou — vê tudo sempre. */
    public function test_titular_ve_todos_os_lancamentos_mesmo_com_privacidade_restrita(): void
    {
        [$profile, $owner, $ownerMember, $secondaryUser, $secondaryMember] = $this->createRestrictedCouple();

        $doOutro = ExpenseRecord::factory()->for($profile, 'profile')->create([
            'member_id' => $secondaryMember->id,
            'created_by_user_id' => $secondaryUser->id,
        ]);

        $this->actingAs($owner);
        app(ProfileContext::class)->set($profile, $ownerMember);

        self::assertTrue(ExpenseRecord::all()->contains($doOutro));
    }

    /** Consultor vinculado vê tudo, inclusive o que é privado entre o casal. */
    public function test_consultor_vinculado_ve_tudo_independente_da_privacidade(): void
    {
        [$profile, $owner, $ownerMember, $secondaryUser, $secondaryMember] = $this->createRestrictedCouple();

        $doOutro = ExpenseRecord::factory()->for($profile, 'profile')->create([
            'member_id' => $secondaryMember->id,
            'created_by_user_id' => $secondaryUser->id,
        ]);

        $consultor = User::factory()->consultant()->create();
        $this->actingAs($consultor);
        app(ProfileContext::class)->set($profile, member: null, asConsultant: true);

        self::assertTrue(ExpenseRecord::all()->contains($doOutro));
    }

    /**
     * Conta conjunta escapa da restrição por natureza: pertence ao casal,
     * não a um membro — mesmo com bank_account_visibility em own_only.
     */
    public function test_conta_conjunta_e_visivel_ao_secundario_mesmo_com_privacidade_restrita(): void
    {
        [$profile, $owner, $ownerMember, $secondaryUser, $secondaryMember] = $this->createRestrictedCouple();

        $contaConjunta = BankAccount::factory()
            ->for($profile, 'profile')
            ->for($ownerMember, 'member')
            ->joint()
            ->create();

        $this->actingAs($secondaryUser);
        app(ProfileContext::class)->set($profile, $secondaryMember);

        self::assertTrue(BankAccount::all()->contains($contaConjunta));
    }

    /**
     * Perfil novo, ninguém nunca configurou nada: settings() precisa
     * materializar transparent na hora — não pode ler como "private" só
     * porque o objeto recém-criado não é recarregado do banco (a
     * constraint única de profile_id em profile_access_settings faz
     * `create()` não devolver os DEFAULTs da coluna automaticamente).
     */
    public function test_perfil_novo_materializa_configuracao_transparente_na_primeira_leitura(): void
    {
        $profile = FinancialProfile::factory()->create();

        self::assertSame('transparent', $profile->settings()->preset());
        self::assertTrue($profile->settings()->sharesDomain('expense_visibility'));
    }

    /**
     * @return array{0: FinancialProfile, 1: User, 2: ProfileMember, 3: User, 4: ProfileMember}
     */
    private function createRestrictedCouple(): array
    {
        $owner = User::factory()->create();
        $profile = FinancialProfile::factory()->couple()->create(['owner_user_id' => $owner->id]);
        $ownerMember = ProfileMember::factory()->create([
            'profile_id' => $profile->id,
            'user_id' => $owner->id,
            'role' => MemberRole::Primary,
        ]);

        $secondaryUser = User::factory()->create();
        $secondaryMember = ProfileMember::factory()->secondary()->create([
            'profile_id' => $profile->id,
            'user_id' => $secondaryUser->id,
        ]);

        $profile->settings()->update([
            'expense_visibility' => Visibility::OwnOnly,
            'bank_account_visibility' => Visibility::OwnOnly,
        ]);

        return [$profile, $owner, $ownerMember, $secondaryUser, $secondaryMember];
    }
}
