<?php

namespace Tests\Feature;

use App\Enums\MemberRole;
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
 * respeitar o que um membro pode ver dentro do próprio casal.
 *
 * A regra é SIMÉTRICA agora — cada lançamento decide por si (`is_private`)
 * se fica oculto do cônjuge, e vale igual pros dois; não existe mais um
 * "dono do perfil" que enxerga tudo por natureza.
 */
class MemberPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_membro_so_nao_ve_o_que_o_outro_marcou_como_oculto(): void
    {
        [$profile, , $ownerMember, $secondaryUser, $secondaryMember] = $this->createCouple();

        $meu = ExpenseRecord::factory()->for($profile, 'profile')->create([
            'member_id' => $secondaryMember->id,
            'is_private' => true,
        ]);
        $doOutroOculto = ExpenseRecord::factory()->for($profile, 'profile')->create([
            'member_id' => $ownerMember->id,
            'is_private' => true,
        ]);
        $doOutroVisivel = ExpenseRecord::factory()->for($profile, 'profile')->create([
            'member_id' => $ownerMember->id,
            'is_private' => false,
        ]);
        $daFamilia = ExpenseRecord::factory()->for($profile, 'profile')->create([
            'member_id' => null,
        ]);

        $this->actingAs($secondaryUser);
        app(ProfileContext::class)->set($profile, $secondaryMember);

        $visiveis = ExpenseRecord::all();

        self::assertTrue($visiveis->contains($meu));
        self::assertTrue($visiveis->contains($daFamilia));
        self::assertTrue($visiveis->contains($doOutroVisivel));
        self::assertFalse($visiveis->contains($doOutroOculto));
    }

    /**
     * A simetria é o que muda em relação ao modelo antigo: o titular
     * (dono do perfil) TAMBÉM não vê o que o cônjuge marcou como oculto
     * — não é mais um caso especial que enxerga tudo.
     */
    public function test_titular_tambem_nao_ve_o_que_o_conjuge_marcou_como_oculto(): void
    {
        [$profile, $owner, $ownerMember, , $secondaryMember] = $this->createCouple();

        $doConjugeOculto = ExpenseRecord::factory()->for($profile, 'profile')->create([
            'member_id' => $secondaryMember->id,
            'is_private' => true,
        ]);
        $doConjugeVisivel = ExpenseRecord::factory()->for($profile, 'profile')->create([
            'member_id' => $secondaryMember->id,
            'is_private' => false,
        ]);

        $this->actingAs($owner);
        app(ProfileContext::class)->set($profile, $ownerMember);

        $visiveis = ExpenseRecord::all();

        self::assertFalse($visiveis->contains($doConjugeOculto));
        self::assertTrue($visiveis->contains($doConjugeVisivel));
    }

    /** Consultor vinculado vê tudo, inclusive o que é oculto entre o casal. */
    public function test_consultor_vinculado_ve_tudo_independente_da_privacidade(): void
    {
        [$profile, , , , $secondaryMember] = $this->createCouple();

        $oculto = ExpenseRecord::factory()->for($profile, 'profile')->create([
            'member_id' => $secondaryMember->id,
            'is_private' => true,
        ]);

        $consultor = User::factory()->consultant()->create();
        $this->actingAs($consultor);
        app(ProfileContext::class)->set($profile, member: null, asConsultant: true);

        self::assertTrue(ExpenseRecord::all()->contains($oculto));
    }

    /**
     * Conta conjunta escapa da restrição por natureza: pertence ao casal,
     * não a um membro — mesmo tentando marcar como privada (is_joint força
     * visible_to_partner=true, ver HasSharingFlags).
     */
    public function test_conta_conjunta_e_sempre_visivel_mesmo_tentando_marcar_como_privada(): void
    {
        [$profile, , $ownerMember, $secondaryUser, $secondaryMember] = $this->createCouple();

        $contaConjunta = BankAccount::factory()
            ->for($profile, 'profile')
            ->for($ownerMember, 'member')
            ->joint()
            ->create(['visible_to_partner' => false]);

        $this->actingAs($secondaryUser);
        app(ProfileContext::class)->set($profile, $secondaryMember);

        self::assertTrue(BankAccount::all()->contains($contaConjunta));
    }

    /**
     * Conta/cartão usa `visible_to_partner` (campo próprio, anterior ao
     * `is_private` genérico) — mesma regra, coluna diferente.
     */
    public function test_conta_com_visible_to_partner_falso_fica_oculta_do_conjuge(): void
    {
        [$profile, , $ownerMember, $secondaryUser, $secondaryMember] = $this->createCouple();

        $contaPrivada = BankAccount::factory()
            ->for($profile, 'profile')
            ->for($ownerMember, 'member')
            ->create(['is_joint' => false, 'visible_to_partner' => false]);

        $this->actingAs($secondaryUser);
        app(ProfileContext::class)->set($profile, $secondaryMember);

        self::assertFalse(BankAccount::all()->contains($contaPrivada));
    }

    /**
     * @return array{0: FinancialProfile, 1: User, 2: ProfileMember, 3: User, 4: ProfileMember}
     */
    private function createCouple(): array
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

        return [$profile, $owner, $ownerMember, $secondaryUser, $secondaryMember];
    }
}
