<?php

namespace App\Livewire\Investments;

use App\Enums\AllocationAssetClass;
use App\Enums\AssetClass;
use App\Enums\EmploymentType;
use App\Enums\InvestmentSector;
use App\Enums\InvestorType;
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

    protected function privacyDomains(): array
    {
        return ['investment_visibility'];
    }

    #[Url]
    public string $tab = 'portfolio';

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

    public string $investmentName = '';

    public string $investmentTicker = '';

    public string $investmentAssetClass = '';

    public string $investmentInstitution = '';

    public string $investmentMemberId = '';

    public string $investmentCurrentAmount = '';

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

    /** Carteira agrupada por setor, como a tela apresenta. */
    public function getBySectorProperty(): Collection
    {
        return $this->sectorInvestments->groupBy(fn (InvestmentRecord $i) => $i->sector->value);
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
            ->with('member', 'linkedInvestment')
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
     * investimentos que mapeiam para uma classe de alocação — reserva e
     * previdência ficam fora (ver AssetClass::allocationClass()).
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
                    ->filter(fn (InvestmentRecord $i) => $i->asset_class->allocationClass() !== null);
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
     * Cria ou atualiza o perfil e garante as duas reservas do membro
     * (paz e oportunidade) — todo membro com perfil de investidor tem as
     * duas, mesmo que ainda com saldo zero. `firstOrCreate` é seguro pra
     * repetir: o índice único evita duplicata.
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
     * Ativo com cota (ação, FII, ETF, cripto...) nasce de uma
     * transação de compra de verdade, passando pelo mesmo
     * InvestmentTransactionService que recalcula preço médio — não é
     * digitar `invested_amount`/`average_price` à mão (mesmo raciocínio
     * do InvestmentsDemoSeeder). Ativo sem cota (CDB, Tesouro,
     * Previdência...) não tem preço médio pra calcular: entra direto
     * com o valor atual e o investido informados.
     */
    public function saveInvestment(InvestmentTransactionService $service): void
    {
        $comCotas = $this->investmentAssetClass !== '' && (AssetClass::tryFrom($this->investmentAssetClass)?->hasQuantity() ?? false);

        $data = $this->validate([
            'investmentName' => ['required', 'string', 'max:255'],
            'investmentAssetClass' => ['required', Rule::enum(AssetClass::class)],
            'investmentMemberId' => ['required'],
            'investmentTicker' => ['nullable', 'string', 'max:20'],
            'investmentInstitution' => ['nullable', 'string', 'max:255'],
            'investmentQuantity' => [$comCotas ? 'required' : 'nullable', 'numeric', 'gt:0'],
            'investmentUnitPrice' => [$comCotas ? 'required' : 'nullable', 'numeric', 'gt:0'],
            'investmentCurrentAmount' => [$comCotas ? 'nullable' : 'required', 'numeric', 'gte:0'],
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
            'ticker' => $data['investmentTicker'] !== '' ? $data['investmentTicker'] : null,
            'name' => $data['investmentName'],
            'institution' => $data['investmentInstitution'] !== '' ? $data['investmentInstitution'] : null,
            'purchase_date' => $dataCompra,
            'return_rate' => $data['investmentReturnRate'] !== '' ? $data['investmentReturnRate'] : null,
            'created_by_user_id' => auth()->id(),
        ];

        if ($comCotas) {
            $custoTotal = bcmul((string) $data['investmentQuantity'], (string) $data['investmentUnitPrice'], 2);
            $valorAtual = $data['investmentCurrentAmount'] !== null && $data['investmentCurrentAmount'] !== ''
                ? Money::parse($data['investmentCurrentAmount'])
                : $custoTotal;

            $investimento = InvestmentRecord::create($base + ['current_amount' => $valorAtual]);

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
            ]);
        }

        session()->flash('status', 'Investimento cadastrado.');
        $this->showInvestmentForm = false;
        $this->resetInvestmentForm();
    }

    private function resetInvestmentForm(): void
    {
        $this->reset(
            'investmentName', 'investmentTicker', 'investmentAssetClass', 'investmentInstitution',
            'investmentMemberId', 'investmentCurrentAmount', 'investmentInvestedAmount',
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
            'bySector' => $this->bySector,
            'total' => $this->total,
            'totalInvested' => $this->totalInvested,
            'totalGain' => $this->totalGain,
            'reserves' => $this->reserves,
            'performance' => $this->performance,
            'transactions' => $this->transactions,
            'snapshotHistory' => $this->snapshotHistory,
            'investorAllocations' => $this->investorAllocations,
        ]);
    }
}
