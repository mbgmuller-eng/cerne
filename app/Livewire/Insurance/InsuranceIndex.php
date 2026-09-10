<?php

namespace App\Livewire\Insurance;

use App\Enums\InsuranceType;
use App\Enums\PaymentFrequency;
use App\Livewire\Concerns\HasPrivacyTabs;
use App\Livewire\Concerns\RequiresActiveProfile;
use App\Models\InsurancePolicy;
use App\Models\ProfileMember;
use App\Support\Money;
use App\Support\ProfileContext;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Tela 6 — Seguros, com resumo agregado por categoria de risco.
 *
 * A lista de apólices é agrupada em tipo → (membro, quando há mais de um
 * dono no mesmo tipo, ou sempre em Saúde) → seguradora → apólices — ver
 * getGroupedProperty(). É o mesmo corte usado no Painel da carteira
 * (PortfolioInsurance), só que escopado a um perfil só.
 */
#[Layout('components.layouts.app')]
class InsuranceIndex extends Component
{
    use RequiresActiveProfile;
    use HasPrivacyTabs;

    public bool $showPolicyForm = false;

    public ?string $editingPolicyId = null;

    public string $policyMemberId = '';

    public string $policyInsuredPersonName = '';

    public string $policyInsuranceType = 'vida';

    public string $policyInsurerName = '';

    public string $policyNumber = '';

    public string $policyInsuredItem = '';

    public string $policyCoverageAmount = '';

    public string $policyMonthlyPremium = '0';

    public string $policyAnnualPremium = '';

    public string $policyPaymentFrequency = 'monthly';

    public string $policyStartDate = '';

    public string $policyExpiryDate = '';

    public string $policyNotes = '';

    public bool $policyIsPrivate = false;

    public ?string $confirmingDeletePolicyId = null;

    protected function privacyModels(): array
    {
        return [InsurancePolicy::class];
    }

    public function mount(): void
    {
        $this->redirectOrAbortWithoutProfile();
        $this->policyStartDate = now()->toDateString();
    }

    // -----------------------------------------------------------------
    // Apólice — cadastrar / editar / excluir
    // -----------------------------------------------------------------

    public function togglePolicyForm(): void
    {
        $this->showPolicyForm = ! $this->showPolicyForm;

        if ($this->showPolicyForm) {
            $this->resetPolicyForm();
        }
    }

    public function editPolicy(string $policyId): void
    {
        $apolice = InsurancePolicy::query()->findOrFail($policyId);

        $this->editingPolicyId = $apolice->id;
        $this->policyMemberId = (string) $apolice->member_id;
        $this->policyInsuredPersonName = (string) $apolice->insured_person_name;
        $this->policyInsuranceType = $apolice->insurance_type->value;
        $this->policyInsurerName = $apolice->insurer_name;
        $this->policyNumber = (string) $apolice->policy_number;
        $this->policyInsuredItem = (string) $apolice->insured_item;
        $this->policyCoverageAmount = (string) ($apolice->coverage_amount ?? '');
        $this->policyMonthlyPremium = $apolice->monthly_premium;
        $this->policyAnnualPremium = (string) ($apolice->annual_premium ?? '');
        $this->policyPaymentFrequency = $apolice->payment_frequency->value;
        $this->policyStartDate = $apolice->start_date->toDateString();
        $this->policyExpiryDate = $apolice->expiry_date?->toDateString() ?? '';
        $this->policyNotes = (string) $apolice->notes;
        $this->policyIsPrivate = $apolice->is_private;
        $this->showPolicyForm = true;
    }

