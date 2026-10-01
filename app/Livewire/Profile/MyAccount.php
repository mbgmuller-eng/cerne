<?php

namespace App\Livewire\Profile;

use App\Enums\InviteStatus;
use App\Enums\MemberRole;
use App\Enums\SubscriptionKind;
use App\Livewire\Concerns\RequiresActiveProfile;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\PartnerInvite;
use App\Models\ProfileMember;
use App\Models\Subscription;
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

    public bool $editingOwnBirthdate = false;

    public string $ownBirthdateInput = '';

    public bool $editingPartnerBirthdate = false;

    public string $partnerBirthdateInput = '';

    /**
     * Profissional não tem FinancialProfile próprio — nunca teria um
     * perfil "ativo" pra RequiresActiveProfile exigir, e cairia sempre
     * redirecionado pra Carteira antes de ver a própria conta. Pula o
     * requisito de perfil pra ele SÓ quando não tem cliente aberto — com
     * cliente aberto, continua vendo os dados do cliente, igual sempre foi
     * (ver isViewingOwnProfessionalAccount()); render() que decide pra
     * qual view manda em cada caso.
     */
    public function mount(): void
    {
        $this->notifyEmail = auth()->user()->notify_email_enabled;
        $this->notifyPush = auth()->user()->notify_push_enabled;

        if ($this->isViewingOwnProfessionalAccount()) {
            return;
        }

        $this->redirectOrAbortWithoutProfile();
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

    /**
     * Aniversário entra em "Datas importantes" (ver ImportantDatesService)
     * — o próprio titular/cônjuge preenche aqui; o consultor/corretor
     * preenche pela tela deles (ImportantDates::saveBirthdate()), não por
     * aqui, porque eles nunca têm um perfil de cliente "aberto" como
     * `member()` — só quem É o membro edita a própria data por esta tela.
     */
    public function toggleOwnBirthdate(): void
    {
        $this->editingOwnBirthdate = ! $this->editingOwnBirthdate;

        if ($this->editingOwnBirthdate) {
            $membro = app(ProfileContext::class)->member();
            $this->ownBirthdateInput = $membro?->birthdate?->toDateString() ?? '';
            $this->resetErrorBag();
        }
    }

    public function saveOwnBirthdate(): void
    {
        $membro = app(ProfileContext::class)->member();
        abort_if($membro === null, 403);

        $data = $this->validate([
            'ownBirthdateInput' => ['required', 'date', 'before:today'],
        ], attributes: ['ownBirthdateInput' => 'data de nascimento']);

        $membro->update(['birthdate' => $data['ownBirthdateInput']]);

        $this->editingOwnBirthdate = false;
        session()->flash('status', 'Aniversário salvo.');
    }

    /** Editar a data do cônjuge é gestão do perfil — mesma policy de convidar/cadastrar cônjuge. */
    public function togglePartnerBirthdate(): void
    {
        $this->editingPartnerBirthdate = ! $this->editingPartnerBirthdate;

        if ($this->editingPartnerBirthdate) {
            $profile = app(ProfileContext::class)->profile();
            $parceiro = $this->resolvePartnerMember($profile);
            $this->partnerBirthdateInput = $parceiro?->birthdate?->toDateString() ?? '';
            $this->resetErrorBag();
        }
    }

    public function savePartnerBirthdate(): void
    {
        $profile = app(ProfileContext::class)->profile();
        $this->authorize('manageMembers', $profile);

        $parceiro = $this->resolvePartnerMember($profile);
        abort_if($parceiro === null, 404);

        $data = $this->validate([
            'partnerBirthdateInput' => ['required', 'date', 'before:today'],
        ], attributes: ['partnerBirthdateInput' => 'data de nascimento']);

        $parceiro->update(['birthdate' => $data['partnerBirthdateInput']]);

        $this->editingPartnerBirthdate = false;
        session()->flash('status', 'Aniversário salvo.');
    }

    public function toggleInviteForm(): void
    {
        $this->showInviteForm = ! $this->showInviteForm;

        if ($this->showInviteForm) {
            $this->reset('partnerName', 'partnerEmail', 'lastInviteLink');
            $this->resetErrorBag();

            // Cônjuge sem login já cadastrado (ver addPartnerWithoutLogin())
            // — pré-preenche o nome que já demos, a pessoa só digita o e-mail.
            $profile = app(ProfileContext::class)->profile();
            $semLogin = ProfileMember::query()
                ->where('profile_id', $profile->id)
                ->where('role', MemberRole::Secondary)
                ->whereNull('user_id')
                ->first();

            if ($semLogin !== null) {
                $this->partnerName = $semLogin->name;
            }
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
        if ($this->isViewingOwnProfessionalAccount()) {
            return $this->renderProfessionalAccount();
        }

        $context = app(ProfileContext::class);
        $profile = $context->profile();

        $partnerMember = $this->resolvePartnerMember($profile);

        $pendingInvite = PartnerInvite::query()
            ->where('profile_id', $profile->id)
            ->where('status', InviteStatus::Pending)
            ->latest()
            ->first();

        // "Meus dados" mostra a pessoa DONA do perfil sendo visto, não
        // quem está logado — pro consultor, isso é o titular do cliente
        // (mesmo raciocínio do resto do app: consultor com cliente aberto
        // vê os dados do cliente, não os próprios). Quando quem está
        // logado É um membro de verdade (titular ou cônjuge vendo a
        // própria conta), member()->user já é ele mesmo, então o
        // comportamento de sempre não muda.
        $donoDosDados = $context->member()?->user ?? $profile->owner;

        // Um cliente pode ter mais de um profissional vinculado (um
        // consultor financeiro E um corretor de seguros, por exemplo) —
        // por isso lista todos, não só o primeiro.
        $profissionaisVinculados = ConsultantClient::query()
            ->with('consultant')
            ->where('client_id', $donoDosDados->id)
            ->active()
            ->get();

        $assinaturasProfissionais = Subscription::query()
            ->ofKind(SubscriptionKind::Professional)
            ->whereIn('user_id', $profissionaisVinculados->pluck('consultant_id'))
            ->get()
            ->keyBy('user_id');

        $minhaAssinatura = Subscription::query()
            ->where('user_id', $donoDosDados->id)
            ->ofKind(SubscriptionKind::Direct)
            ->latest('created_at')
            ->first();

        return view('livewire.profile.my-account', [
            'profile' => $profile,
            'user' => $donoDosDados,
            'minhaAssinatura' => $minhaAssinatura,
            'profissionaisVinculados' => $profissionaisVinculados,
            'assinaturasProfissionais' => $assinaturasProfissionais,
            'partner' => $partnerMember,
            'pendingInvite' => $pendingInvite,
            // Sem cônjuge ainda, OU cônjuge cadastrado sem login (ver
            // addPartnerWithoutLogin()) — os dois casos podem receber
            // convite por e-mail (ver PartnerInviteService::send()).
            'canInvitePartner' => ($partnerMember === null || $partnerMember->user === null)
                && auth()->user()->can('manageMembers', $profile),
            // Nulo quando quem está vendo não é membro de verdade (ex.:
            // consultor com cliente aberto) — só então o card "meus dados"
            // ganha o editor de aniversário, e só então "Gerenciar
            // assinatura" aparece (é a assinatura de quem está vendo, não
            // a do cliente aberto).
            'ownMember' => $context->member(),
            'canManageMembers' => auth()->user()->can('manageMembers', $profile),
        ]);
    }

    /**
     * Só é "a conta do profissional" quando ele não tem cliente nenhum
     * aberto — com cliente aberto, o profissional continua vendo os
     * dados DAQUELE cliente nesta mesma tela, igual sempre foi (ver
     * ProfileContext). Sem isso, um consultor com cliente aberto
     * acabaria sempre vendo a própria conta, nunca a do cliente.
     */
    private function isViewingOwnProfessionalAccount(): bool
    {
        return auth()->user()->isLinkedProfessional() && app(ProfileContext::class)->profile() === null;
    }

    /** Clientes vinculados e ativos, não a carteira inteira — isto é "minha conta", não o painel da carteira. */
    private function renderProfessionalAccount()
    {
        $profissional = auth()->user();

        $minhaAssinatura = Subscription::query()
            ->where('user_id', $profissional->id)
            ->ofKind(SubscriptionKind::Professional)
            ->latest('created_at')
            ->first();

        $clientesVinculados = ConsultantClient::query()
            ->with('client')
            ->where('consultant_id', $profissional->id)
            ->active()
            ->get();

        return view('livewire.profile.professional-account', [
            'profissional' => $profissional,
            'minhaAssinatura' => $minhaAssinatura,
            'clientesVinculados' => $clientesVinculados,
        ]);
    }

    /**
     * "Meu cônjuge" é sempre o OUTRO membro, não "o secundário" — pra quem
     * é o secundário, o cônjuge é o titular, não ele mesmo. Mas isso só
     * faz sentido quando QUEM ESTÁ VENDO é um membro de verdade: um
     * consultor olhando o perfil do cliente não é membro nenhum,
     * memberId() vem nulo, e where('id', '!=', null) do Eloquent vira
     * WHERE id IS NOT NULL (Laravel converte comparação com null pra
     * whereNull/whereNotNull) — ou seja, "qualquer membro", inclusive o
     * titular, o que fazia o consultor ver o próprio cliente listado como
     * cônjuge dele mesmo. Pro consultor, cônjuge só pode significar o
     * membro Secondary mesmo (só existe um por perfil — ver
     * PartnerInviteService::alreadyHasPartner()).
     */
    private function resolvePartnerMember(FinancialProfile $profile): ?ProfileMember
    {
        $context = app(ProfileContext::class);

        return $context->memberId() !== null
            ? ProfileMember::query()
                ->where('profile_id', $profile->id)
                ->where('id', '!=', $context->memberId())
                ->with('user')
                ->first()
            : ProfileMember::query()
                ->where('profile_id', $profile->id)
                ->where('role', MemberRole::Secondary)
                ->with('user')
                ->first();
    }
}
