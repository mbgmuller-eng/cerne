<?php

namespace App\Livewire\Consultant;

use App\Enums\ConsultantClientStatus;
use App\Enums\InviteStatus;
use App\Models\ConsultantClient;
use App\Models\ConsultantInvite;
use App\Models\User;
use App\Services\ClientInviteService;
use App\Services\ConsultantLinkService;
use App\Services\ConsultantPortfolioService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Painel da carteira do consultor: panorama agregado de todos os clientes
 * com vínculo ativo — patrimônio total, cobertura de seguros, convite de
 * novo cliente, abrir o perfil de qualquer um deles. Não é o detalhe de
 * um perfil (isso é App\Livewire\Dashboard) — é a tela inicial do
 * consultor, ver Dashboard::mount(). Até pouco tempo a gestão de
 * convites vivia numa tela própria (ClientDashboard, rota /clientes);
 * juntou aqui porque as duas eram sempre a mesma tarefa em telas
 * diferentes — não fazia sentido duas portas pra "cuidar dos clientes".
 */
#[Layout('components.layouts.app')]
class PortfolioOverview extends Component
{
    public string $inviteName = '';

    public string $inviteEmail = '';

    public ?string $lastInviteLink = null;

    public bool $showInviteForm = false;


    /** @var array<string, string> chave usada na URL/dropdown => rótulo, na ordem de exibição */
    private const ORDENS_CLIENTES = [
        'atencao' => 'Precisa de atenção',
        'nome' => 'Nome',
        'email' => 'E-mail',
        'patrimonio' => 'Patrimônio',
        'status' => 'Status',
        'desde' => 'Cliente desde',
    ];

    private const ORDENS_SEM_SEGURO = [
        'nome' => 'Nome',
        'email' => 'E-mail',
        'patrimonio' => 'Patrimônio',
        'desde' => 'Cliente desde',
    ];

    // Tudo com #[Url] pra a ordenação/filtro sobreviver a compartilhar o
    // link ou voltar no navegador — não é só estado de sessão.
    #[Url]
    public string $ordenarClientes = 'atencao';

    #[Url]
    public string $statusClientes = 'todos';

    #[Url]
    public string $buscaClientes = '';

    #[Url]
    public string $ordenarSemSeguro = 'nome';

