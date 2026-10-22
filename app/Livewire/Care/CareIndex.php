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

    public function changeAccess(string $caregiverId, string $access, CaregiverService $service): void
    {
        $service->changeAccess($this->caregiverOfMineOrFail($caregiverId), CaregiverAccess::from($access));
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

    public function toggleNotify(string $caregiverId): void
    {
        $vinculo = $this->myLinkOrFail($caregiverId);
        $vinculo->update(['notify_reminders' => ! $vinculo->notify_reminders]);
    }

    public function render()
    {
        $usuario = auth()->user();
        $servico = app(CareProfileService::class);

        $meusEspacos = $usuario->selfCareProfiles()->with(['owner', 'caregivers' => fn ($q) => $q->where('status', CaregiverStatus::Active->value)->orderBy('invited_name')])->get();
        $minhas = $usuario->careProfiles()->with(['members', 'caregivers' => fn ($q) => $q->where('status', '!=', CaregiverStatus::Revoked->value)->orderBy('invited_name')])->orderBy('profile_name')->get();
        $vinculos = ProfileCaregiver::query()->active()->where('user_id', $usuario->id)->get()->keyBy('profile_id');
        $acompanho = $usuario->followedProfiles()->with('owner')->orderBy('profile_name')->get();

        return view('livewire.care.care-index', [
            'minhas' => $minhas,
            'meusEspacos' => $meusEspacos,
            'acompanho' => $acompanho,
            'vinculos' => $vinculos,
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

    private function caregiverOfMineOrFail(string $caregiverId): ProfileCaregiver
    {
        $vinculo = ProfileCaregiver::query()->findOrFail($caregiverId);
        $this->ownedOrFail($vinculo->profile_id);

        return $vinculo;
    }

    private function myLinkOrFail(string $caregiverId): ProfileCaregiver
    {
        return ProfileCaregiver::query()->active()->where('user_id', auth()->id())->findOrFail($caregiverId);
    }
}
