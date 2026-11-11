<?php

namespace App\Livewire\Family;

use App\Enums\CaregiverAccess;
use App\Enums\CaregiverGrantBasis;
use App\Enums\CaregiverStatus;
use App\Models\ProfileCaregiver;
use App\Models\ProfileMember;
use App\Services\CaregiverService;
use App\Support\ProfileContext;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;

/**
 * Convite assistido: o consultor vinculado monta o acesso de um familiar (os filhos de dois idosos, por exemplo) em nome das pessoas do perfil do
 * cliente, para quem não consegue fazer isso sozinho. Cada acesso fica registrado com quem montou, quando e com que base; as pessoas com login são
 * avisadas na hora e encerram o acesso quando quiserem. Quem consegue fazer sozinho usa a tela Pessoas e acessos ("Quem me acompanha").
 */
#[Layout('components.layouts.app')]
class FamilyAccessAssist extends Component
{
    public string $name = '';

    public string $email = '';

    /** @var list<string> */
    public array $memberIds = [];

    public bool $health = true;

    public string $access = 'read';

    public bool $finance = true;

    public string $basis = '';

    public bool $declared = false;

    public ?string $lastLink = null;

    public function mount(): void
    {
        $contexto = app(ProfileContext::class);

        abort_unless($contexto->isConsultant() && $contexto->profile() !== null, 403);
        Gate::authorize('manageFamilyAccess', $contexto->profile());

        $this->memberIds = $this->members()->pluck('id')->all();
    }

    public function grant(CaregiverService $service): void
    {
        $perfil = app(ProfileContext::class)->profile();
        Gate::authorize('manageFamilyAccess', $perfil);

        $dados = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'memberIds' => ['required', 'array', 'min:1'],
            'memberIds.*' => ['string'],
            'health' => ['boolean'],
            'finance' => ['boolean'],
            'access' => ['required', 'in:read,edit'],
            'basis' => ['required', 'in:authorized,representative'],
            'declared' => ['accepted'],
        ], [
            'memberIds.required' => 'Escolha pelo menos uma pessoa.',
            'basis.required' => 'Informe com que base você libera este acesso.',
            'declared.accepted' => 'Confirme a declaração para continuar.',
        ], ['name' => 'nome', 'email' => 'e-mail']);

        try {
            $service->assistedGrant(
                $perfil, auth()->user(), $dados['memberIds'], trim($dados['name']), $dados['email'], $this->health, CaregiverAccess::from($dados['access']),
                $this->finance, CaregiverGrantBasis::from($dados['basis']),
            );
        } catch (RuntimeException $e) {
            $this->addError('email', $e->getMessage());

            return;
        }

        $this->reset('name', 'email', 'basis', 'declared');
        session()->flash('status', 'Pedido enviado ao cliente. O familiar só recebe o convite depois que o cliente aprovar em Minha conta.');
    }

    public function revoke(string $caregiverId, CaregiverService $service): void
    {
        $acesso = ProfileCaregiver::query()->where('profile_id', app(ProfileContext::class)->profileId())->findOrFail($caregiverId);

        Gate::authorize('manageGrant', $acesso);

        $service->revoke($acesso);

        session()->flash('status', 'Acesso encerrado.');
    }

    /** @return \Illuminate\Support\Collection<int, ProfileMember> */
    private function members()
    {
        return ProfileMember::query()->where('profile_id', app(ProfileContext::class)->profileId())->where('is_active', true)->orderBy('name')->get();
    }

    public function render()
    {
        $perfil = app(ProfileContext::class)->profile();

        return view('livewire.family.family-access-assist', [
            'perfil' => $perfil,
            'membros' => $this->members(),
            'acessos' => ProfileCaregiver::query()->where('profile_id', $perfil->id)->where('status', '!=', CaregiverStatus::Revoked->value)
                ->with(['member', 'grantedBy'])->orderBy('invited_name')->get(),
            'bases' => [CaregiverGrantBasis::Authorized, CaregiverGrantBasis::Representative],
            'niveis' => CaregiverAccess::cases(),
        ]);
    }
}
