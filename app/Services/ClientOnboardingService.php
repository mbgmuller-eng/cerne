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
 * São até cinco inserções que precisam acontecer juntas ou não acontecer:
 * usuário, perfil, membro titular, vínculo com o consultor (pulado quando o
 * convite não tem consultor — ver ConsultantInvite::issue()) e baixa do
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

            // Convite emitido pelo painel admin (ver ClientInviteService::
            // sendStandalone()) não tem consultor — a conta fica
            // deliberadamente sem vínculo nenhum, ninguém além do próprio
            // dono enxerga os dados dela.
            if ($invite->consultant_id !== null) {
                ConsultantClient::create([
                    'consultant_id' => $invite->consultant_id,
                    'client_id' => $user->id,
                    'status' => ConsultantClientStatus::Active,
                    'invited_at' => $invite->created_at,
                    'accepted_at' => now(),
                ]);
            }

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
     * Casal ganha privacidade granular (`is_private` por lançamento) quando
     * cada membro loga — mas login não é obrigatório: ver
     * addPartnerWithoutLogin() pra quem não quer conta nenhuma.
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
     * Acrescenta o cônjuge sem criar login nenhum — caso do casal em que
     * um dos dois não quer (ou não vai) acessar a plataforma. Conta
     * bancária, gasto e investimento em nome dele(a) funcionam normal
     * (tudo é vinculado por `member_id`, não por usuário); a única
     * consequência real é que nada dele(a) pode ser marcado como privado —
     * sem login, ninguém (nem ele(a)) veria esse dado, então
     * `MemberPrivacyScope` simplesmente esconde de todo mundo menos do
     * consultor. Ver `PartnerInviteService::alreadyHasPartner()`: verifica
     * por `role === Secondary`, não por `user_id`, então já bloqueia um
     * convite por e-mail depois deste cadastro sozinho.
     */
    public function addPartnerWithoutLogin(FinancialProfile $profile, string $name): ProfileMember
    {
        return DB::transaction(function () use ($profile, $name): ProfileMember {
            $profile->update(['profile_type' => ProfileType::Couple]);

            return ProfileMember::create([
                'profile_id' => $profile->id,
                'user_id' => null,
                'name' => $name,
                'role' => MemberRole::Secondary,
                'is_active' => true,
            ]);
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
