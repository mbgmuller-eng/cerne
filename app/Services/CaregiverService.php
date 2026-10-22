<?php

namespace App\Services;

use App\Enums\CaregiverAccess;
use App\Enums\CaregiverStatus;
use App\Enums\UserRole;
use App\Mail\CaregiverInviteMail;
use App\Models\FinancialProfile;
use App\Models\ProfileCaregiver;
use App\Models\ProfileMember;
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

        // A pessoa acompanhada tem um membro só: o acesso é a ele.
        $membro = ProfileMember::query()->where('profile_id', $profile->id)->orderBy('created_at')->firstOrFail();

        ['token' => $token] = ProfileCaregiver::issue($profile, $membro, $owner, $name, $email, $access);

        $link = route('caregiver-invite.accept', ['token' => $token]);

        Mail::to($email)->queue(new CaregiverInviteMail($owner, $profile->profile_name, $name, $access, $link));

        return $link;
    }

    /**
     * Convida alguém para acompanhar a MINHA saúde (ou a de quem eu sou): não exige assinatura, de quem convida nem de quem acompanha. Cada um do
     * casal convida só para a própria saúde; quem acompanha não vê a do cônjuge nem nada de finanças.
     *
     * @return string o link do convite
     */
    public function inviteFollower(ProfileMember $member, User $inviter, string $name, string $email, CaregiverAccess $access): string
    {
        $perfil = FinancialProfile::withCareProfiles()->findOrFail($member->profile_id);

        if ($perfil->isCare() || $member->user_id !== $inviter->id) {
            throw new RuntimeException('Você só convida alguém para acompanhar a sua própria saúde.');
        }

        if (mb_strtolower(trim($email)) === mb_strtolower($inviter->email)) {
            throw new RuntimeException('Você não precisa se convidar.');
        }

        // Quem já divide o perfil comigo (o cônjuge) já enxerga o que eu cadastro de saúde: convidá-lo como cuidador não faria sentido.
        if (ProfileMember::query()->where('profile_id', $member->profile_id)->where('is_active', true)->whereHas('user', fn ($q) => $q->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))]))->exists()) {
            throw new RuntimeException('Essa pessoa já divide o perfil com você e já vê a sua ficha de saúde.');
        }

        ['token' => $token] = ProfileCaregiver::issue($perfil, $member, $inviter, $name, $email, $access);

        $link = route('caregiver-invite.accept', ['token' => $token]);

        Mail::to($email)->queue(new CaregiverInviteMail($inviter, $member->name, $name, $access, $link, true));

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
