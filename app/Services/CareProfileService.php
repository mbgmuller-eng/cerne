<?php

namespace App\Services;

use App\Enums\MemberRole;
use App\Enums\PlatformModule;
use App\Enums\ProfileType;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\Privacy\AccountDeletionService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pessoa acompanhada (mãe, pai...): um perfil de cuidado com uma única pessoa, sem finanças e normalmente sem login. Quem cria é o dono e
 * paga; quem cuida entra depois (CaregiverService).
 *
 * A pessoa cadastrada é dado de terceiro, e dado de saúde é sensível (LGPD, art. 11): por isso o cadastro só acontece com a declaração de
 * quem cadastra (ela autorizou, ou ele é o representante legal), gravada com data e versão do texto.
 */
class CareProfileService
{
    public const BASIS_AUTHORIZED = 'authorized';

    public const BASIS_LEGAL_REPRESENTATIVE = 'legal_representative';

    public function __construct(private readonly EntitlementService $entitlements, private readonly AccountDeletionService $deletion) {}

    public static function enabled(): bool
    {
        return (bool) config('cerne.care.enabled');
    }

    /** Pode cadastrar pessoa acompanhada: recurso ligado e assinatura direta própria com Saúde (quem paga o assento é o dono). */
    public function canCreate(User $owner): bool
    {
        return self::enabled() && $this->entitlements->userHasDirectModule($owner, PlatformModule::Saude);
    }

    public function create(User $owner, string $name, ?string $birthdate, string $basis): FinancialProfile
    {
        if (! $this->canCreate($owner)) {
            throw new RuntimeException('Cadastrar pessoa acompanhada exige uma assinatura com Saúde.');
        }

        if (! in_array($basis, [self::BASIS_AUTHORIZED, self::BASIS_LEGAL_REPRESENTATIVE], true)) {
            throw new RuntimeException('Informe se a pessoa autorizou ou se você é o representante legal.');
        }

        return DB::transaction(function () use ($owner, $name, $birthdate, $basis): FinancialProfile {
            $perfil = FinancialProfile::withCareProfiles()->create([
                'owner_user_id' => $owner->id,
                'profile_name' => $name,
                'profile_type' => ProfileType::Care,
                'base_currency' => 'BRL',
                'reference_month' => 1,
                'care_consent_by_user_id' => $owner->id,
                'care_consent_at' => now(),
                'care_consent_basis' => $basis,
                'care_consent_version' => config('cerne.legal.care_consent_version'),
            ]);

            ProfileMember::create([
                'profile_id' => $perfil->id,
                'user_id' => null,
                'name' => $name,
                'role' => MemberRole::Primary,
                'is_active' => true,
                'birthdate' => $birthdate !== '' ? $birthdate : null,
            ]);

            return $perfil;
        });
    }

    /** Apaga a pessoa acompanhada com todos os dados e arquivos dela (a cascata do banco sozinha deixaria os arquivos no disco). */
    public function delete(FinancialProfile $perfil): int
    {
        if (! $perfil->isCare()) {
            throw new RuntimeException('Só perfil de pessoa acompanhada pode ser excluído por aqui.');
        }

        return $this->deletion->deleteProfile($perfil);
    }
}
