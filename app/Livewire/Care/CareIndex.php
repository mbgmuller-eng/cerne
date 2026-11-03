<?php

namespace App\Livewire\Care;

use App\Enums\CaregiverAccess;
use App\Enums\CaregiverStatus;
use App\Models\FinancialProfile;
use App\Models\ProfileCaregiver;
use App\Models\ProfileMember;
use App\Services\CaregiverService;
use App\Services\CarePersonService;
use App\Services\CareProfileService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;

/**
 * "Pessoas que acompanho": as pessoas que o usuário cadastrou (e paga) e as que ele acompanha como cuidador. É a porta de entrada da área,
 * sem perfil ativo, porque quem só cuida (o irmão) pode não ter perfil próprio.
 */
#[Layout('components.layouts.app')]
class CareIndex extends Component
{
    public bool $adding = false;

    public string $newName = '';

    public string $newBirthdate = '';

    public string $newBasis = '';

    public bool $declared = false;

    /** Pessoa cujos cuidadores estão abertos na tela. */
    public ?string $managingId = null;

    public string $inviteName = '';

    public string $inviteEmail = '';

    public string $inviteAccess = 'read';

    public ?string $lastInviteLink = null;

    /** Convite para a própria pessoa usar o app (e-mail dela e o link de reserva). */
    public string $selfEmail = '';

    public ?string $lastSelfLink = null;

    /** Convite para alguém acompanhar a MINHA saúde (não exige assinatura). */
    public string $followName = '';

    public string $followEmail = '';

    public string $followAccess = 'read';

    /** O que o convidado acompanha: a saúde e/ou as finanças (só leitura). */
    public bool $followHealth = true;

    public bool $followFinance = false;

    /** Limite de gasto que avisa a família, por perfil (id do perfil => valor digitado). */
    public array $alertThreshold = [];

    public ?string $lastFollowLink = null;

    public function startAdding(): void
    {
        abort_unless(app(CareProfileService::class)->canCreate(auth()->user()), 403);

        $this->adding = true;
    }

    public function cancelAdding(): void
    {
        $this->reset('adding', 'newName', 'newBirthdate', 'newBasis', 'declared');
        $this->resetErrorBag();
    }

    public function addPerson(CareProfileService $service): void
    {
        abort_unless($service->canCreate(auth()->user()), 403);

        $this->validate([
            'newName' => ['required', 'string', 'max:120'],
            'newBirthdate' => ['nullable', 'date', 'before:today'],
            'newBasis' => ['required', 'in:'.CareProfileService::BASIS_AUTHORIZED.','.CareProfileService::BASIS_LEGAL_REPRESENTATIVE],
            'declared' => ['accepted'],
        ], [
            'newBasis.required' => 'Diga se a pessoa autorizou ou se você é o representante legal.',
            'declared.accepted' => 'Confirme a declaração para cadastrar a pessoa.',
        ], ['newName' => 'nome', 'newBirthdate' => 'data de nascimento']);

        $perfil = $service->create(auth()->user(), trim($this->newName), $this->newBirthdate, $this->newBasis);

        $this->cancelAdding();
        $this->managingId = $perfil->id;
        session()->flash('care_status', 'Pessoa cadastrada. Agora você pode convidar quem vai ajudar a cuidar.');
    }

    public function manage(string $profileId): void
    {
        $this->ownedOrFail($profileId);

        $this->managingId = $this->managingId === $profileId ? null : $profileId;
        $this->reset('inviteName', 'inviteEmail', 'lastInviteLink', 'selfEmail', 'lastSelfLink');
        $this->inviteAccess = 'read';
        $this->resetErrorBag();
    }

