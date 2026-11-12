<?php

namespace App\Services;

use App\Enums\CaregiverAccess;
use App\Enums\CaregiverGrantBasis;
use App\Enums\CaregiverStatus;
use App\Enums\UserRole;
use App\Mail\CaregiverInviteMail;
use App\Models\FinancialProfile;
use App\Notifications\ConsultantAccessRequested;
use App\Models\ProfileCaregiver;
use App\Models\ProfileMember;
use App\Models\User;
use App\Notifications\FamilyAccessGranted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
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

        \App\Support\MailThrottle::guard($owner->id, $email);

        ['token' => $token] = ProfileCaregiver::issue($profile, $membro, $owner, $name, $email, $access);

        $link = route('caregiver-invite.accept', ['token' => $token]);

        Mail::to($email)->queue(new CaregiverInviteMail($owner, $profile->profile_name, $name, $access, $link));

        return $link;
    }

    /**
     * Convida alguém para acompanhar a MINHA saúde e/ou as MINHAS finanças: não exige assinatura, de quem convida nem de quem acompanha. Cada um do
     * casal convida só para o que é seu; quem acompanha não vê o do cônjuge. Finanças são sempre só leitura e só o que não é privado.
     *
     * @return string o link do convite
     */
    public function inviteFollower(ProfileMember $member, User $inviter, string $name, string $email, CaregiverAccess $access, bool $health = true, bool $finance = false): string
    {
        if (! $health && ! $finance) {
            throw new RuntimeException('Escolha o que a pessoa vai acompanhar: a saúde, as finanças ou as duas.');
        }

        // Finanças são só leitura: o nível de edição vale apenas para a saúde.
        $access = $health ? $access : CaregiverAccess::Read;

        $perfil = FinancialProfile::withCareProfiles()->findOrFail($member->profile_id);

        if ($perfil->isCare() || $member->user_id !== $inviter->id) {
            throw new RuntimeException('Você só convida alguém para acompanhar a sua própria saúde e as suas próprias finanças.');
        }

        if (mb_strtolower(trim($email)) === mb_strtolower($inviter->email)) {
            throw new RuntimeException('Você não precisa se convidar.');
        }

        // Quem já divide o perfil comigo (o cônjuge) já enxerga o que eu cadastro de saúde: convidá-lo como cuidador não faria sentido.
        if (ProfileMember::query()->where('profile_id', $member->profile_id)->where('is_active', true)->whereHas('user', fn ($q) => $q->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))]))->exists()) {
            throw new RuntimeException('Essa pessoa já divide o perfil com você e já vê o que você cadastra.');
        }

        \App\Support\MailThrottle::guard($inviter->id, $email);

        ['token' => $token] = ProfileCaregiver::issue($perfil, $member, $inviter, $name, $email, $access, $health, $finance, CaregiverGrantBasis::Self);

        $link = route('caregiver-invite.accept', ['token' => $token]);

        Mail::to($email)->queue(new CaregiverInviteMail($inviter, $member->name, $name, $access, $link, true, $health, $finance));

        return $link;
    }

    /**
     * CONVITE ASSISTIDO: o consultor vinculado monta o acesso de um familiar em nome da pessoa (ou das pessoas) do perfil, para quem não consegue fazer
     * isso sozinho. Fica registrado quem, quando e com que base (a pessoa autorizou, ou o consultor a representa legalmente), cada pessoa com login é
     * avisada na hora e pode encerrar o acesso quando quiser. Um e-mail só vai ao familiar, e um aceite ativa todos os acessos do mesmo lote.
     *
     * O consultor NUNCA recebe o link nem dispara o e-mail: o pedido fica guardado, o dono do perfil é avisado e só depois que ele aprova
     * (`approveAssistedGrant`) o e-mail vai ao familiar. Sem essa trava o consultor liberava saúde e documentos a um e-mail que ele controla.
     *
     * @param  list<string>  $memberIds  as pessoas do perfil cujos dados o familiar passa a acompanhar
     */
    public function assistedGrant(FinancialProfile $profile, User $consultant, array $memberIds, string $name, string $email, bool $health, CaregiverAccess $access, bool $finance, CaregiverGrantBasis $basis): void
    {
        if ($profile->isCare() || ! (new \App\Policies\FinancialProfilePolicy)->manageFamilyAccess($consultant, $profile)) {
            throw new RuntimeException('Só o consultor vinculado ao cliente monta o acesso da família.');
        }

        if ($profile->owner->password_pending) {
            throw new RuntimeException('O cliente ainda não ativou o acesso à conta. Envie o convite de acesso primeiro: é ele quem aprova o acesso da família.');
        }

        if ($basis === CaregiverGrantBasis::Self) {
            throw new RuntimeException('Informe a base do acesso: a pessoa autorizou, ou o representante legal.');
        }

        if (! $health && ! $finance) {
            throw new RuntimeException('Escolha o que o familiar vai acompanhar: a saúde, as finanças ou as duas.');
        }

        $access = $health ? $access : CaregiverAccess::Read;
        $email = mb_strtolower(trim($email));

        $membros = ProfileMember::query()->where('profile_id', $profile->id)->where('is_active', true)->whereIn('id', $memberIds)->with('user')->get();

        if ($membros->isEmpty() || $membros->count() !== count(array_unique($memberIds))) {
            throw new RuntimeException('Escolha pelo menos uma pessoa deste perfil.');
        }

        // Quem já divide o perfil (o cônjuge) já enxerga o que a outra pessoa cadastra: convidá-lo como familiar não faria sentido.
        if (ProfileMember::query()->where('profile_id', $profile->id)->where('is_active', true)->whereHas('user', fn ($q) => $q->whereRaw('lower(email) = ?', [$email]))->exists()) {
            throw new RuntimeException('Essa pessoa já divide o perfil e já vê o que a outra cadastra.');
        }

        DB::transaction(function () use ($profile, $consultant, $membros, $name, $email, $access, $health, $finance, $basis): void {
            foreach ($membros as $membro) {
                // approved = false: o token daqui nunca sai; o aceite só existe depois que o dono aprova (novo token).
                ProfileCaregiver::issue($profile, $membro, $consultant, $name, $email, $access, $health, $finance, $basis, $consultant, false);
            }
        });

        $escopo = collect([$health ? 'a saúde' : null, $finance ? 'as finanças (só para ver)' : null])->filter()->join(' e ');
        $profile->owner->notify(new ConsultantAccessRequested($consultant->name, $name, 'o acesso de familiar a '.$escopo.' de '.$membros->pluck('name')->sort()->join(', ', ' e ')));
    }

    /**
     * Pedidos de acesso de familiar montados pelo consultor que esperam a aprovação do dono, agrupados por lote (mesmo e-mail e mesmo consultor).
     *
     * @return \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, ProfileCaregiver>>
     */
    public function pendingAssistedGrants(User $owner): \Illuminate\Support\Collection
    {
        return ProfileCaregiver::query()
            ->whereIn('profile_id', FinancialProfile::withCareProfiles()->where('owner_user_id', $owner->id)->select('id'))
            ->whereNotNull('granted_by_user_id')
            ->whereNull('owner_approved_at')
            ->where('status', CaregiverStatus::Invited->value)
            ->where('invite_expires_at', '>', now())
            ->with(['member', 'grantedBy'])
            ->get()
            ->groupBy(fn (ProfileCaregiver $c) => $c->profile_id.'|'.$c->invited_email.'|'.$c->granted_by_user_id)
            ->values();
    }

    /** O dono aprova o lote: troca o token de cada linha (o original nunca saiu), e só agora o e-mail vai ao familiar e as pessoas são avisadas. */
    public function approveAssistedGrant(ProfileCaregiver $caregiver, User $owner): string
    {
        $lote = $this->assistedBatch($caregiver, $owner);

        $primeiroToken = null;

        DB::transaction(function () use ($lote, &$primeiroToken): void {
            foreach ($lote as $linha) {
                $token = \Illuminate\Support\Str::random(48);
                $linha->forceFill([
                    'invite_token' => hash('sha256', $token),
                    'invite_expires_at' => now()->addDays((int) config('cerne.invite.expires_in_days')),
                    'owner_approved_at' => now(),
                ])->save();
                $primeiroToken ??= $token;
            }
        });

        $primeira = $lote->first();
        $link = route('caregiver-invite.accept', ['token' => $primeiroToken]);
        $nomes = $lote->map(fn (ProfileCaregiver $l) => $l->member->name)->sort()->join(', ', ' e ');
        $consultor = $primeira->grantedBy;
        $medico = (bool) $primeira->can_view_health;
        $financas = (bool) $primeira->can_view_finance;

        \App\Support\MailThrottle::guard($consultor?->id, $primeira->invited_email);

        Mail::to($primeira->invited_email)->queue(new CaregiverInviteMail($consultor, $nomes, $primeira->invited_name, $primeira->access_level, $link, false, $medico, $financas, true));

        // Cada pessoa com login sabe, na hora, quem passou a acompanhá-la e com que base.
        $escopo = collect([$medico ? 'a saúde' : null, $financas ? 'as finanças (só para ver)' : null])->filter()->join(' e ');
        foreach ($lote->filter(fn (ProfileCaregiver $l) => $l->member?->user !== null) as $linha) {
            Notification::send($linha->member->user, new FamilyAccessGranted($consultor->name, $primeira->invited_name, $escopo, mb_strtolower($primeira->grant_basis->label())));
        }

        return $link;
    }

    public function rejectAssistedGrant(ProfileCaregiver $caregiver, User $owner): void
    {
        foreach ($this->assistedBatch($caregiver, $owner) as $linha) {
            $linha->update(['status' => CaregiverStatus::Revoked, 'revoked_at' => now(), 'invite_token' => null, 'invite_expires_at' => null]);
        }
    }

    /** O lote do pedido (todas as pessoas do mesmo familiar e consultor) e a conferência de que quem decide é o dono do perfil. */
    private function assistedBatch(ProfileCaregiver $caregiver, User $owner): \Illuminate\Support\Collection
    {
        $perfil = FinancialProfile::withCareProfiles()->findOrFail($caregiver->profile_id);

        if ($perfil->owner_user_id !== $owner->id) {
            throw new RuntimeException('Só o dono do perfil aprova este pedido.');
        }

        $lote = ProfileCaregiver::query()
            ->where('profile_id', $caregiver->profile_id)->where('invited_email', $caregiver->invited_email)
            ->where('granted_by_user_id', $caregiver->granted_by_user_id)
            ->whereNull('owner_approved_at')->where('status', CaregiverStatus::Invited->value)
            ->with(['member.user', 'grantedBy'])->get();

        if ($lote->isEmpty() || $caregiver->granted_by_user_id === null) {
            throw new RuntimeException('Este pedido já foi tratado ou expirou. Peça um novo ao consultor.');
        }

        return $lote;
    }

    /** Muda o que o familiar enxerga (saúde, finanças ou as duas). Sempre fica pelo menos uma. */
    public function changeScope(ProfileCaregiver $caregiver, bool $health, bool $finance): void
    {
        if (! $health && ! $finance) {
            throw new RuntimeException('O acesso precisa incluir a saúde, as finanças ou as duas. Para tirar tudo, encerre o acesso.');
        }

        $caregiver->update([
            'can_view_health' => $health,
            'can_view_finance' => $finance,
            // Finanças são só leitura: sem a saúde, o nível de edição não tem o que editar.
            'access_level' => $health ? $caregiver->access_level : CaregiverAccess::Read,
        ]);
    }

    /** Aceite por quem já tem conta: o e-mail da conta precisa ser o do convite. */
    public function accept(ProfileCaregiver $caregiver, User $user): ProfileCaregiver
    {
        if (mb_strtolower($user->email) !== mb_strtolower($caregiver->invited_email)) {
            throw new RuntimeException('Este convite é para outro e-mail. Entre com a conta do e-mail convidado.');
        }

        $ativar = [
            'user_id' => $user->id,
            'status' => CaregiverStatus::Active,
            'accepted_at' => now(),
            'invite_token' => null,
            'invite_expires_at' => null,
        ];

        $caregiver->update($ativar);

        // Convite assistido: o consultor montou o acesso a mais de uma pessoa do mesmo perfil de uma vez, e um aceite só ativa o lote inteiro.
        if ($caregiver->granted_by_user_id !== null) {
            ProfileCaregiver::query()
                ->where('profile_id', $caregiver->profile_id)->where('invited_email', $caregiver->invited_email)
                ->where('granted_by_user_id', $caregiver->granted_by_user_id)
                ->where('status', CaregiverStatus::Invited->value)->where('id', '!=', $caregiver->id)
                ->get()->each(fn (ProfileCaregiver $irmao) => $irmao->update($ativar));
        }

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
