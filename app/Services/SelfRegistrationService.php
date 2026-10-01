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
    /**
     * Data de nascimento do cliente vai pro ProfileMember, não pro User
     * — é o campo que ImportantDatesService::notifyUpcomingBirthdays() já
     * lê pra avisar o profissional vinculado do aniversário do cliente.
     */
    public function registerClient(string $name, string $email, string $password, string $birthdate): User
    {
        return DB::transaction(function () use ($name, $email, $password, $birthdate): User {
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
                'birthdate' => $birthdate,
            ]);

            return $user;
        });
    }

    /**
     * $role precisa ser Consultant ou Broker — a validação do controller
     * já garante isso. Profissional não tem ProfileMember, por isso a
     * data de nascimento fica direto no User.
     */
    public function registerProfessional(string $name, string $email, string $password, UserRole $role, string $birthdate): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => $role,
            'birthdate' => $birthdate,
            'is_active' => true,
            'terms_accepted_at' => now(),
        ]);
    }
}