    public function invite(CaregiverService $service): void
    {
        $perfil = $this->ownedOrFail((string) $this->managingId);

        $dados = $this->validate([
            'inviteName' => ['required', 'string', 'max:120'],
            'inviteEmail' => ['required', 'email', 'max:190'],
            'inviteAccess' => ['required', 'in:read,edit'],
        ], [], ['inviteName' => 'nome', 'inviteEmail' => 'e-mail']);

        try {
            $this->lastInviteLink = $service->invite($perfil, auth()->user(), trim($dados['inviteName']), $dados['inviteEmail'], CaregiverAccess::from($dados['inviteAccess']));
        } catch (RuntimeException $e) {
            $this->addError('inviteEmail', $e->getMessage());

            return;
        }

        $this->reset('inviteName', 'inviteEmail');
        $this->inviteAccess = 'read';
        session()->flash('care_status', 'Convite enviado por e-mail.');
    }

    /** O dono convida a própria pessoa para ver e controlar o espaço dela. */
    public function inviteSelf(CarePersonService $service): void
    {
        $perfil = $this->ownedOrFail((string) $this->managingId);

        $dados = $this->validate(['selfEmail' => ['required', 'email', 'max:190']], [], ['selfEmail' => 'e-mail']);

        try {
            $this->lastSelfLink = $service->invite($perfil, auth()->user(), $dados['selfEmail']);
        } catch (RuntimeException $e) {
            $this->addError('selfEmail', $e->getMessage());

            return;
        }

        $this->selfEmail = '';
        session()->flash('care_status', 'Convite enviado para a própria pessoa por e-mail.');
    }

    /** Convida alguém para acompanhar a minha saúde. Cada um do casal convida só para a própria. */
    public function inviteFollower(string $memberId, CaregiverService $service): void
    {
        $membro = ProfileMember::query()->where('user_id', auth()->id())->where('is_active', true)->findOrFail($memberId);

        $dados = $this->validate([
            'followName' => ['required', 'string', 'max:120'],
            'followEmail' => ['required', 'email', 'max:190'],
            'followAccess' => ['required', 'in:read,edit'],
            'followHealth' => ['boolean'],
            'followFinance' => ['boolean'],
        ], [], ['followName' => 'nome', 'followEmail' => 'e-mail']);

        try {
            $this->lastFollowLink = $service->inviteFollower($membro, auth()->user(), trim($dados['followName']), $dados['followEmail'], CaregiverAccess::from($dados['followAccess']), $this->followHealth, $this->followFinance);
        } catch (RuntimeException $e) {
            $this->addError('followEmail', $e->getMessage());

            return;
        }

        $this->reset('followName', 'followEmail', 'followFinance');
        $this->followHealth = true;
        $this->followAccess = 'read';
        session()->flash('care_status', 'Convite enviado por e-mail.');
    }

    public function changeAccess(string $caregiverId, string $access, CaregiverService $service): void
    {
        $service->changeAccess($this->caregiverOfMineOrFail($caregiverId), CaregiverAccess::from($access));
    }

    /** Liga ou desliga a saúde no acesso de alguém que me acompanha. Sempre sobra pelo menos uma coisa. */
    public function toggleFollowerHealth(string $caregiverId, CaregiverService $service): void
    {
        $acesso = $this->caregiverOfMineOrFail($caregiverId);
        $this->changeScopeOrError($service, $acesso, ! $acesso->can_view_health, (bool) $acesso->can_view_finance);
    }

    /** Liga ou desliga as finanças (só leitura) no acesso de alguém que me acompanha. */
    public function toggleFollowerFinance(string $caregiverId, CaregiverService $service): void
    {
        $acesso = $this->caregiverOfMineOrFail($caregiverId);
        $this->changeScopeOrError($service, $acesso, (bool) $acesso->can_view_health, ! $acesso->can_view_finance);
    }

    private function changeScopeOrError(CaregiverService $service, ProfileCaregiver $acesso, bool $saude, bool $financas): void
    {
        try {
            $service->changeScope($acesso, $saude, $financas);
        } catch (RuntimeException $e) {
            $this->addError('followEmail', $e->getMessage());
        }
    }

