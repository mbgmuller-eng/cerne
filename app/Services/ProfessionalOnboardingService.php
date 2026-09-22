<?php

namespace App\Services;

use App\Enums\InviteStatus;
use App\Models\ProfessionalInvite;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aceite de convite profissional (Consultor ou Corretor) — mirror de
 * ClientOnboardingService::acceptInvite(), mas sem FinancialProfile nem
 * ProfileMember: profissional não é dono de perfil, só enxerga o dos
 * clientes vinculados (ver ConsultantLinkService).
 */
class ProfessionalOnboardingService
{
    public function acceptInvite(ProfessionalInvite $invite, string $password): User
    {
        return DB::transaction(function () use ($invite, $password): User {
            if (User::where('email', $invite->email)->exists()) {
                throw ValidationException::withMessages([
                    'password' => 'Esse e-mail já tem conta no Cerne.',
                ]);
            }

            $user = User::create([
                'name' => $invite->name,
                'email' => $invite->email,
                'password' => $password,
                'role' => $invite->role,
                'is_active' => true,
            ]);

            $user->forceFill(['email_verified_at' => now()])->save();

            $invite->update([
                'status' => InviteStatus::Accepted,
                'accepted_at' => now(),
            ]);

            return $user;
        });
    }
}
