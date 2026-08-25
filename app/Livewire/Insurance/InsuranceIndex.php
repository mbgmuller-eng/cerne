<?php

namespace App\Livewire\Insurance;

use App\Livewire\Concerns\HasPrivacyTabs;
use App\Livewire\Concerns\RequiresActiveProfile;
use App\Models\InsurancePolicy;
use App\Support\Money;
use App\Support\ProfileContext;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Tela 6 — Seguros, com resumo agregado por categoria de risco.
 */
#[Layout('components.layouts.app')]
class InsuranceIndex extends Component
{
    use RequiresActiveProfile;
    use HasPrivacyTabs;

    protected function privacyDomains(): array
    {
        return ['insurance_visibility'];
    }

    public function mount(): void
    {
        $this->redirectOrAbortWithoutProfile();
    }

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

        return view('livewire.insurance.insurance-index', [
            'showPrivacyTabs' => $this->showPrivacyTabs,
            'privacyMembers' => $this->privacyMembers,
            'profile' => $context->profile(),
            'member' => $context->member(),
            'policies' => $this->policies,
            'byType' => $this->byType,
            'totalCoverage' => $this->totalCoverage,
            'totalMonthly' => $this->totalMonthly,
            'expiring' => $this->expiring,
            'insurersCount' => $this->insurersCount,
        ]);
    }
}