    /** Limite de gasto que avisa quem acompanha as minhas finanças (vazio ou zero desliga). Qualquer pessoa do perfil pode ajustar. */
    public function saveAlertThreshold(string $profileId): void
    {
        $membro = ProfileMember::query()->where('user_id', auth()->id())->where('is_active', true)->where('profile_id', $profileId)->firstOrFail();
        $perfil = FinancialProfile::withCareProfiles()->findOrFail($membro->profile_id);

        $valor = trim((string) ($this->alertThreshold[$profileId] ?? ''));

        $this->validate(["alertThreshold.{$profileId}" => ['nullable', 'numeric', 'min:0', 'max:9999999999999']], [], ["alertThreshold.{$profileId}" => 'limite']);

        $perfil->update(['family_alert_threshold' => $valor === '' || (float) $valor <= 0 ? null : \App\Support\Money::parse($valor)]);

        session()->flash('care_status', $perfil->family_alert_threshold === null ? 'Aviso de gasto alto desligado.' : 'Limite salvo.');
    }

    public function revoke(string $caregiverId, CaregiverService $service): void
    {
        $service->revoke($this->caregiverOfMineOrFail($caregiverId));

        session()->flash('care_status', 'Acesso encerrado.');
    }

    public function deletePerson(string $profileId, CareProfileService $service): void
    {
        $service->delete($this->ownedOrFail($profileId));

        $this->managingId = null;
        session()->flash('care_status', 'Pessoa e todos os dados dela foram apagados.');
    }

    /** Quem cuida deixa de acompanhar por conta própria. */
    public function leave(string $caregiverId, CaregiverService $service): void
    {
        $service->revoke($this->myLinkOrFail($caregiverId));

        session()->flash('care_status', 'Você deixou de acompanhar esta pessoa.');
    }

    // --- A própria pessoa acompanhada (com login) manda no acesso ao espaço dela ---

    /** Ela encerra o acesso de um cuidador. */
    public function revokeAsPerson(string $caregiverId, CaregiverService $service): void
    {
        $vinculo = ProfileCaregiver::query()->findOrFail($caregiverId);
        $this->myOwnSpaceOrFail($vinculo->profile_id);

        $service->revoke($vinculo);

        session()->flash('care_status', 'Acesso encerrado.');
    }

    public function revokeMyConsent(string $profileId, CarePersonService $service): void
    {
        $service->revokeConsent($this->myOwnSpaceOrFail($profileId));

        session()->flash('care_status', 'Consentimento revogado. A saúde deste espaço foi fechada e os avisos pararam. Os dados continuam guardados até você apagar o espaço.');
    }

    public function grantMyConsent(string $profileId, CarePersonService $service): void
    {
        $service->grantConsent($this->myOwnSpaceOrFail($profileId), auth()->user());

        session()->flash('care_status', 'Consentimento dado de novo.');
    }

    /** Direito de eliminação: apaga o espaço inteiro, com dados e documentos, e avisa o dono. */
    public function endMySpace(string $profileId, CarePersonService $service): void
    {
        $service->endSpace($this->myOwnSpaceOrFail($profileId), auth()->user());

        session()->forget(\App\Http\Middleware\SetProfileContext::SESSION_KEY);
        session()->flash('care_status', 'Seu espaço foi apagado.');
    }

    /** Avisos de consultas e exames. */
    public function toggleNotify(string $caregiverId): void
    {
        $vinculo = $this->myLinkOrFail($caregiverId);
        $vinculo->update(['notify_reminders' => ! $vinculo->notify_reminders]);
    }

    /** Avisos de conta que vence ou está atrasada (finanças). */
    public function toggleNotifyFinance(string $caregiverId): void
    {
        $vinculo = $this->myLinkOrFail($caregiverId);
        $vinculo->update(['notify_finance' => ! $vinculo->notify_finance]);
    }

    /** Resumo semanal das finanças. */
    public function toggleNotifyWeekly(string $caregiverId): void
    {
        $vinculo = $this->myLinkOrFail($caregiverId);
        $vinculo->update(['notify_weekly' => ! $vinculo->notify_weekly]);
    }

    /** Avisos de gasto acima do limite combinado. */
    public function toggleNotifyAlerts(string $caregiverId): void
    {
        $vinculo = $this->myLinkOrFail($caregiverId);
        $vinculo->update(['notify_alerts' => ! $vinculo->notify_alerts]);
    }

