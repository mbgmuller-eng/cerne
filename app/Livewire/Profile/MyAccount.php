<?php

namespace App\Livewire\Profile;

use App\Enums\ConsultantClientStatus;
use App\Enums\InviteStatus;
use App\Livewire\Concerns\RequiresActiveProfile;
use App\Models\ConsultantClient;
use App\Models\PartnerInvite;
use App\Models\ProfileMember;
use App\Services\ClientOnboardingService;
use App\Services\PartnerInviteService;
use App\Support\ProfileContext;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Tela "Minha conta" — meus dados, meu consultor, e o cônjuge (já
 * vinculado, convite pendente, ou o formulário pra convidar).
 *
 * Mostra sempre o perfil ATIVO (ver ProfileContext) — igual a qualquer
 * outra tela do app. Um consultor com cliente aberto vê os dados do
 * cliente, não os próprios (mesmo raciocínio de Fluxo de Caixa,
 * Investimentos etc.).
 */
#[Layout('components.layouts.app')]
class MyAccount extends Component
{
    use RequiresActiveProfile;

    public bool $showInviteForm = false;

    public string $partnerName = '';

    public string $partnerEmail = '';

    public ?string $lastInviteLink = null;

    public bool $showPartnerOnlyForm = false;

    public string $partnerOnlyName = '';

    public bool $notifyEmail = false;

    public bool $notifyPush = false;

    public function mount(): void
    {
        $this->redirectOrAbortWithoutProfile();

        $this->notifyEmail = auth()->user()->notify_email_enabled;
        $this->notifyPush = auth()->user()->notify_push_enabled;
    }

    public function updatedNotifyEmail(bool $value): void
    {
        auth()->user()->update(['notify_email_enabled' => $value]);
    }

    /**
     * Desligar persiste na hora. Ligar NÃO persiste aqui — quem liga de
     * verdade é PushSubscriptionController::store, chamado pelo JS só
     * depois que o navegador confirma a inscrição. Persistir "true" já
     * aqui deixaria a flag mentindo (ligada, mas sem inscrição nenhuma)
     * se a pessoa negar a permissão do navegador.
     */
    public function updatedNotifyPush(bool $value): void
    {
        if (! $value) {
            auth()->user()->update(['notify_push_enabled' => false]);
        }
    }

    public function toggleInviteForm(): void
    {
        $this->showInviteForm = ! $this->showInviteForm;

        if ($this->showInviteForm) {
            $this->reset('partnerName', 'partnerEmail', 'lastInviteLink');
            $this->resetErrorBag();
        }
    }

    public function invitePartner(PartnerInviteService $service): void
    {
        $profile = app(ProfileContext::class)->profile();

        $this->authorize('manageMembers', $profile);

        $data = $this->validate([
            'partnerName' => ['required', 'string', 'max:255'],
            'partnerEmail' => ['required', 'email', 'max:255'],
        ], attributes: [
            'partnerName' => 'nome',
            'partnerEmail' => 'e-mail',
        ]);

        $this->lastInviteLink = $service->send($profile, auth()->user(), $data['partnerName'], $data['partnerEmail']);

        $this->reset('partnerName', 'partnerEmail');
        session()->flash('status', 'Convite enviado.');
    }

    public function togglePartnerOnlyForm(): void
    {
        $this->showPartnerOnlyForm = ! $this->showPartnerOnlyForm;

        if ($this->showPartnerOnlyForm) {
            $this->reset('partnerOnlyName');
            $this->resetErrorBag();
        }
    }

    /**
     * Cadastra o cônjuge sem login nenhum — quem não quer acessar a
     * plataforma ainda pode ter conta bancária, gasto e investimento em
     * nome próprio dentro do casal (ver
     * ClientOnboardingService::addPartnerWithoutLogin()).
     */
    public function addPartnerWithoutLogin(ClientOnboardingService $service): void
    {
        $profile = app(ProfileContext::class)->profile();

        $this->authorize('manageMembers', $profile);

        $data = $this->validate([
            'partnerOnlyName' => ['required', 'string', 'max:255'],
        ], attributes: [
            'partnerOnlyName' => 'nome',
        ]);

        $service->addPartnerWithoutLogin($profile, $data['partnerOnlyName']);

        $this->reset('partnerOnlyName');
        $this->showPartnerOnlyForm = false;
        session()->flash('status', 'Cônjuge cadastrado — sem login, só você (e o consultor) acessam os dados dele(a).');
    }

    public function render()
    {
        $context = app(ProfileContext::class);
        $profile = $context->profile();

        $consultantLink = ConsultantClient::query()
            ->with('consultant')
            ->where('client_id', $profile->owner_user_id)
            ->where('status', ConsultantClientStatus::Active)
            ->first();

        // "Meu cônjuge" é sempre o OUTRO membro, não "o secundário" — pra
        // quem é o secundário, o cônjuge é o titular, não ele mesmo.
        // Compara pelo id do MEMBRO, não do usuário: um cônjuge sem login
        // tem user_id nulo, e em SQL "NULL != X" nunca é verdadeiro — um
        // filtro por user_id excluiria ele sozinho, mesmo sem querer.
        $partnerMember = ProfileMember::query()
            ->where('profile_id', $profile->id)
            ->where('id', '!=', $context->memberId())
            ->with('user')
            ->first();

        $pendingInvite = PartnerInvite::query()
            ->where('profile_id', $profile->id)
            ->where('status', InviteStatus::Pending)
            ->latest()
            ->first();

        return view('livewire.profile.my-account', [
            'profile' => $profile,
            'user' => auth()->user(),
            'consultant' => $consultantLink?->consultant,
            'partner' => $partnerMember,
            'pendingInvite' => $pendingInvite,
            'canInvitePartner' => $partnerMember === null && auth()->user()->can('manageMembers', $profile),
        ]);
    }
}
