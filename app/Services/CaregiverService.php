<?php

namespace App\Services;

use App\Enums\CaregiverAccess;
use App\Enums\CaregiverStatus;
use App\Enums\UserRole;
use App\Mail\CaregiverInviteMail;
use App\Models\FinancialProfile;
use App\Models\ProfileCaregiver;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Cuidadores de uma pessoa acompanhada: convidar, aceitar, mudar o nível, revogar. Só o DONO convida e revoga (FinancialProfilePolicy::
 * manageCaregivers, conferida por quem chama). Quem cuida nunca paga: o assento é do dono.
 */
class CaregiverService
{
    /** @return string o link do convite (para repassar por outro canal se o e-mail não chegar) */
    public function invite(FinancialProfile $profile, User $owner, string $name, string $email, CaregiverAccess $access): string
    {
        if (! $profile->isCare() || $profile->owner_user_id !== $owner->id) {
            throw new RuntimeException('Só o dono da pessoa acompanhada convida cuidadores.');
        }

        if (mb_strtolower(trim($email)) === mb_strtolower($owner->email)) {
            throw new RuntimeException('Você já é o dono: não precisa se convidar.');
        }

        ['token' => $token] = ProfileCaregiver::issue($profile, $owner, $name, $email, $access);

        $link = route('caregiver-invite.accept', ['token' => $token]);

        Mail::to($email)->queue(new CaregiverInviteMail($owner, $profile->profile_name, $name, $access, $link));

        return $link;
    }

    /** Aceite por quem já tem conta: o e-mail da conta precisa ser o do convite. */
    public function accept(ProfileCaregiver $caregiver, User $user): ProfileCaregiver
    {
        if (mb_strtolower($user->email) !== mb_strtolower($caregiver->invited_email)) {
            throw new RuntimeException('Este convite é para outro e-mail. Entre com a conta do e-mail convidado.');
        }

        $caregiver->update([
            'user_id' => $user->id,
            'status' => CaregiverStatus::Active,
            'accepted_at' => now(),
            'invite_token' => null,
            'invite_expires_at' => null,
        ]);

        return $caregiver;
    }

    /** Aceite por quem ainda não tem conta: nasce sem perfil e sem assinatura, só com acesso à pessoa que o liberou. */
    public function acceptWithNewAccount(ProfileCaregiver $caregiver, string $password): User
    {
        return DB::transaction(function () use ($caregiver, $password): User {
            $user = User::create([
                'name' => $caregiver->invited_name,
                'email' => $caregiver->invited_email,
                'password' => $password,
                'role' => UserRole::Client,
                'is_active' => true,
                'terms_accepted_at' => now(),
                'terms_version' => config('cerne.legal.terms_version'),
            ]);

            // O link chegou ao e-mail convidado: é a prova de que o endereço é dele.
            $user->forceFill(['email_verified_at' => now()])->save();

            $this->accept($caregiver, $user);

            return $user;
        });
    }

    public function changeAccess(ProfileCaregiver $caregiver, CaregiverAccess $access): void
    {
        $caregiver->update(['access_level' => $access]);
    }

    /** Revogar corta o acesso na hora (o SetProfileContext reautoriza a cada requisição). */
    public function revoke(ProfileCaregiver $caregiver): void
    {
        $caregiver->update(['status' => CaregiverStatus::Revoked, 'revoked_at' => now(), 'invite_token' => null, 'invite_expires_at' => null]);
    }
}