    #[Url]
    public string $buscaSemSeguro = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isConsultant(), 403);
    }

    public function limparFiltrosClientes(): void
    {
        $this->buscaClientes = '';
        $this->statusClientes = 'todos';
    }

    public function toggleInviteForm(): void
    {
        $this->showInviteForm = ! $this->showInviteForm;

        if ($this->showInviteForm) {
            $this->reset('inviteName', 'inviteEmail', 'lastInviteLink');
            $this->resetErrorBag();
        }
    }

    /**
     * E-mail novo vira convite de cadastro (ClientInviteService); e-mail
     * que já tem conta vira pedido de autorização de vínculo
     * (ConsultantLinkService) — ninguém ganha uma segunda conta só porque
     * um consultor diferente tentou convidar o mesmo endereço.
     */
    public function invite(ClientInviteService $invites, ConsultantLinkService $links): void
    {
        $this->validate([
            'inviteName' => ['required', 'string', 'max:255'],
            'inviteEmail' => ['required', 'email', 'max:255'],
        ], attributes: [
            'inviteName' => 'nome',
            'inviteEmail' => 'e-mail',
        ]);

        $consultor = auth()->user();
        $existente = User::where('email', $this->inviteEmail)->first();

        if ($existente === null) {
            $this->lastInviteLink = $invites->send($consultor, $this->inviteName, $this->inviteEmail);
            $this->reset('inviteName', 'inviteEmail');
            session()->flash('status', 'Convite enviado.');

            return;
        }

        if (! $existente->isClient()) {
            $this->addError('inviteEmail', 'Esse e-mail já pertence a uma conta que não é de cliente.');

            return;
        }

        $vinculo = ConsultantClient::query()
            ->where('consultant_id', $consultor->id)
            ->where('client_id', $existente->id)
            ->first();

        if ($vinculo?->status === ConsultantClientStatus::Active) {
            $this->addError('inviteEmail', 'Esse e-mail já é seu cliente.');

            return;
        }

        if ($vinculo?->status === ConsultantClientStatus::Pending) {
            $this->addError('inviteEmail', 'Já existe uma autorização pendente pra esse e-mail.');

            return;
        }

        $this->lastInviteLink = $links->request($consultor, $existente);
        $this->reset('inviteName', 'inviteEmail');
        session()->flash('status', 'Esse e-mail já tem conta no Cerne — enviamos um pedido de autorização de vínculo.');
    }

    /** @return Collection<int, ConsultantInvite> */
    public function getPendingInvitesProperty(): Collection
    {
        return ConsultantInvite::query()
            ->where('consultant_id', auth()->id())
            ->where('status', InviteStatus::Pending)
            ->latest()
            ->get()
            ->reject(fn (ConsultantInvite $invite) => $invite->isExpired())
            ->values();
    }

    public function render(ConsultantPortfolioService $portfolio)
    {
        $dados = $portfolio->overview(auth()->user());
        $dados['por_cliente'] = $this->filtrarEOrdenarClientes($dados['por_cliente']);
        $dados['sem_seguro_vida'] = $this->filtrarEOrdenarSemSeguro($dados['sem_seguro_vida']);

        return view('livewire.consultant.portfolio-overview', [
            'dados' => $dados,
            'ordensClientes' => self::ORDENS_CLIENTES,
            'ordensSemSeguro' => self::ORDENS_SEM_SEGURO,
        ]);
    }

    /**
     * Filtro (status + busca por nome/e-mail) e ordenação da tabela "por
     * cliente" — é só apresentação, o cálculo já veio pronto do serviço.
     * Padrão "atenção primeiro": ativo sem seguro de vida > ativo coberto
     * > pendente > inativo.
     *
     * @param  list<array>  $porCliente
     * @return list<array>
     */
    private function filtrarEOrdenarClientes(array $porCliente): array
    {
        $lista = collect($porCliente);

        if ($this->statusClientes !== 'todos') {
            $lista = $lista->filter(fn (array $l) => $l['status']->value === $this->statusClientes);
        }

        $lista = $this->filtrarPorBusca($lista, $this->buscaClientes);

        return (match ($this->ordenarClientes) {
            'nome' => $lista->sortBy(fn (array $l) => mb_strtolower($l['name'])),
            'email' => $lista->sortBy(fn (array $l) => mb_strtolower($l['email'])),
            'patrimonio' => $lista->sortByDesc(fn (array $l) => $l['patrimonio'] !== null ? (float) $l['patrimonio'] : -INF),
            'status' => $lista->sortBy(fn (array $l) => match ($l['status']) {
                ConsultantClientStatus::Active => 0,
                ConsultantClientStatus::Pending => 1,
                ConsultantClientStatus::Inactive => 2,
            }),
            'desde' => $lista->sortByDesc(fn (array $l) => $l['since']?->getTimestamp() ?? 0),
            default => $lista->sortBy(fn (array $l) => match (true) {
                $l['status'] === ConsultantClientStatus::Active && ! $l['seguro_vida'] => 0,
                $l['status'] === ConsultantClientStatus::Active => 1,
                $l['status'] === ConsultantClientStatus::Pending => 2,
                default => 3,
            }),
        })->values()->all();
    }

    /**
     * Filtro (busca) e ordenação da lista "sem seguro de vida" — sempre
     * só clientes ativos (por definição do serviço), então não tem filtro
     * de status aqui.
     *
     * @param  list<array>  $lista
     * @return list<array>
     */
    private function filtrarEOrdenarSemSeguro(array $lista): array
    {
        $colecao = $this->filtrarPorBusca(collect($lista), $this->buscaSemSeguro);

        return (match ($this->ordenarSemSeguro) {
            'email' => $colecao->sortBy(fn (array $l) => mb_strtolower($l['email'])),
            'patrimonio' => $colecao->sortByDesc(fn (array $l) => (float) $l['patrimonio']),
            'desde' => $colecao->sortByDesc(fn (array $l) => $l['since']?->getTimestamp() ?? 0),
            default => $colecao->sortBy(fn (array $l) => mb_strtolower($l['name'])),
        })->values()->all();
    }

    /** @param  \Illuminate\Support\Collection<int, array>  $lista */
    private function filtrarPorBusca(\Illuminate\Support\Collection $lista, string $busca): \Illuminate\Support\Collection
    {
        $busca = mb_strtolower(trim($busca));

        if ($busca === '') {
            return $lista;
        }

        return $lista->filter(fn (array $l) => str_contains(mb_strtolower($l['name']), $busca)
            || str_contains(mb_strtolower($l['email']), $busca));
    }
}
