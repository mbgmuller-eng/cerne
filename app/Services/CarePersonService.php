<?php

namespace App\Services;

use App\Enums\CaregiverStatus;
use App\Enums\InviteStatus;
use App\Enums\UserRole;
use App\Mail\CarePersonInviteMail;
use App\Models\CarePersonInvite;
use App\Models\FinancialProfile;
use App\Models\ProfileCaregiver;
use App\Models\ProfileMember;
use App\Models\User;
use App\Notifications\CareSpaceEnded;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * A própria pessoa acompanhada passa a usar o app (a mãe que, depois de os filhos começarem, quer ver e controlar o que é dela).
 *
 * O dado não muda de lugar: o login dela é ligado ao membro que já existe no perfil de cuidado, e o dono continua dono e responsável pelo
 * pagamento. O que muda é quem manda no acesso: ela vê quem cuida dela e pode encerrar o acesso de cada um, revogar o consentimento ou
 * encerrar o espaço inteiro (direito de eliminação, LGPD art. 18, VI). Ao aceitar, o consentimento passa a ser DELA (`self`), não mais só a
 * declaração de quem cadastrou.
 */
class CarePersonService
{
    public const BASIS_SELF = 'self';

    public function __construct(private readonly CareProfileService $profiles) {}

    /** @return string o link do convite (para repassar por outro canal se o e-mail não chegar) */
    public function invite(FinancialProfile $profile, User $owner, string $email): string
    {
        if (! $profile->isCare() || $profile->owner_user_id !== $owner->id) {
            throw new RuntimeException('Só o dono da pessoa acompanhada a convida para usar o app.');
        }

        $membro = $this->personOf($profile);

        if ($membro->user_id !== null) {
            throw new RuntimeException('Esta pessoa já tem acesso próprio.');
        }

        if (mb_strtolower(trim($email)) === mb_strtolower($owner->email)) {
            throw new RuntimeException('Use o e-mail da própria pessoa, não o seu.');
        }

        ['token' => $token] = CarePersonInvite::issue($profile, $membro, $owner, $email);

        $link = route('care-person-invite.accept', ['token' => $token]);

        Mail::to($email)->queue(new CarePersonInviteMail($owner, $membro->name, $link));

        return $link;
    }

    /** Aceite por quem já tem conta (o e-mail da conta precisa ser o do convite). */
    public function accept(CarePersonInvite $invite, User $user): void
    {
        if (mb_strtolower($user->email) !== $invite->email) {
            throw new RuntimeException('Este convite é para outro e-mail. Entre com a conta do e-mail convidado.');
        }

        DB::transaction(function () use ($invite, $user): void {
            $membro = ProfileMember::query()->findOrFail($invite->member_id);

            if ($membro->user_id !== null && $membro->user_id !== $user->id) {
                throw new RuntimeException('Esta pessoa já tem acesso próprio.');
            }

            $membro->update(['user_id' => $user->id]);

            // Quem agora é a própria pessoa deixa de ser "cuidador" dela: um acesso só, o de titular dos próprios dados.
            ProfileCaregiver::query()->where('profile_id', $invite->profile_id)->where('user_id', $user->id)->update([
                'status' => CaregiverStatus::Revoked->value, 'revoked_at' => now(),
            ]);

            // O consentimento passa a ser dela mesma.
            FinancialProfile::withCareProfiles()->whereKey($invite->profile_id)->update([
                'care_consent_by_user_id' => $user->id,
                'care_consent_at' => now(),
                'care_consent_basis' => self::BASIS_SELF,
                'care_consent_version' => config('cerne.legal.care_consent_version'),
            ]);

            $invite->update(['status' => InviteStatus::Accepted, 'accepted_at' => now()]);
        });
    }

    /** Aceite por quem ainda não tem conta: nasce sem perfil financeiro e sem assinatura, só com o espaço de saúde dela. */
    public function acceptWithNewAccount(CarePersonInvite $invite, string $password): User
    {
        return DB::transaction(function () use ($invite, $password): User {
            $membro = ProfileMember::query()->findOrFail($invite->member_id);

            $user = User::create([
                'name' => $membro->name,
                'email' => $invite->email,
                'password' => $password,
                'role' => UserRole::Client,
                'is_active' => true,
                'terms_accepted_at' => now(),
                'terms_version' => config('cerne.legal.terms_version'),
            ]);

            // O link chegou ao e-mail convidado: é a prova de que o endereço é dela.
            $user->forceFill(['email_verified_at' => now()])->save();

            $this->accept($invite, $user);

            return $user;
        });
    }

    public function revokeConsent(FinancialProfile $profile): void
    {
        $profile->forceFill(['care_consent_at' => null, 'care_consent_basis' => null, 'care_consent_version' => null])->save();
    }

    public function grantConsent(FinancialProfile $profile, User $person): void
    {
        $profile->forceFill([
            'care_consent_by_user_id' => $person->id,
            'care_consent_at' => now(),
            'care_consent_basis' => self::BASIS_SELF,
            'care_consent_version' => config('cerne.legal.care_consent_version'),
        ])->save();
    }

    /** Encerra o espaço: apaga dados e arquivos e avisa o dono, que deixa de pagar por ela. */
    public function endSpace(FinancialProfile $profile, User $person): void
    {
        $dono = $profile->owner;
        $nome = $this->personOf($profile)->name;

        $this->profiles->delete($profile);

        $dono?->notify(new CareSpaceEnded($nome));
    }

    private function personOf(FinancialProfile $profile): ProfileMember
    {
        return ProfileMember::query()->where('profile_id', $profile->id)->orderBy('created_at')->firstOrFail();
    }
}
