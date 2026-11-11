<?php

namespace App\Livewire\Profile;

use App\Enums\InviteStatus;
use App\Models\FinancialProfile;
use App\Models\PartnerInvite;
use App\Models\ProfileCaregiver;
use App\Services\CaregiverService;
use App\Services\PartnerInviteService;
use Illuminate\Support\Collection;
use Livewire\Component;
use RuntimeException;

/**
 * Pedidos que o CONSULTOR montou em nome do dono do perfil (cônjuge com login e acesso de familiar à saúde ou às finanças). Nada vale, e nenhum e-mail
 * sai ao convidado, até o dono aprovar aqui. Só o dono vê e decide; o consultor com o perfil aberto nunca aparece como dono.
 */
class AccessApprovals extends Component
{
    public function approvePartner(string $id, PartnerInviteService $service): void
    {
        // Resolvido fora do try: o abort(403) é uma RuntimeException e não pode virar mensagem de tela.
        $convite = $this->partnerInvite($id);

        try {
            $service->approve($convite, auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('approval', $e->getMessage());

            return;
        }

        session()->flash('status', 'Pedido aprovado. O convite foi enviado por e-mail.');
    }

    public function rejectPartner(string $id, PartnerInviteService $service): void
    {
        $service->reject($this->partnerInvite($id), auth()->user());

        session()->flash('status', 'Pedido recusado. Nada foi enviado.');
    }

    public function approveGrant(string $caregiverId, CaregiverService $service): void
    {
        $acesso = $this->grant($caregiverId);

        try {
            $service->approveAssistedGrant($acesso, auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('approval', $e->getMessage());

            return;
        }

        session()->flash('status', 'Pedido aprovado. O familiar recebeu o convite por e-mail e as pessoas foram avisadas.');
    }

    public function rejectGrant(string $caregiverId, CaregiverService $service): void
    {
        $service->rejectAssistedGrant($this->grant($caregiverId), auth()->user());

        session()->flash('status', 'Pedido recusado. Nada foi enviado.');
    }

    private function partnerInvite(string $id): PartnerInvite
    {
        $convite = PartnerInvite::query()->with('profile')->findOrFail($id);

        abort_unless($convite->profile?->owner_user_id === auth()->id(), 403);

        return $convite;
    }

    private function grant(string $id): ProfileCaregiver
    {
        $acesso = ProfileCaregiver::query()->findOrFail($id);

        abort_unless(FinancialProfile::withCareProfiles()->whereKey($acesso->profile_id)->where('owner_user_id', auth()->id())->exists(), 403);

        return $acesso;
    }

    /** @return Collection<int, PartnerInvite> */
    private function partnerRequests(): Collection
    {
        return PartnerInvite::query()
            ->whereIn('profile_id', FinancialProfile::query()->where('owner_user_id', auth()->id())->select('id'))
            ->where('status', InviteStatus::Pending)->whereNull('owner_approved_at')->where('expires_at', '>', now())
            ->with('invitedBy')->latest()->get();
    }

    public function render()
    {
        return view('livewire.profile.access-approvals', [
            'partnerRequests' => $this->partnerRequests(),
            'grantRequests' => app(CaregiverService::class)->pendingAssistedGrants(auth()->user()),
        ]);
    }
}
