<?php

namespace App\Livewire\Investments;

use App\Enums\AllocationAssetClass;
use App\Enums\AssetClass;
use App\Enums\EmploymentType;
use App\Enums\InvestmentSector;
use App\Enums\InvestorType;
use App\Enums\PortfolioDisplayGroup;
use App\Enums\ReserveType;
use App\Enums\TransactionType;
use App\Livewire\Concerns\HasPrivacyTabs;
use App\Livewire\Concerns\RequiresActiveProfile;
use App\Models\FinancialReserve;
use App\Models\InvestmentPerformance;
use App\Models\InvestmentRecord;
use App\Models\InvestmentSnapshot;
use App\Models\InvestmentTransaction;
use App\Models\InvestorProfile;
use App\Models\ProfileMember;
use App\Models\RecommendedAllocation;
use App\Services\InvestmentTransactionService;
use App\Support\Money;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Tela 5 — Investimentos, em três abas: Portfólio, Performance e
 * Transações (seção 12 da especificação).
 */
#[Layout('components.layouts.app')]
class InvestmentsIndex extends Component
{
    use RequiresActiveProfile;
    use HasPrivacyTabs;

    protected function privacyModels(): array
    {
        return [InvestmentRecord::class];
    }

    #[Url]
    public string $tab = 'portfolio';

    // -----------------------------------------------------------------
    // Evolução do patrimônio (aba Performance) — lente do gráfico
    // -----------------------------------------------------------------

    /** 'total', 'group' ou 'asset' — o que as colunas da Evolução mostram. */
    public string $evolutionLens = 'total';

    /** Ativo escolhido quando $evolutionLens === 'asset'. */
    public string $evolutionAssetId = '';

    /** Grupos que o clique na legenda tirou do gráfico empilhado (lente 'group') — value do PortfolioDisplayGroup. */
    public array $evolutionHiddenGroups = [];

    public function updatedEvolutionLens(): void
    {
        $this->evolutionAssetId = '';
    }

    /** Clique na legenda liga/desliga um grupo no gráfico empilhado — não mexe na escala dos outros. */
    public function toggleEvolutionGroup(string $grupo): void
    {
        $this->evolutionHiddenGroups = in_array($grupo, $this->evolutionHiddenGroups, true)
            ? array_values(array_diff($this->evolutionHiddenGroups, [$grupo]))
            : [...$this->evolutionHiddenGroups, $grupo];
    }

    // -----------------------------------------------------------------
    // Formulário — Perfil do investidor
    // -----------------------------------------------------------------

    public bool $showInvestorProfileForm = false;

    public string $investorProfileMemberId = '';

    public string $investorTypeInput = '';

    public string $employmentTypeInput = '';

    // -----------------------------------------------------------------
    // Formulário — Novo investimento
    // -----------------------------------------------------------------

    public bool $showInvestmentForm = false;

    /** id do investimento em edição, ou nulo quando o formulário é de cadastro novo. */
    public ?string $editingInvestmentId = null;

    public string $investmentName = '';

    public string $investmentTicker = '';

    public string $investmentAssetClass = '';

    /** '', 'paz' ou 'oportunidade' — vazio é "não conta pra nenhuma reserva". */
    public string $investmentReserveType = '';

    public string $investmentInstitution = '';

    public string $investmentMemberId = '';

    public bool $investmentIsPrivate = false;

    public string $investmentCurrentAmount = '';

    /** Só usado ao editar — o dia em que o valor atual informado foi conferido (atualiza a foto do mês). */
    public string $investmentValueDate = '';

    public string $investmentInvestedAmount = '';

    public string $investmentQuantity = '';

    public string $investmentUnitPrice = '';

    public string $investmentPurchaseDate = '';

    public string $investmentReturnRate = '';

