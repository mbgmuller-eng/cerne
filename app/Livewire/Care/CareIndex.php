<?php

namespace App\Livewire\Care;

use App\Enums\CaregiverAccess;
use App\Enums\CaregiverStatus;
use App\Models\FinancialProfile;
use App\Models\ProfileCaregiver;
use App\Services\CaregiverService;
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
        $this->reset('inviteName', 'inviteEmail', 'lastInviteLink');
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

    public function toggleNotify(string $caregiverId): void
    {
        $vinculo = $this->myLinkOrFail($caregiverId);
        $vinculo->update(['notify_reminders' => ! $vinculo->notify_reminders]);
    }

    public function render()
    {
        $usuario = auth()->user();
        $servico = app(CareProfileService::class);

        $minhas = $usuario->careProfiles()->with(['caregivers' => fn ($q) => $q->where('status', '!=', CaregiverStatus::Revoked->value)->orderBy('invited_name')])->orderBy('profile_name')->get();
        $vinculos = ProfileCaregiver::query()->active()->where('user_id', $usuario->id)->get()->keyBy('profile_id');
        $acompanho = $usuario->followedProfiles()->with('owner')->orderBy('profile_name')->get();

        return view('livewire.care.care-index', [
            'minhas' => $minhas,
            'acompanho' => $acompanho,
            'vinculos' => $vinculos,
            'podeCadastrar' => $servico->canCreate($usuario),
            'recursoLigado' => CareProfileService::enabled(),
            'temPerfilProprio' => $usuario->ownedProfiles()->exists(),
            'accessOptions' => CaregiverAccess::cases(),
        ]);
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
