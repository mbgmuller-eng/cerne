<?php

namespace App\Livewire\Admin;

use App\Enums\InviteStatus;
use App\Models\ConsultantInvite;
use App\Models\FinancialProfile;
use App\Models\User;
use App\Services\ClientInviteService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Painel administrativo: todo usuário e todo perfil da plataforma, e um
 * jeito de criar conta de cliente SEM vínculo de consultor — pro amigo que
 * quer usar o Cerne por conta própria, sem o Marcelo (ou qualquer outro
 * consultor) enxergando os dados dele. Mesmo fluxo de convite/aceite de
 * sempre (ConsultantInvite + /convite/{token}), só que o convite nasce sem
 * consultor — ver ClientInviteService::sendStandalone().
 */
#[Layout('components.layouts.app')]
class AdminUsers extends Component
{
    public string $inviteName = '';

    public string $inviteEmail = '';

    public ?string $lastInviteLink = null;

    public bool $showInviteForm = false;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isPlatformAdmin(), 403);
    }

    public function toggleInviteForm(): void
    {
        $this->showInviteForm = ! $this->showInviteForm;

        if ($this->showInviteForm) {
            $this->reset('inviteName', 'inviteEmail', 'lastInviteLink');
            $this->resetErrorBag();
        }
    }

    public function invite(ClientInviteService $invites): void
    {
        $this->validate([
            'inviteName' => ['required', 'string', 'max:255'],
            'inviteEmail' => ['required', 'email', 'max:255'],
        ], attributes: [
            'inviteName' => 'nome',
            'inviteEmail' => 'e-mail',
        ]);

        if (User::where('email', $this->inviteEmail)->exists()) {
            $this->addError('inviteEmail', 'Esse e-mail já tem conta no Cerne.');

            return;
        }

        $this->lastInviteLink = $invites->sendStandalone($this->inviteName, $this->inviteEmail);
        $this->reset('inviteName', 'inviteEmail');
        session()->flash('status', 'Convite criado — copie o link abaixo e envie pro seu amigo.');
    }

    /** Convites sem consultor ainda aguardando cadastro. */
    public function getPendingInvitesProperty(): Collection
    {
        return ConsultantInvite::query()
            ->whereNull('consultant_id')
            ->where('status', InviteStatus::Pending)
            ->latest()
            ->get()
            ->reject(fn (ConsultantInvite $invite) => $invite->isExpired())
            ->values();
    }

    public function render()
    {
        return view('livewire.admin.admin-users', [
            'users' => User::query()->orderByDesc('created_at')->get(),
            'profiles' => FinancialProfile::query()
                ->with(['owner.consultantLinks' => fn ($q) => $q->active()->with('consultant')])
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }
}
