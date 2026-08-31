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
use App\Models\PartnerInvite;
use App\Models\ProfileMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Transforma um convite aceito em cliente operante.
 *
 * São cinco inserções que precisam acontecer juntas ou não acontecer:
 * usuário, perfil, membro titular, vínculo com o consultor e baixa do
 * convite. Um cliente com perfil mas sem membro titular é um estado
 * quebrado difícil de diagnosticar depois — daí a transação.
 */
class ClientOnboardingService
{
    /**
     * @param  string  $password  Senha em claro; o cast `hashed` do model cuida do resto.
     */
    public function acceptInvite(ConsultantInvite $invite, string $password): User
    {
        return DB::transaction(function () use ($invite, $password): User {
            $user = User::create([
                'name' => $invite->client_name,
                'email' => $invite->client_email,
                'password' => $password,
                'role' => UserRole::Client,
                'is_active' => true,
            ]);

            $user->forceFill(['email_verified_at' => now()])->save();

            // O nome do perfil é sempre o que o consultor escreveu ao
            // convidar — "Marcelo Müller" vira o perfil "Marcelo Müller",
            // "Marcelo e Helen" vira "Marcelo e Helen". Não é mais algo
            // que o cliente escolhe na tela de aceite.
            $profile = FinancialProfile::create([
                'owner_user_id' => $user->id,
                'profile_name' => $invite->client_name,
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
    public function addPartner(FinancialProfile $profile, string $name, string $email, string $password): User
    {
        return DB::transaction(function () use ($profile, $name, $email, $password): User {
            $partner = User::create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'role' => UserRole::Client,
                'is_active' => true,
            ]);

            $partner->forceFill(['email_verified_at' => now()])->save();

            $profile->update(['profile_type' => ProfileType::Couple]);

            ProfileMember::create([
                'profile_id' => $profile->id,
                'user_id' => $partner->id,
                'name' => $name,
                'role' => MemberRole::Secondary,
                'is_active' => true,
            ]);

            return $partner;
        });
    }

    /**
     * Aceite do convite de cônjuge (PartnerInvite) — chama addPartner() e
     * baixa o convite na mesma transação, mesmo raciocínio de
     * acceptInvite() com o convite de cliente.
     */
    public function acceptPartnerInvite(PartnerInvite $invite, string $password): User
    {
        return DB::transaction(function () use ($invite, $password): User {
            $partner = $this->addPartner($invite->profile, $invite->partner_name, $invite->partner_email, $password);

            $invite->update([
                'status' => InviteStatus::Accepted,
                'accepted_at' => now(),
            ]);

            return $partner;
        });
    }
}