    public function mount(): void
    {
        $this->redirectOrAbortWithoutProfile();
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['portfolio', 'performance', 'transactions'], true)
            ? $tab
            : 'portfolio';
    }

    /**
     * SEMPRE os investimentos dos dois — Reservas, Perfil do investidor e
     * os totais no topo da tela têm lógica própria de cruzar dado dos
     * dois membros (ver InvestorProfile) e não devem variar com a aba
     * de privacidade. Só a listagem "Carteira por setor" respeita a aba
     * — ver getSectorInvestmentsProperty().
     *
     * @return Collection<int, InvestmentRecord>
     */
    public function getInvestmentsProperty(): Collection
    {
        return InvestmentRecord::query()
            ->active()
            ->with('member')
            ->orderBy('sector')
            ->orderByDesc('current_amount')
            ->get();
    }

    /**
     * Os mesmos investimentos, mas filtrados pela aba de privacidade
     * (Casal/membro) quando ela estiver visível — é o que a listagem
     * "Carteira por setor" usa. InvestmentRecord sempre tem member_id
     * preenchido (sem conceito de "conjunto" como conta/cartão), então
     * a aba "Casal" aqui legitimamente não lista nada — nenhum
     * investimento pertence aos dois ao mesmo tempo.
     *
     * @return Collection<int, InvestmentRecord>
     */
    public function getSectorInvestmentsProperty(): Collection
    {
        if (! $this->showPrivacyTabs) {
            return $this->investments;
        }

        $membroId = $this->viewAs === '' ? null : $this->viewAs;

        return $this->investments->where('member_id', $membroId)->values();
    }

    /**
     * Carteira agrupada como a tela apresenta — não pelo `sector` bruto
     * (só 5 valores, empilha quase tudo em renda fixa/variável), pelo
     * PortfolioDisplayGroup de cada investimento (ver InvestmentRecord::
     * displayGroup()), na ordem fixa de PortfolioDisplayGroup::cases()
     * — reserva primeiro, depois as classes com meta recomendada,
     * previdência e internacional por último. Só entram grupos com pelo
     * menos um investimento.
     *
     * @return Collection<string, Collection<int, InvestmentRecord>>
     */
    public function getByGroupProperty(): Collection
    {
        $agrupado = $this->sectorInvestments->groupBy(fn (InvestmentRecord $i) => $i->displayGroup()->value);

        return collect(PortfolioDisplayGroup::cases())
            ->mapWithKeys(fn (PortfolioDisplayGroup $grupo) => [$grupo->value => $agrupado->get($grupo->value, collect())])
            ->filter(fn (Collection $ativos) => $ativos->isNotEmpty());
    }

    public function getTotalProperty(): string
    {
        return Money::sum($this->investments->pluck('current_amount'));
    }

    public function getTotalInvestedProperty(): string
    {
        return Money::sum($this->investments->pluck('invested_amount'));
    }

    public function getTotalGainProperty(): string
    {
        return bcsub($this->total, $this->totalInvested, 2);
    }

    /**
     * A do casal (member_id nulo — visível aos dois, ver
     * InvestorProfile::sharedPeaceReserveTarget()) vem primeiro; depois,
     * agrupada por membro e, dentro do membro, paz antes de oportunidade
     * — é a ordem em que elas fazem sentido conceitualmente (primeiro a
     * base, depois o excedente), e no grid de 2 colunas deixa as duas
     * reservas do mesmo membro lado a lado.
     */
    public function getReservesProperty(): Collection
    {
        return FinancialReserve::query()
            ->with('member')
            ->get()
            ->sortBy([
                fn (FinancialReserve $a, FinancialReserve $b) => ($a->member->name ?? '') <=> ($b->member->name ?? ''),
                fn (FinancialReserve $a, FinancialReserve $b) => ($a->reserve_type === ReserveType::Paz ? 0 : 1)
                    <=> ($b->reserve_type === ReserveType::Paz ? 0 : 1),
            ])
            ->values();
    }

    /**
     * Histórico mensal (mais antigo primeiro) dos ativos de previdência —
     * é o que desenha o gráfico do "card de contrato". Só busca pra
     * quem tem `sector = retirement`; os demais setores não usam gráfico.
     *
     * @return array<string, list<float>> investment_id => valores cronológicos
     */
    public function getSnapshotHistoryProperty(): array
    {
        $idsAposentadoria = $this->investments
            ->where('sector', InvestmentSector::Retirement)
            ->pluck('id');

        if ($idsAposentadoria->isEmpty()) {
            return [];
        }

        return InvestmentSnapshot::query()
            ->whereIn('investment_id', $idsAposentadoria)
            ->orderBy('year')->orderBy('month')
            ->get(['investment_id', 'amount'])
            ->groupBy('investment_id')
            ->map(fn (Collection $grupo) => $grupo->pluck('amount')->map(fn ($v) => (float) $v)->all())
            ->all();
    }

    /**
     * Perfil de investidor por membro: tipo, tipo de atuação, reserva de
     * paz sugerida x atual, e a alocação real da carteira (por
     * AllocationAssetClass) comparada à recomendada pelo consultor. Todo
     * membro ativo aparece — quem ainda não tem perfil cadastrado entra
     * com `perfil: null`, pra a tela oferecer o cadastro em vez de
     * simplesmente sumir da lista. Só entram na comparação de alocação os
     * investimentos que mapeiam para uma classe de alocação (ver
     * AssetClass::allocationClass()) E que não estão marcados como
     * reserva (reserve_type) — um CDB que é a reserva de paz de alguém
     * não conta como "renda fixa alocável", mesmo sendo um CDB de
     * verdade.
     *
     * @return Collection<int, array{
     *     membro: ProfileMember,
     *     perfil: ?InvestorProfile,
     *     reservaSugerida: string,
     *     reservaAtual: string,
     *     totalAlocavel: string,
     *     categorias: Collection<int, array{classe: AllocationAssetClass, valor: string, atualPct: float, recomendadoPct: float, investimentos: Collection<int, InvestmentRecord>}>,
     * }>
     */
    public function getInvestorAllocationsProperty(): Collection
    {
        $profileId = app(ProfileContext::class)->profileId();

        $membros = ProfileMember::query()
            ->where('profile_id', $profileId)
            ->where('is_active', true)
            ->with(['investorProfile.allocations'])
            ->orderBy('name')
            ->get();

        $investimentosPorMembro = $this->investments->groupBy('member_id');
        $reservasPorMembro = $this->reserves->groupBy('member_id');

        return $membros->map(function (ProfileMember $membro) use ($investimentosPorMembro, $reservasPorMembro) {
            $perfil = $membro->investorProfile;

            $categorias = collect();
            $totalAlocavel = '0.00';

            if ($perfil !== null) {
                $alocaveis = $investimentosPorMembro->get($membro->id, collect())
                    ->filter(fn (InvestmentRecord $i) => $i->reserve_type === null && $i->asset_class->allocationClass() !== null);
                $totalAlocavel = Money::sum($alocaveis->pluck('current_amount'));
                $porCategoria = $alocaveis->groupBy(fn (InvestmentRecord $i) => $i->asset_class->allocationClass()->value);

                $categorias = collect(AllocationAssetClass::cases())
                    ->map(function (AllocationAssetClass $classe) use ($porCategoria, $totalAlocavel, $perfil) {
                        $investimentosCategoria = $porCategoria->get($classe->value, collect())->values();
                        $valor = Money::sum($investimentosCategoria->pluck('current_amount'));

                        return [
                            'classe' => $classe,
                            'valor' => $valor,
                            'atualPct' => Money::percentageOf($valor, $totalAlocavel),
                            'recomendadoPct' => (float) ($perfil->allocations->firstWhere('asset_class', $classe)?->target_percentage ?? 0),
                            'investimentos' => $investimentosCategoria,
                        ];
                    })
                    ->filter(fn (array $c) => $c['atualPct'] > 0 || $c['recomendadoPct'] > 0)
                    ->values();
            }

            $reservaPaz = $reservasPorMembro->get($membro->id, collect())
                ->first(fn (FinancialReserve $r) => $r->reserve_type === ReserveType::Paz);

            return [
                'membro' => $membro,
                'perfil' => $perfil,
                'reservaSugerida' => $perfil?->peaceReserveTarget() ?? '0.00',
                'reservaAtual' => $reservaPaz?->effectiveAmount() ?? '0.00',
                'totalAlocavel' => $totalAlocavel,
                'categorias' => $categorias,
            ];
        })->values();
    }

    // -----------------------------------------------------------------
    // Perfil do investidor — cadastrar / editar
    // -----------------------------------------------------------------

    public function toggleInvestorProfileForm(string $memberId): void
    {
        if ($this->showInvestorProfileForm && $this->investorProfileMemberId === $memberId) {
            $this->showInvestorProfileForm = false;
            $this->resetInvestorProfileForm();

            return;
        }

        $membro = ProfileMember::query()
            ->where('profile_id', app(ProfileContext::class)->profileId())
            ->where('id', $memberId)
            ->firstOrFail();

        $perfil = InvestorProfile::query()->where('member_id', $membro->id)->first();

        $this->investorProfileMemberId = $membro->id;
        $this->investorTypeInput = $perfil?->investor_type?->value ?? '';
        $this->employmentTypeInput = $perfil?->employment_type?->value ?? '';
        $this->showInvestorProfileForm = true;
        $this->resetErrorBag();
    }

    /**
     * Cria ou atualiza o perfil, sincroniza a carteira recomendada com a
     * regra padrão do tipo de investidor (InvestorType::
     * recommendedAllocations() — mesma regra pra todo cliente, sem ajuste
     * manual por enquanto) e garante as duas reservas do membro (paz e
     * oportunidade) — todo membro com perfil de investidor tem as duas,
     * mesmo que ainda com saldo zero. `updateOrCreate`/`firstOrCreate` são
     * seguros de repetir: o índice único evita duplicata, e trocar o tipo
     * de investidor depois atualiza a carteira recomendada pro novo tipo.
     *
     * Se o casal tem gasto essencial oculto entre si E os dois já são
     * provedores (cada um com tipo de atuação definido), garante também
     * a reserva de paz/oportunidade DO CASAL (member_id nulo) — ela só
     * faz sentido quando existe uma fatia genuinamente compartilhada pra
     * calcular (ver InvestorProfile::sharedPeaceReserveTarget()).
     */
    public function saveInvestorProfile(): void
    {
        $data = $this->validate([
            'investorProfileMemberId' => ['required'],
            'investorTypeInput' => ['required', Rule::enum(InvestorType::class)],
            'employmentTypeInput' => ['required', Rule::enum(EmploymentType::class)],
        ], attributes: [
            'investorTypeInput' => 'perfil de investidor',
            'employmentTypeInput' => 'tipo de atuação',
        ]);

        $membro = ProfileMember::query()
            ->where('profile_id', app(ProfileContext::class)->profileId())
            ->where('id', $data['investorProfileMemberId'])
            ->firstOrFail();

        $perfil = InvestorProfile::query()->updateOrCreate(
            ['member_id' => $membro->id],
            [
                'investor_type' => $data['investorTypeInput'],
                'employment_type' => $data['employmentTypeInput'],
            ],
        );

        foreach ($perfil->investor_type->recommendedAllocations() as $classe => $percentual) {
            RecommendedAllocation::query()->updateOrCreate(
                ['investor_profile_id' => $perfil->id, 'asset_class' => $classe],
                ['target_percentage' => $percentual],
            );
        }

        foreach (ReserveType::cases() as $tipo) {
            FinancialReserve::query()->firstOrCreate(
                ['member_id' => $membro->id, 'reserve_type' => $tipo],
                ['target_amount' => '0.00', 'current_amount' => '0.00'],
            );
        }

        if (bccomp($perfil->sharedPeaceReserveTarget(), '0.00', 2) > 0) {
            foreach (ReserveType::cases() as $tipo) {
                FinancialReserve::query()->firstOrCreate(
                    ['member_id' => null, 'reserve_type' => $tipo],
                    ['target_amount' => '0.00', 'current_amount' => '0.00'],
                );
            }
        }

        session()->flash('status', 'Perfil de investidor salvo.');
        $this->showInvestorProfileForm = false;
        $this->resetInvestorProfileForm();
    }

    private function resetInvestorProfileForm(): void
    {
        $this->reset('investorProfileMemberId', 'investorTypeInput', 'employmentTypeInput');
        $this->resetErrorBag();
    }

    // -----------------------------------------------------------------
    // Novo investimento
    // -----------------------------------------------------------------

    public function toggleInvestmentForm(): void
    {
        $this->showInvestmentForm = ! $this->showInvestmentForm;

        if ($this->showInvestmentForm) {
            $this->resetInvestmentForm();
        }
    }

    /**
     * Carrega um investimento já cadastrado pro formulário — mesmo
     * formulário do cadastro novo, só que salvar atualiza em vez de
     * criar (ver saveInvestment()). Quantidade/preço médio nunca entram
     * aqui: pertencem à transação de compra que originou o ativo, editar
     * não pode corrigir isso por fora dela (senão o preço médio
     * dessincroniza da transação que devia explicá-lo).
     */
    public function editInvestment(string $investmentId): void
    {
        $investimento = InvestmentRecord::findOrFail($investmentId);

        $this->editingInvestmentId = $investimento->id;
        $this->investmentName = $investimento->name;
        $this->investmentTicker = (string) $investimento->ticker;
        $this->investmentAssetClass = $investimento->asset_class->value;
        $this->investmentReserveType = $investimento->reserve_type?->value ?? '';
        $this->investmentInstitution = (string) $investimento->institution;
        $this->investmentMemberId = $investimento->member_id;
        $this->investmentIsPrivate = $investimento->is_private;
        $this->investmentCurrentAmount = $investimento->current_amount;
        $this->investmentValueDate = CarbonImmutable::now()->toDateString();
        $this->investmentInvestedAmount = (string) $investimento->invested_amount;
        $this->investmentPurchaseDate = $investimento->purchase_date?->toDateString() ?? '';
        $this->investmentReturnRate = (string) $investimento->return_rate;
        $this->showInvestmentForm = true;
        $this->resetErrorBag();
    }

    /**
     * Ativo com cota (ação, FII, ETF, cripto...) nasce de uma
     * transação de compra de verdade, passando pelo mesmo
     * InvestmentTransactionService que recalcula preço médio — não é
     * digitar `invested_amount`/`average_price` à mão (mesmo raciocínio
     * do InvestmentsDemoSeeder). Ativo sem cota (CDB, Tesouro,
     * Previdência...) não tem preço médio pra calcular: entra direto
     * com o valor atual e o investido informados.
     *
     * Editar nunca passa pelo caminho de cotas, mesmo pra um ativo que
     * nasceu com elas — o formulário de edição não mexe em
     * quantidade/preço médio (ver editInvestment()), só corrige os
     * outros campos e o valor atual de mercado.
     */
    public function saveInvestment(InvestmentTransactionService $service): void
    {
        $editando = $this->editingInvestmentId !== null;
        $comCotas = ! $editando && $this->investmentAssetClass !== '' && (AssetClass::tryFrom($this->investmentAssetClass)?->hasQuantity() ?? false);

        $data = $this->validate([
            'investmentName' => ['required', 'string', 'max:255'],
            'investmentAssetClass' => ['required', Rule::enum(AssetClass::class)],
            // '' é "não conta pra reserva nenhuma" — Rule::in já cobre os
            // dois casos sozinho; 'required' rejeitaria '' (não é isso
            // que "obrigatório" devia significar aqui), e 'nullable' não
            // trata '' como vazio (só null), então nenhum dos dois serve.
            'investmentReserveType' => [Rule::in(['', ...array_column(ReserveType::cases(), 'value')])],
            'investmentMemberId' => ['required'],
            'investmentTicker' => ['nullable', 'string', 'max:20'],
            'investmentInstitution' => ['nullable', 'string', 'max:255'],
            'investmentQuantity' => [$comCotas ? 'required' : 'nullable', 'numeric', 'gt:0'],
            'investmentUnitPrice' => [$comCotas ? 'required' : 'nullable', 'numeric', 'gt:0'],
            'investmentCurrentAmount' => [$comCotas ? 'nullable' : 'required', 'numeric', 'gte:0'],
            'investmentValueDate' => [$editando ? 'required' : 'nullable', 'date', 'before_or_equal:today'],
            'investmentInvestedAmount' => ['nullable', 'numeric', 'gte:0'],
            'investmentPurchaseDate' => ['nullable', 'date'],
            'investmentReturnRate' => ['nullable', 'string', 'max:50'],
        ], attributes: [
            'investmentName' => 'nome',
            'investmentAssetClass' => 'classe do ativo',
            'investmentMemberId' => 'membro',
            'investmentQuantity' => 'quantidade',
            'investmentUnitPrice' => 'preço unitário',
            'investmentCurrentAmount' => 'valor atual',
            'investmentValueDate' => 'data deste valor',
        ]);

        $membroId = $this->resolveMembro($this->investmentMemberId);
        $classe = AssetClass::from($data['investmentAssetClass']);
        $dataCompra = $data['investmentPurchaseDate'] !== null && $data['investmentPurchaseDate'] !== ''
            ? CarbonImmutable::parse($data['investmentPurchaseDate'])
            : null;

        $base = [
            'member_id' => $membroId,
            'sector' => $classe->sector(),
            'asset_class' => $classe,
            'reserve_type' => $data['investmentReserveType'] !== '' ? $data['investmentReserveType'] : null,
            'ticker' => $data['investmentTicker'] !== '' ? $data['investmentTicker'] : null,
            'name' => $data['investmentName'],
            'institution' => $data['investmentInstitution'] !== '' ? $data['investmentInstitution'] : null,
            'purchase_date' => $dataCompra,
            'return_rate' => $data['investmentReturnRate'] !== '' ? $data['investmentReturnRate'] : null,
            'is_private' => $this->investmentIsPrivate,
        ];

        if ($editando) {
            $valorAtual = Money::parse($data['investmentCurrentAmount']);

            InvestmentRecord::findOrFail($this->editingInvestmentId)->update($base + [
                'current_amount' => $valorAtual,
                'invested_amount' => $data['investmentInvestedAmount'] !== null && $data['investmentInvestedAmount'] !== ''
                    ? Money::parse($data['investmentInvestedAmount'])
                    : $valorAtual,
            ]);

            // Mesma "foto mensal" que InvestmentSnapshotService::captureMonth()
            // grava sozinho todo dia 1 — atualizar o valor à mão precisa
            // manter o histórico coerente com o que a tela de Evolução do
            // patrimônio mostra, senão a curva só refletiria a mudança no
            // próximo mês. Uma foto por mês: editar de novo no mesmo mês
            // corrige a mesma foto, não cria outra.
            $dataValor = CarbonImmutable::parse($data['investmentValueDate']);
            InvestmentSnapshot::updateOrCreate(
                ['investment_id' => $this->editingInvestmentId, 'year' => $dataValor->year, 'month' => $dataValor->month],
                ['amount' => $valorAtual],
            );
        } elseif ($comCotas) {
            $custoTotal = bcmul((string) $data['investmentQuantity'], (string) $data['investmentUnitPrice'], 2);
            $valorAtual = $data['investmentCurrentAmount'] !== null && $data['investmentCurrentAmount'] !== ''
                ? Money::parse($data['investmentCurrentAmount'])
                : $custoTotal;

            $investimento = InvestmentRecord::create($base + ['current_amount' => $valorAtual, 'created_by_user_id' => auth()->id()]);

            $service->record($investimento, [
                'type' => TransactionType::Buy,
                'quantity' => (string) $data['investmentQuantity'],
                'unit_price' => (string) $data['investmentUnitPrice'],
                'total_amount' => $custoTotal,
                'operation_date' => $dataCompra ?? CarbonImmutable::now(),
            ], auth()->id());
        } else {
            InvestmentRecord::create($base + [
                'current_amount' => Money::parse($data['investmentCurrentAmount']),
                'invested_amount' => $data['investmentInvestedAmount'] !== null && $data['investmentInvestedAmount'] !== ''
                    ? Money::parse($data['investmentInvestedAmount'])
                    : Money::parse($data['investmentCurrentAmount']),
                'created_by_user_id' => auth()->id(),
            ]);
        }

        session()->flash('status', $editando ? 'Investimento atualizado.' : 'Investimento cadastrado.');
        $this->showInvestmentForm = false;
        $this->resetInvestmentForm();
    }

    private function resetInvestmentForm(): void
    {
        $this->reset(
            'editingInvestmentId', 'investmentName', 'investmentTicker', 'investmentAssetClass', 'investmentReserveType', 'investmentInstitution',
            'investmentMemberId', 'investmentIsPrivate', 'investmentCurrentAmount', 'investmentValueDate', 'investmentInvestedAmount',
            'investmentQuantity', 'investmentUnitPrice', 'investmentPurchaseDate', 'investmentReturnRate',
        );
        $this->resetErrorBag();
    }

    /**
     * ProfileMember não é BelongsToProfile — sem essa checagem manual, um
     * member_id de outro perfil passaria direto (mesmo raciocínio de
     * CashFlowIndex::validarMembro / AccountsIndex::resolveMembro).
     */
    private function resolveMembro(string $memberId): string
    {
        $membro = ProfileMember::query()
            ->where('profile_id', app(ProfileContext::class)->profileId())
            ->where('id', $memberId)
            ->first();

        if ($membro === null) {
            throw ValidationException::withMessages(['investmentMemberId' => 'Selecione um membro.']);
        }

        return $membro->id;
    }

    /**
     * Base compartilhada pelo resumo (Total) e pelo gráfico de colunas
     * (Total/Grupo/Ativo) da Evolução do patrimônio: o intervalo de
     * meses comum a toda a carteira filtrada (do primeiro ao último mês
     * com QUALQUER foto — foto = InvestmentSnapshot) e, dentro dele, o
     * valor de CADA ativo mês a mês.
     *
     * O carry-forward é por ATIVO, não pela soma do mês — um mês em que
     * só parte da carteira tirou foto não pode fazer o total do mês
     * cair (ver teste de regressão: um CDB parado num mês, ao lado de um
     * Tesouro que fotografou todo mês, não pode "sumir" da soma). Sem
     * pelo menos 2 meses de foto no total não há curva pra desenhar
     * (cliente novo, sem histórico importado nem um mês fechado ainda)
     * — nesse caso, null.
     *
     * @return ?array{meses: list<CarbonImmutable>, porAtivo: array<string, list<float>>}
     */
    private function evolutionSeries(): ?array
    {
        $ids = $this->sectorInvestments->pluck('id');

        if ($ids->isEmpty()) {
            return null;
        }

        $porAtivoBruto = InvestmentSnapshot::query()
            ->whereIn('investment_id', $ids)
            ->orderBy('year')->orderBy('month')
            ->get(['investment_id', 'year', 'month', 'amount'])
            ->groupBy('investment_id');

        if ($porAtivoBruto->sum(fn (Collection $linhas) => $linhas->count()) < 2) {
            return null;
        }

        $primeiroMes = null;
        $ultimoMes = null;
        $seriesBrutas = [];

        foreach ($porAtivoBruto as $investmentId => $linhas) {
            $seriesBrutas[$investmentId] = $linhas->mapWithKeys(fn ($l) => [$l->year.'-'.$l->month => (float) $l->amount]);

            $primeira = CarbonImmutable::create($linhas->first()->year, $linhas->first()->month, 1);
            $ultima = CarbonImmutable::create($linhas->last()->year, $linhas->last()->month, 1);
            $primeiroMes = $primeiroMes === null ? $primeira : $primeiroMes->min($primeira);
            $ultimoMes = $ultimoMes === null ? $ultima : $ultimoMes->max($ultima);
        }

        if ($primeiroMes->equalTo($ultimoMes)) {
            return null; // um único mês de história no total — sem curva.
        }

        $meses = [];
        for ($mes = $primeiroMes; $mes->lte($ultimoMes); $mes = $mes->addMonth()) {
            $meses[] = $mes;
        }

        $porAtivo = [];
        foreach ($seriesBrutas as $investmentId => $porMes) {
            $pontos = [];
            $anterior = 0.0;
            foreach ($meses as $mes) {
                $anterior = $porMes[$mes->year.'-'.$mes->month] ?? $anterior;
                $pontos[] = $anterior;
            }
            $porAtivo[$investmentId] = $pontos;
        }

        return ['meses' => $meses, 'porAtivo' => $porAtivo];
    }

    /** Soma, índice a índice, as séries mensais dos ativos informados. */
    private function somarSeries(array $porAtivo, array $ids): array
    {
        $n = $porAtivo === [] ? 0 : count(reset($porAtivo));
        $totais = array_fill(0, $n, 0.0);

        foreach ($ids as $id) {
            foreach ($porAtivo[$id] ?? [] as $i => $valor) {
                $totais[$i] += $valor;
            }
        }

        return $totais;
    }

    /**
     * Resumo da Evolução do patrimônio (card de cima, sempre o TOTAL da
     * carteira filtrada, independente da lente escolhida pro gráfico
     * abaixo) — desde quando há histórico, valor atual e crescimento em
     * R$ e % desde o primeiro mês.
     *
     * @return ?array{desde: string, valorAtual: string, crescimentoValor: string, crescimentoPct: ?float}
     */
    public function getPortfolioEvolutionProperty(): ?array
    {
        $series = $this->evolutionSeries();

        if ($series === null) {
            return null;
        }

        $total = $this->somarSeries($series['porAtivo'], array_keys($series['porAtivo']));
        $primeiroValor = $total[0];
        $ultimoValor = end($total);
        $crescimentoValor = $ultimoValor - $primeiroValor;

        return [
            'desde' => $series['meses'][0]->translatedFormat('M/Y'),
            'valorAtual' => Money::parse($ultimoValor),
            'crescimentoValor' => Money::parse($crescimentoValor),
            'crescimentoPct' => $primeiroValor > 0 ? ($crescimentoValor / $primeiroValor) * 100 : null,
        ];
    }

    /**
     * Dado pro gráfico de colunas da Evolução — conforme $evolutionLens:
     * 'total' (uma série só, a carteira inteira), 'group' (uma série
     * empilhada por PortfolioDisplayGroup, cor fixa por grupo) ou
     * 'asset' (uma série só, o ativo escolhido em $evolutionAssetId).
     * `maximo` já vem calculado pra escala do eixo Y bater com o que
     * está sendo mostrado (soma empilhada do mês, não o maior grupo
     * isolado).
     *
     * `porGrupo` sempre traz TODOS os grupos com dado (mesmo os escondidos
     * pela legenda, marcados `visivel: false`) — é o que alimenta os
     * botões de filtro, que precisam continuar clicáveis pra religar um
     * grupo. Só os `visivel: true` entram no empilhado de verdade e na
     * escala do eixo Y (esconder um grupo reaproveita a régua pros que
     * sobraram, não deixa um espaço vazio no topo do gráfico).
     *
     * @return ?array{
     *     meses: list<string>,
     *     total: ?list<float>,
     *     porGrupo: ?list<array{grupo: PortfolioDisplayGroup, cor: string, valores: list<float>, visivel: bool}>,
     *     ativo: ?array{nome: string, valores: list<float>},
     *     ativosDisponiveis: list<array{id: string, nome: string}>,
     *     maximo: float,
     * }
     */
    public function getEvolutionChartProperty(): ?array
    {
        $series = $this->evolutionSeries();

        if ($series === null) {
            return null;
        }

        $porAtivo = $series['porAtivo'];
        $investimentosPorId = $this->sectorInvestments->keyBy('id');

        $ativosDisponiveis = collect($porAtivo)->keys()
            ->map(fn ($id) => $investimentosPorId->get($id))
            ->filter()
            ->sortBy(fn (InvestmentRecord $i) => $i->displayName())
            ->map(fn (InvestmentRecord $i) => ['id' => $i->id, 'nome' => $i->displayName()])
            ->values()->all();

        $resultado = [
            'meses' => collect($series['meses'])->map(fn (CarbonImmutable $m) => $m->translatedFormat('M/y'))->all(),
            'total' => null,
            'porGrupo' => null,
            'ativo' => null,
            'ativosDisponiveis' => $ativosDisponiveis,
        ];

        $seriesParaEscala = [];

        if ($this->evolutionLens === 'group') {
            $idsPorGrupo = collect(array_keys($porAtivo))
                ->groupBy(fn ($id) => $investimentosPorId->get($id)?->displayGroup()->value ?? PortfolioDisplayGroup::Other->value);

            $resultado['porGrupo'] = collect(PortfolioDisplayGroup::cases())
                ->filter(fn (PortfolioDisplayGroup $grupo) => $idsPorGrupo->has($grupo->value))
                ->map(fn (PortfolioDisplayGroup $grupo) => [
                    'grupo' => $grupo,
                    'cor' => $grupo->color(),
                    'valores' => $this->somarSeries($porAtivo, $idsPorGrupo[$grupo->value]->all()),
                    'visivel' => ! in_array($grupo->value, $this->evolutionHiddenGroups, true),
                ])
                ->values()->all();

            $seriesParaEscala = collect($resultado['porGrupo'])
                ->filter(fn (array $g) => $g['visivel'])
                ->pluck('valores')->all();
        } elseif ($this->evolutionLens === 'asset' && isset($porAtivo[$this->evolutionAssetId])) {
            $investimento = $investimentosPorId->get($this->evolutionAssetId);
            $resultado['ativo'] = [
                'nome' => $investimento?->displayName() ?? '',
                'valores' => $porAtivo[$this->evolutionAssetId],
            ];

            $seriesParaEscala = [$resultado['ativo']['valores']];
        } else {
            $resultado['total'] = $this->somarSeries($porAtivo, array_keys($porAtivo));
            $seriesParaEscala = [$resultado['total']];
        }

        $somasPorMes = $this->somarSeries($seriesParaEscala, array_keys($seriesParaEscala));
        $resultado['maximo'] = $somasPorMes === [] ? 1.0 : max(max($somasPorMes), 1.0);

        return $resultado;
    }

    /** @return Collection<int, InvestmentPerformance> */
    public function getPerformanceProperty(): Collection
    {
        $query = InvestmentPerformance::query()
            ->with('investment')
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->limit(24);

        $this->applyPrivacyTabFilter($query);

        return $query->get();
    }

    /** @return Collection<int, InvestmentTransaction> */
    public function getTransactionsProperty(): Collection
    {
        $query = InvestmentTransaction::query()
            ->with('investment', 'member')
            ->orderByDesc('operation_date')
            ->limit(50);

        $this->applyPrivacyTabFilter($query);

        return $query->get();
    }

    /**
     * Casal (não existe — investimento sempre tem dono) ou um membro
     * específico, quando a aba de privacidade está visível.
     */
    private function applyPrivacyTabFilter(Builder $query): void
    {
        if (! $this->showPrivacyTabs) {
            return;
        }

        $query->where('member_id', $this->viewAs === '' ? null : $this->viewAs);
    }

    public function render()
    {
        return view('livewire.investments.investments-index', [
            'members' => ProfileMember::query()
                ->where('profile_id', app(ProfileContext::class)->profileId())
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'showPrivacyTabs' => $this->showPrivacyTabs,
            'privacyMembers' => $this->privacyMembers,
            'byGroup' => $this->byGroup,
            'total' => $this->total,
            'totalInvested' => $this->totalInvested,
            'totalGain' => $this->totalGain,
            'reserves' => $this->reserves,
            'performance' => $this->performance,
            'portfolioEvolution' => $this->portfolioEvolution,
            'evolutionChart' => $this->evolutionChart,
            'transactions' => $this->transactions,
            'snapshotHistory' => $this->snapshotHistory,
            'investorAllocations' => $this->investorAllocations,
        ]);
    }
}
