<?php

namespace App\Livewire\Consultant;

use App\Enums\ConsultantClientStatus;
use App\Enums\InviteStatus;
use App\Models\ConsultantClient;
use App\Models\ConsultantInvite;
use App\Models\FinancialProfile;
use App\Models\User;
use App\Services\ClientInviteService;
use App\Services\ConsultantCapacityService;
use App\Services\ConsultantLinkService;
use App\Services\ConsultantPortfolioService;
use App\Services\PartnerInviteService;
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
    /** 'reais' (tudo convertido) ou 'moedas' (dividido em reais, USDT, BTC e ETH). Lembrado na sessão e valendo também em Investimentos da carteira. */
    public string $exibicao = 'reais';

    public function exibir(string $modo): void
    {
        $this->exibicao = $modo === 'moedas' ? 'moedas' : 'reais';
        session(['carteira_exibicao' => $this->exibicao]);
    }

    public string $inviteName = '';

    public string $inviteEmail = '';

    public ?string $lastInviteLink = null;

    public bool $showInviteForm = false;

    /** profile_id da linha com o formulário de convidar cônjuge aberto, ou nulo. */
    public ?string $invitingPartnerProfileId = null;

    public string $partnerName = '';

    public string $partnerEmail = '';

    public ?string $lastPartnerInviteLink = null;

    /** profile_id do último "Enviar convite de acesso" bem-sucedido — mostra o link ali na hora, mesma linha (session flash não aparece sem navegação de página inteira: o banner mora no layout, fora da árvore que uma chamada Livewire re-renderiza). */
    public ?string $lastAccessInviteProfileId = null;

    public ?string $lastAccessInviteLink = null;


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

    /** Sentido que cada critério tinha antes de a pessoa poder escolher: é o que vale até ela inverter. */
    private const SENTIDO_NATURAL = [
        'atencao' => 'asc', 'nome' => 'asc', 'email' => 'asc', 'status' => 'asc',
        'patrimonio' => 'desc', 'desde' => 'desc',
    ];

    /** @var array<string, array{asc: string, desc: string}> como o sentido se lê em cada critério */
    private const ROTULOS_SENTIDO = [
        'atencao' => ['asc' => 'Mais urgentes primeiro', 'desc' => 'Menos urgentes primeiro'],
        'nome' => ['asc' => 'A a Z', 'desc' => 'Z a A'],
        'email' => ['asc' => 'A a Z', 'desc' => 'Z a A'],
        'status' => ['asc' => 'Ativos primeiro', 'desc' => 'Inativos primeiro'],
        'patrimonio' => ['asc' => 'Do menor ao maior', 'desc' => 'Do maior ao menor'],
        'desde' => ['asc' => 'Do mais antigo ao mais novo', 'desc' => 'Do mais novo ao mais antigo'],
    ];

    /** Padrão de quem nunca escolheu um. */
    private const PADRAO_CLIENTES = ['sort' => 'atencao', 'dir' => 'asc'];

    private const PADRAO_SEM_SEGURO = ['sort' => 'nome', 'dir' => 'asc'];

    // Tudo com #[Url] pra a ordenação/filtro sobreviver a compartilhar o
    // link ou voltar no navegador — não é só estado de sessão. Critério e
    // sentido vazios na URL caem no padrão salvo na conta (ver mount()).
    #[Url]
    public string $ordenarClientes = '';

    #[Url]
    public string $sentidoClientes = '';

    #[Url]
    public string $statusClientes = 'todos';

    #[Url]
    public string $buscaClientes = '';

    #[Url]
    public string $ordenarSemSeguro = '';

    #[Url]
    public string $sentidoSemSeguro = '';

    #[Url]
    public string $buscaSemSeguro = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isConsultant(), 403);

        $this->exibicao = session('carteira_exibicao') === 'moedas' ? 'moedas' : 'reais';

        [$this->ordenarClientes, $this->sentidoClientes] = $this->ordemInicial(
            'clients', $this->ordenarClientes, $this->sentidoClientes, self::ORDENS_CLIENTES, self::PADRAO_CLIENTES,
        );
        [$this->ordenarSemSeguro, $this->sentidoSemSeguro] = $this->ordemInicial(
            'no_life', $this->ordenarSemSeguro, $this->sentidoSemSeguro, self::ORDENS_SEM_SEGURO, self::PADRAO_SEM_SEGURO,
        );
    }

    /** Trocar o critério volta ao sentido que a pessoa guardou para ele, ou ao natural. */
    public function updatedOrdenarClientes(): void
    {
        $this->sentidoClientes = $this->sentidoPadraoDe('clients', $this->ordenarClientes);
    }

    public function updatedOrdenarSemSeguro(): void
    {
        $this->sentidoSemSeguro = $this->sentidoPadraoDe('no_life', $this->ordenarSemSeguro);
    }

    public function salvarPadraoClientes(): void
    {
        $this->salvarPadrao('clients', $this->ordenarClientes, $this->sentidoClientes, self::ORDENS_CLIENTES);
    }

    public function salvarPadraoSemSeguro(): void
    {
        $this->salvarPadrao('no_life', $this->ordenarSemSeguro, $this->sentidoSemSeguro, self::ORDENS_SEM_SEGURO);
    }

    /**
     * @param  array<string, string>  $ordens
     * @param  array{sort: string, dir: string}  $padraoDoSistema
     * @return array{0: string, 1: string}
     */
    private function ordemInicial(string $lista, string $chave, string $sentido, array $ordens, array $padraoDoSistema): array
    {
        if (! isset($ordens[$chave])) {
            $salvo = auth()->user()->portfolio_prefs[$lista] ?? [];
            $chave = isset($ordens[$salvo['sort'] ?? '']) ? $salvo['sort'] : $padraoDoSistema['sort'];
            $sentido = '';
        }

        return [$chave, in_array($sentido, ['asc', 'desc'], true) ? $sentido : $this->sentidoPadraoDe($lista, $chave)];
    }

    private function sentidoPadraoDe(string $lista, string $chave): string
    {
        $salvo = auth()->user()->portfolio_prefs[$lista] ?? [];

        if (($salvo['sort'] ?? null) === $chave && in_array($salvo['dir'] ?? null, ['asc', 'desc'], true)) {
            return $salvo['dir'];
        }

        return self::SENTIDO_NATURAL[$chave] ?? 'asc';
    }

    /** @param  array<string, string>  $ordens */
    private function salvarPadrao(string $lista, string $chave, string $sentido, array $ordens): void
    {
        if (! isset($ordens[$chave]) || ! in_array($sentido, ['asc', 'desc'], true)) {
            return;
        }

        $usuario = auth()->user();
        $prefs = $usuario->portfolio_prefs ?? [];
        $prefs[$lista] = ['sort' => $chave, 'dir' => $sentido];
        $usuario->update(['portfolio_prefs' => $prefs]);
    }

    /**
     * O que está na tela já é o que a conta guarda (ou, sem nada guardado, o padrão do sistema)?
     *
     * @param  array{sort: string, dir: string}  $padraoDoSistema
     */
    private function ehOPadrao(string $lista, string $chave, string $sentido, array $padraoDoSistema): bool
    {
        $guardado = auth()->user()->portfolio_prefs[$lista] ?? $padraoDoSistema;

        return ($guardado['sort'] ?? null) === $chave && ($guardado['dir'] ?? null) === $sentido;
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
    public function invite(ClientInviteService $invites, ConsultantLinkService $links, ConsultantCapacityService $capacity): void
    {
        $this->validate([
            'inviteName' => ['required', 'string', 'max:255'],
            'inviteEmail' => ['required', 'email', 'max:255'],
        ], attributes: [
            'inviteName' => 'nome',
            'inviteEmail' => 'e-mail',
        ]);

        $consultor = auth()->user();

        if (! $capacity->hasRoomForNewClient($consultor)) {
            $this->addError('inviteEmail', 'Você atingiu o limite de clientes do seu plano atual. Aumente o limite na página de assinatura para adicionar mais.');

            return;
        }

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
            $this->addError('inviteEmail', 'Já existe uma autorização pendente para esse e-mail.');

            return;
        }

        $this->lastInviteLink = $links->request($consultor, $existente);
        $this->reset('inviteName', 'inviteEmail');
        session()->flash('status', 'Esse e-mail já tem conta no Cerne — enviamos um pedido de autorização de vínculo.');
    }

    /**
     * Abre/fecha o formulário de convidar cônjuge pra uma linha da tabela
     * — só uma por vez (abrir outra fecha a anterior).
     */
    public function togglePartnerInviteForm(?string $profileId = null): void
    {
        $this->invitingPartnerProfileId = $this->invitingPartnerProfileId === $profileId ? null : $profileId;
        $this->reset('partnerName', 'partnerEmail', 'lastPartnerInviteLink');
        $this->resetErrorBag();
    }

    public function sendPartnerInvite(PartnerInviteService $service): void
    {
        $profile = FinancialProfile::findOrFail($this->invitingPartnerProfileId);

        $this->authorize('manageMembers', $profile);

        $data = $this->validate([
            'partnerName' => ['required', 'string', 'max:255'],
            'partnerEmail' => ['required', 'email', 'max:255'],
        ], attributes: [
            'partnerName' => 'nome',
            'partnerEmail' => 'e-mail',
        ]);

        $this->lastPartnerInviteLink = $service->send($profile, auth()->user(), $data['partnerName'], $data['partnerEmail']);

        $this->reset('partnerName', 'partnerEmail');
        session()->flash('status', 'Convite enviado.');
    }

    /**
     * Reenviar convite de cliente titular ainda não cadastrado — pode ter
     * se perdido no e-mail, ou o prazo expirou. Escopado por
     * consultant_id igual a getPendingInvitesProperty(): sem isso, um
     * convite de outro consultor poderia ser reenviado só adivinhando o id.
     */
    public function reenviarConvite(string $id, ClientInviteService $invites): void
    {
        $convite = ConsultantInvite::query()
            ->where('consultant_id', auth()->id())
            ->where('status', InviteStatus::Pending)
            ->findOrFail($id);

        $this->lastInviteLink = $invites->resend($convite);
        session()->flash('status', 'Convite reenviado.');
    }

    /**
     * Primeiro convite de acesso pra um cliente que já existe na
     * plataforma (perfil, vínculo ativo) mas nunca recebeu nenhum — caso
     * típico de importação em lote, que cria a conta com senha aleatória
     * desconhecida sem avisar ninguém (ver ClientOnboardingService::
     * acceptInvite()). Diferente de reenviarConvite(): ali já existe um
     * ConsultantInvite; aqui não existe nenhum ainda.
     */
    public function enviarConviteDeAcesso(string $profileId, ClientInviteService $invites): void
    {
        $profile = FinancialProfile::with('owner')->findOrFail($profileId);

        // Só o dono do vínculo ativo pode receber — mesma checagem de
        // togglePartnerInviteForm()/sendPartnerInvite() pro mesmo motivo:
        // um id de perfil adulterado não pode disparar convite pra
        // ninguém fora da carteira deste consultor.
        ConsultantClient::query()
            ->where('consultant_id', auth()->id())
            ->where('client_id', $profile->owner_user_id)
            ->where('status', ConsultantClientStatus::Active)
            ->firstOrFail();

        $this->lastAccessInviteProfileId = $profileId;
        $this->lastAccessInviteLink = $invites->send(auth()->user(), $profile->owner->name, $profile->owner->email);

        session()->flash('status', "Convite de acesso enviado para {$profile->owner->email}.");
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
            'rotulosSentido' => self::ROTULOS_SENTIDO,
            'clientesEhPadrao' => $this->ehOPadrao('clients', $this->ordenarClientes, $this->sentidoClientes, self::PADRAO_CLIENTES),
            'semSeguroEhPadrao' => $this->ehOPadrao('no_life', $this->ordenarSemSeguro, $this->sentidoSemSeguro, self::PADRAO_SEM_SEGURO),
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

        return $this->ordenar($lista, $this->ordenarClientes, $this->sentidoClientes)->all();
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

        return $this->ordenar($colecao, $this->ordenarSemSeguro, $this->sentidoSemSeguro)->all();
    }

    /**
     * Quem não tem o dado do critério (pendente sem patrimônio, vínculo sem data) fica sempre no fim, nos dois
     * sentidos: no ascendente ele subiria ao topo e empurraria os clientes de verdade para baixo.
     *
     * @param  \Illuminate\Support\Collection<int, array>  $lista
     * @return \Illuminate\Support\Collection<int, array>
     */
    private function ordenar(\Illuminate\Support\Collection $lista, string $criterio, string $sentido): \Illuminate\Support\Collection
    {
        $valor = fn (array $l) => $this->valorDeOrdem($criterio, $l);
        [$com, $sem] = $lista->partition(fn (array $l) => $valor($l) !== null);

        $com = $sentido === 'desc' ? $com->sortByDesc($valor) : $com->sortBy($valor);

        return $com->concat($sem)->values();
    }

    private function valorDeOrdem(string $criterio, array $l): string|float|int|null
    {
        return match ($criterio) {
            'email' => mb_strtolower($l['email']),
            'patrimonio' => $l['patrimonio'] !== null ? (float) $l['patrimonio'] : null,
            'desde' => $l['since']?->getTimestamp(),
            'status' => match ($l['status']) {
                ConsultantClientStatus::Active => 0,
                ConsultantClientStatus::Pending => 1,
                ConsultantClientStatus::Inactive => 2,
            },
            // Ativo sem seguro de vida > ativo coberto > pendente > inativo.
            'atencao' => match (true) {
                $l['status'] === ConsultantClientStatus::Active && ! $l['seguro_vida'] => 0,
                $l['status'] === ConsultantClientStatus::Active => 1,
                $l['status'] === ConsultantClientStatus::Pending => 2,
                default => 3,
            },
            default => mb_strtolower($l['name']),
        };
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