    public function savePolicy(): void
    {
        $data = $this->validate([
            'policyMemberId' => ['nullable'],
            'policyInsuredPersonName' => ['nullable', 'string', 'max:255'],
            'policyInsuranceType' => ['required', Rule::enum(InsuranceType::class)],
            'policyInsurerName' => ['required', 'string', 'max:255'],
            'policyNumber' => ['nullable', 'string', 'max:100'],
            'policyInsuredItem' => ['nullable', 'string', 'max:255'],
            'policyCoverageAmount' => ['nullable', 'numeric', 'min:0'],
            'policyMonthlyPremium' => ['required', 'numeric', 'min:0'],
            'policyAnnualPremium' => ['nullable', 'numeric', 'min:0'],
            'policyPaymentFrequency' => ['required', Rule::enum(PaymentFrequency::class)],
            'policyStartDate' => ['required', 'date'],
            'policyExpiryDate' => ['nullable', 'date', 'after_or_equal:policyStartDate'],
            'policyNotes' => ['nullable', 'string'],
        ], attributes: [
            'policyInsuranceType' => 'tipo de seguro',
            'policyInsurerName' => 'seguradora',
            'policyMonthlyPremium' => 'mensalidade',
            'policyPaymentFrequency' => 'forma de pagamento',
            'policyStartDate' => 'início de vigência',
            'policyExpiryDate' => 'vencimento',
        ]);

        $memberId = $this->resolveMembro($this->policyMemberId);

        $payload = [
            'member_id' => $memberId,
            // Só faz sentido quando não há membro cadastrado — com
            // member_id preenchido, o nome livre é descartado de
            // propósito (evita os dois campos divergirem sobre "de quem"
            // é a apólice).
            'insured_person_name' => $memberId === null && $data['policyInsuredPersonName'] !== ''
                ? $data['policyInsuredPersonName']
                : null,
            'insurance_type' => $data['policyInsuranceType'],
            'insurer_name' => $data['policyInsurerName'],
            'policy_number' => $data['policyNumber'] !== '' ? $data['policyNumber'] : null,
            'insured_item' => $data['policyInsuredItem'] !== '' ? $data['policyInsuredItem'] : null,
            'coverage_amount' => $data['policyCoverageAmount'] !== '' ? $data['policyCoverageAmount'] : null,
            'monthly_premium' => $data['policyMonthlyPremium'],
            'annual_premium' => $data['policyAnnualPremium'] !== '' ? $data['policyAnnualPremium'] : null,
            'payment_frequency' => $data['policyPaymentFrequency'],
            'start_date' => $data['policyStartDate'],
            'expiry_date' => $data['policyExpiryDate'] !== '' ? $data['policyExpiryDate'] : null,
            'notes' => $data['policyNotes'] !== '' ? $data['policyNotes'] : null,
            'is_private' => $this->policyIsPrivate,
        ];

        if ($this->editingPolicyId !== null) {
            InsurancePolicy::query()->findOrFail($this->editingPolicyId)->update($payload);
            session()->flash('status', 'Apólice atualizada.');
        } else {
            InsurancePolicy::create($payload + ['is_active' => true, 'created_by_user_id' => auth()->id()]);
            session()->flash('status', 'Apólice cadastrada.');
        }

        $this->resetPolicyForm();
        $this->showPolicyForm = false;
    }

    public function confirmDeletePolicy(string $policyId): void
    {
        $this->confirmingDeletePolicyId = $policyId;
    }

    public function cancelDeletePolicy(): void
    {
        $this->confirmingDeletePolicyId = null;
    }

    public function deletePolicy(string $policyId): void
    {
        InsurancePolicy::query()->findOrFail($policyId)->delete();
        $this->confirmingDeletePolicyId = null;
        session()->flash('status', 'Apólice excluída.');
    }

    private function resetPolicyForm(): void
    {
        $this->reset(
            'editingPolicyId', 'policyMemberId', 'policyInsuredPersonName', 'policyInsurerName', 'policyNumber',
            'policyInsuredItem', 'policyCoverageAmount', 'policyAnnualPremium', 'policyExpiryDate', 'policyNotes',
            'policyIsPrivate',
        );
        $this->policyInsuranceType = 'vida';
        $this->policyMonthlyPremium = '0';
        $this->policyPaymentFrequency = 'monthly';
        $this->policyStartDate = now()->toDateString();
        $this->resetErrorBag();
    }

    /**
     * Diferente de conta/cartão, seguro pode não ter dono — member_id nulo
     * é "seguro familiar" (ver migration). Em branco no formulário devolve
     * null de propósito; um id preenchido precisa pertencer ao perfil
     * ativo (ProfileMember não é BelongsToProfile, a checagem é manual).
     */
    private function resolveMembro(string $memberId): ?string
    {
        if ($memberId === '') {
            return null;
        }

        $membro = ProfileMember::query()
            ->where('profile_id', app(ProfileContext::class)->profileId())
            ->where('id', $memberId)
            ->first();

        if ($membro === null) {
            throw ValidationException::withMessages([
                'policyMemberId' => 'Selecione um membro válido.',
            ]);
        }

        return $membro->id;
    }

    // -----------------------------------------------------------------
    // Leitura
    // -----------------------------------------------------------------

