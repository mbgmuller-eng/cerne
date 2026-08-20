<?php

namespace App\Services;

use App\Enums\ConsultantClientStatus;
use App\Enums\InviteStatus;
use App\Enums\MemberRole;
use App\Enums\ProfileType;
use App\Enums\UserRole;
use App\Models\ConsultantClient;
use App\Models\ConsultantInvite;
use App\Models\FinancialProfile;
use App\Models\ProfileAccessSettings;
use App\Models\ProfileMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Transforma um convite aceito em cliente operante.
 *
 * São seis inserções que precisam acontecer juntas ou não acontecer:
 * usuário, perfil, membro titular, configuração de privacidade, vínculo
 * com o consultor e baixa do convite. Um cliente com perfil mas sem
 * membro titular, ou com perfil mas sem privacidade configurada, é um
 * estado quebrado difícil de diagnosticar depois — daí a transação.
 */
class ClientOnboardingService
{
    /**
     * @param  string  $password  Senha em claro; o cast `hashed` do model cuida do resto.
     */
    public function acceptInvite(ConsultantInvite $invite, string $password, ?string $profileName = null): User
    {
        return DB::transaction(function () use ($invite, $password, $profileName): User {
            $user = User::create([
                'name' => $invite->client_name,
                'email' => $invite->client_email,
                'password' => $password,
                'role' => UserRole::Client,
                'is_active' => true,
            ]);

            $user->forceFill(['email_verified_at' => now()])->save();

            $profile = FinancialProfile::create([
                'owner_user_id' => $user->id,
                'profile_name' => $profileName ?: $this->defaultProfileName($invite->client_name),
                'profile_type' => ProfileType::Single,
                'base_currency' => 'BRL',
                'reference_month' => 1,
            ]);

            ProfileMember::create([
                'profile_id' => $profile->id,
                'user_id' => $user->id,
                'name' => $invite->client_name,
                'role' => MemberRole::Primary,
                'is_active' => true,
            ]);

            // Perfil individual nasce transparente; a tela 9 só passa a
            // fazer diferença quando o perfil vira casal.
            ProfileAccessSettings::create(array_merge(
                ProfileAccessSettings::transparentPreset(),
                [
                    'profile_id' => $profile->id,
                    'updated_by_user_id' => $user->id,
                ],
            ));

            ConsultantClient::create([
                'consultant_id' => $invite->consultant_id,
                'client_id' => $user->id,
                'status' => ConsultantClientStatus::Active,
                'invited_at' => $invite->created_at,
                'accepted_at' => now(),
            ]);

            $invite->update([
                'status' => InviteStatus::Accepted,
                'accepted_at' => now(),
            ]);

            return $user;
        });
    }

    /**
     * Acrescenta o cônjuge a um perfil, promovendo-o a `couple`.
     *
     * Perfil de casal exige login próprio para cada membro — é o que torna
     * a privacidade granular aplicável (ver ProfileType::requiresMemberLogin).
     */
    public function addPartner(FinancialProfile $profile, string $name, string $email, string $password): ProfileMember
    {
        return DB::transaction(function () use ($profile, $name, $email, $password): ProfileMember {
            $partner = User::create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'role' => UserRole::Client,
                'is_active' => true,
            ]);

            $profile->update(['profile_type' => ProfileType::Couple]);

            return ProfileMember::create([
                'profile_id' => $profile->id,
                'user_id' => $partner->id,
                'name' => $name,
                'role' => MemberRole::Secondary,
                'is_active' => true,
            ]);
        });
    }

    private function defaultProfileName(string $clientName): string
    {
        $firstName = trim(explode(' ', trim($clientName))[0]);

        return $firstName !== '' ? "Finanças de {$firstName}" : 'Minhas finanças';
    }
}
