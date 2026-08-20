<?php

namespace App\Livewire\Consultant;

use App\Models\ConsultantClient;
use App\Models\ConsultantInvite;
use App\Services\ClientInviteService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Tela 1 — Dashboard de clientes (visão do consultor).
 *
 * Lista os vínculos de consultant_clients e permite abrir o perfil de cada
 * cliente. A troca de perfil grava na sessão; o SetProfileContext
 * reautoriza a cada requisição, então a sessão é conveniência e não
 * credencial.
 */
#[Layout('components.layouts.app')]
class ClientDashboard extends Component
{
    public string $inviteName = '';

    public string $inviteEmail = '';

    public ?string $lastInviteLink = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isConsultant(), 403);
    }

    /** @return Collection<int, ConsultantClient> */
    public function getClientsProperty(): Collection
    {
        return ConsultantClient::query()
            ->with('client')
            ->where('consultant_id', auth()->id())
            ->orderBy('status')
            ->get();
    }

    /** @return Collection<int, ConsultantInvite> */
    public function getPendingInvitesProperty(): Collection
    {
        return ConsultantInvite::query()
            ->where('consultant_id', auth()->id())
            ->where('status', \App\Enums\InviteStatus::Pending)
            ->latest()
            ->get()
            ->reject(fn (ConsultantInvite $invite) => $invite->isExpired())
            ->values();
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

        $this->lastInviteLink = $invites->send(auth()->user(), $this->inviteName, $this->inviteEmail);

        $this->reset('inviteName', 'inviteEmail');

        session()->flash('status', 'Convite enviado.');
    }

    public function render()
    {
        return view('livewire.consultant.client-dashboard', [
            'clients' => $this->clients,
            'pendingInvites' => $this->pendingInvites,
        ]);
    }
}
