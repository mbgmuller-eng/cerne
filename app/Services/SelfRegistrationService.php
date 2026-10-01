<?php

namespace App\Services;

use App\Enums\MemberRole;
use App\Enums\ProfileType;
use App\Enums\UserRole;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cadastro sem convite — mirror de ClientOnboardingService::acceptInvite()
 * e ProfessionalOnboardingService::acceptInvite(), mas não há convite pra
 * consumir e `email_verified_at` fica nulo de propósito: ninguém vouch por
 * esse e-mail, quem confirma é a própria pessoa clicando o link (ver
 * VerifyEmailAddress). `unique:users,email` na validação do controller já
 * barra duplicata antes de chegar aqui, então não precisa do desvio de
 * "conta já existe" que o aceite de convite tem.
 */
class SelfRegistrationService
{
    public function registerClient(string $name, string $email, string $password): User
    {
        return DB::transaction(function () use ($name, $email, $password): User {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'role' => UserRole::Client,
                'is_active' => true,
                'terms_accepted_at' => now(),
            ]);

            $profile = FinancialProfile::create([
                'owner_user_id' => $user->id,
                'profile_name' => $name,
                'profile_type' => ProfileType::Single,
                'base_currency' => 'BRL',
                'reference_month' => 1,
            ]);

            ProfileMember::create([
                'profile_id' => $profile->id,
                'user_id' => $user->id,
                'name' => $name,
                'role' => MemberRole::Primary,
                'is_active' => true,
            ]);

            return $user;
        });
    }

    /** $role precisa ser Consultant ou Broker — a validação do controller já garante isso. */
    public function registerProfessional(string $name, string $email, string $password, UserRole $role): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => $role,
            'is_active' => true,
            'terms_accepted_at' => now(),
        ]);
    }
}