    /** Avisos de troca de itens de cuidado (filtro do aparelho, palmilha...). */
    public function toggleNotifyItems(string $caregiverId): void
    {
        $vinculo = $this->myLinkOrFail($caregiverId);
        $vinculo->update(['notify_care_items' => ! $vinculo->notify_care_items]);
    }

    public function render()
    {
        $usuario = auth()->user();
        $servico = app(CareProfileService::class);

        $meusEspacos = $usuario->selfCareProfiles()->with(['owner', 'caregivers' => fn ($q) => $q->where('status', CaregiverStatus::Active->value)->orderBy('invited_name')])->get();
        $minhas = $usuario->careProfiles()->with(['members', 'caregivers' => fn ($q) => $q->where('status', '!=', CaregiverStatus::Revoked->value)->orderBy('invited_name')])->orderBy('profile_name')->get();
        // O que EU acompanho: um acesso por pessoa (num casal, o mesmo cuidador pode acompanhar os dois, cada um com o seu nível).
        $acompanho = ProfileCaregiver::query()->active()->where('user_id', $usuario->id)->with(['member', 'profile', 'invitedBy'])->get()->sortBy(fn ($a) => $a->member?->name ?? '')->values();

        // Quem ME acompanha: a minha pessoa em perfil comum (a pessoa acompanhada tem a tela própria, acima).
        $meusMembros = ProfileMember::query()
            ->where('user_id', $usuario->id)->where('is_active', true)
            ->whereIn('profile_id', FinancialProfile::query()->select('id'))
            ->with(['profile', 'followers' => fn ($q) => $q->where('status', '!=', CaregiverStatus::Revoked->value)->orderBy('invited_name')])
            ->get();

        // O limite de gasto que avisa a família parte do que já está salvo no perfil.
        foreach ($meusMembros as $eu) {
            $this->alertThreshold[$eu->profile_id] ??= $eu->profile?->family_alert_threshold !== null ? (string) $eu->profile->family_alert_threshold : '';
        }

        return view('livewire.care.care-index', [
            'minhas' => $minhas,
            'meusEspacos' => $meusEspacos,
            'acompanho' => $acompanho,
            'meusMembros' => $meusMembros,
            'podeCadastrar' => $servico->canCreate($usuario),
            'recursoLigado' => CareProfileService::enabled(),
            'temPerfilProprio' => $usuario->ownedProfiles()->exists(),
            'accessOptions' => CaregiverAccess::cases(),
        ]);
    }

    /** O espaço em que o usuário logado É a pessoa acompanhada (e só ele manda nele). */
    private function myOwnSpaceOrFail(string $profileId): FinancialProfile
    {
        $perfil = auth()->user()->selfCareProfiles()->whereKey($profileId)->firstOrFail();

        Gate::authorize('manageOwnAccess', $perfil);

        return $perfil;
    }

    private function ownedOrFail(string $profileId): FinancialProfile
    {
        $perfil = auth()->user()->careProfiles()->whereKey($profileId)->firstOrFail();

        Gate::authorize('manageCaregivers', $perfil);

        return $perfil;
    }

    /**
     * O acesso de um cuidador, se for de uma pessoa que EU gerencio: as que cadastrei ou eu mesmo. De quem não é meu a busca não acha (404).
     */
    private function caregiverOfMineOrFail(string $caregiverId): ProfileCaregiver
    {
        $usuario = auth()->user();

        $vinculo = ProfileCaregiver::query()
            ->where(fn ($q) => $q->whereIn('profile_id', $usuario->careProfiles()->select('id'))
                ->orWhereIn('member_id', ProfileMember::query()->where('user_id', $usuario->id)->select('id')))
            ->findOrFail($caregiverId);

        Gate::authorize('manageGrant', $vinculo);

        return $vinculo;
    }

    private function myLinkOrFail(string $caregiverId): ProfileCaregiver
    {
        return ProfileCaregiver::query()->active()->where('user_id', auth()->id())->findOrFail($caregiverId);
    }
}
