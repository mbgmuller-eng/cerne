<?php

namespace App\Livewire\Consultant;

use App\Enums\AssetClass;
use App\Enums\InvestmentRuleScope;
use App\Services\AssetFeeService;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Regras de percentual sobre patrimônio do consultor: quanto cobrar ao ano de cada produto, empresa (instituição) ou tipo
 * de ativo. Valem para os contratos no modo "regras". Mora na aba "Regras de comissionamento" da tela de Recebimentos.
 */
class InvestmentRules extends Component
{
    public ?string $aviso = null;

    public bool $showForm = false;

    public ?string $ruleId = null;

    public string $scope = 'institution';

    public string $target = '';

    public string $annualPercent = '';

    public string $notes = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isConsultant(), 403);
    }

    public function openForm(?string $id = null): void
    {
        $this->resetErrorBag();
        $this->reset('ruleId', 'target', 'annualPercent', 'notes');
        $this->scope = InvestmentRuleScope::Institution->value;

        if ($id !== null) {
            $regra = app(AssetFeeService::class)->rules(auth()->user())->firstWhere('id', $id);
            abort_if($regra === null, 404);
            $this->ruleId = $regra->id;
            $this->scope = $regra->type_key;
            $this->target = $regra->type_key === InvestmentRuleScope::AssetClass->value ? $regra->counterparty_key : $regra->counterparty;
            $this->annualPercent = (string) $regra->annual_percent;
            $this->notes = (string) $regra->notes;
        }

        $this->showForm = true;
    }

    /** Trocar o escopo limpa o alvo: o que se digita para empresa não serve de tipo de ativo. */
    public function updatedScope(): void
    {
        $this->target = '';
    }

    public function save(AssetFeeService $service): void
    {
        $dados = $this->validate([
            'scope' => ['required', Rule::in(array_keys(InvestmentRuleScope::options()))],
            'target' => ['required', 'string', 'max:120', $this->scope === InvestmentRuleScope::AssetClass->value ? Rule::in(array_keys(AssetClass::options())) : 'string'],
            'annualPercent' => ['required', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], attributes: ['scope' => 'tipo de regra', 'target' => 'alvo da regra', 'annualPercent' => 'percentual ao ano', 'notes' => 'observação']);

        if ($service->ruleExists(auth()->user(), $dados['scope'], $dados['target'], $this->ruleId)) {
            $this->addError('target', 'Já existe uma regra para este alvo.');

            return;
        }

        $service->saveRule(auth()->user(), $this->ruleId, [
            'scope' => $dados['scope'], 'target' => $dados['target'], 'annual_percent' => $dados['annualPercent'], 'notes' => $dados['notes'] ?? null,
        ]);

        $this->showForm = false;
        $this->aviso = 'Regra salva. A previsão dos contratos já foi refeita.';
    }

    public function toggle(AssetFeeService $service, string $id, bool $ativo): void
    {
        $service->setRuleActive(auth()->user(), $id, $ativo);
        $this->aviso = $ativo ? 'Regra reativada.' : 'Regra pausada.';
    }

    public function delete(AssetFeeService $service, string $id): void
    {
        $service->deleteRule(auth()->user(), $id);
        $this->aviso = 'Regra excluída.';
    }

    public function render(AssetFeeService $service)
    {
        return view('livewire.consultant.investment-rules', [
            'regras' => $service->rules(auth()->user()),
            'escopos' => InvestmentRuleScope::options(),
            'tiposDeAtivo' => AssetClass::options(),
            'empresas' => $this->showForm && $this->scope === InvestmentRuleScope::Institution->value ? $service->institutionsOf(auth()->user()) : [],
        ]);
    }
}