    /** @return Collection<int, InsurancePolicy> */
    public function getPoliciesProperty(): Collection
    {
        $query = InsurancePolicy::query()->active()->with('member')->orderBy('insurance_type');

        if ($this->showPrivacyTabs) {
            $query->where('member_id', $this->viewAs === '' ? null : $this->viewAs);
        }

        return $query->get();
    }

    /**
     * Cobertura somada por tipo de risco — o card de resumo da spec.
     *
     * @return Collection<string, array{cobertura: string, mensal: string, quantidade: int}>
     */
    public function getByTypeProperty(): Collection
    {
        return $this->policies
            ->groupBy(fn (InsurancePolicy $p) => $p->insurance_type->value)
            ->map(fn (Collection $grupo) => [
                'cobertura' => Money::sum($grupo->pluck('coverage_amount')),
                'mensal' => Money::sum($grupo->map(fn (InsurancePolicy $p) => $p->normalizedMonthlyCost())),
                'quantidade' => $grupo->count(),
            ]);
    }

    /**
     * Apólices agrupadas para a lista "Suas apólices": tipo → (pessoa,
     * quando há mais de uma dona naquele tipo, ou sempre em Saúde, que é
     * sempre pessoal mesmo perfil individual) → seguradora → apólices.
     * Casal com dois seguros de vida (um por cônjuge) separa por pessoa
     * antes da seguradora; perfil individual com só um seguro de carro
     * vai direto pra seguradora, sem cabeçalho redundante. "Pessoa" usa
     * InsurancePolicy::personGroupKey() — cobre tanto titular/cônjuge
     * cadastrado quanto alguém sem ProfileMember (ex.: filha), ver
     * insured_person_name.
     *
     * @return Collection<int, array{tipo: InsuranceType, separarPorMembro: bool, membros: Collection}>
     */
    public function getGroupedProperty(): Collection
    {
        return $this->policies
            ->groupBy(fn (InsurancePolicy $p) => $p->insurance_type->value)
            ->map(function (Collection $doTipo, string $tipoValue) {
                $tipo = InsuranceType::from($tipoValue);
                $separarPorMembro = $tipo === InsuranceType::Saude
                    || $doTipo->map(fn (InsurancePolicy $p) => $p->personGroupKey())->unique()->count() > 1;

                $porMembro = $separarPorMembro
                    ? $doTipo->groupBy(fn (InsurancePolicy $p) => $p->personGroupKey())
                    : collect(['todos' => $doTipo]);

                return [
                    'tipo' => $tipo,
                    'separarPorMembro' => $separarPorMembro,
                    'membros' => $porMembro->map(fn (Collection $doMembro) => [
                        'nome' => $doMembro->first()->personLabel(),
                        'seguradoras' => $doMembro->groupBy('insurer_name'),
                    ])->values(),
                ];
            })
            ->sortBy(fn (array $grupo) => $grupo['tipo']->label())
            ->values();
    }

    public function getTotalCoverageProperty(): string
    {
        return Money::sum($this->policies->pluck('coverage_amount'));
    }

    /** Custo mensal normalizado: apólice anual dividida por 12. */
    public function getTotalMonthlyProperty(): string
    {
        return Money::sum($this->policies->map(fn (InsurancePolicy $p) => $p->normalizedMonthlyCost()));
    }

    /** @return Collection<int, InsurancePolicy> */
    public function getExpiringProperty(): Collection
    {
        return $this->policies
            ->filter(fn (InsurancePolicy $p) => $p->isExpiring(30))
            ->sortBy('expiry_date')
            ->values();
    }

    public function getInsurersCountProperty(): int
    {
        return $this->policies->pluck('insurer_name')->unique()->count();
    }

    public function render()
    {
        $context = app(ProfileContext::class);
        $profileId = $context->profileId();

        return view('livewire.insurance.insurance-index', [
            'showPrivacyTabs' => $this->showPrivacyTabs,
            'privacyMembers' => $this->privacyMembers,
            'profile' => $context->profile(),
            'member' => $context->member(),
            'policies' => $this->policies,
            'byType' => $this->byType,
            'grouped' => $this->grouped,
            'totalCoverage' => $this->totalCoverage,
            'totalMonthly' => $this->totalMonthly,
            'expiring' => $this->expiring,
            'insurersCount' => $this->insurersCount,
            'members' => ProfileMember::query()
                ->where('profile_id', $profileId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
        ]);
    }
}
