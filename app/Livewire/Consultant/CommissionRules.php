<?php

namespace App\Livewire\Consultant;

use App\Enums\CommissionType;
use App\Enums\InsuranceType;
use App\Models\Insurer;
use App\Services\InsuranceCommissionService;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Regras de comissionamento do profissional (hoje, seguradoras) e as apólices em que ele tem comissão. Mora dentro da
 * tela de Recebimentos (aba "Regras de comissionamento"); só mexe nos dados do próprio profissional.
 */
class CommissionRules extends Component
{
    public ?string $aviso = null;

    public bool $showRuleForm = false;

    public ?string $ruleId = null;

    public string $ruleCounterparty = '';

    public string $ruleType = '';

    public string $ruleFirstYear = '';

    public string $ruleRenewal = '';

    public string $ruleAgencyType = '';

    public string $ruleAgencyPercent = '';

    public string $ruleAgencyAmount = '';

    public string $ruleDelay = '0';

    public string $ruleNotes = '';

    public bool $showIncludeForm = false;

    public string $includePolicyId = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isLinkedProfessional(), 403);
    }

    public function openRuleForm(?string $id = null, ?string $seguradora = null): void
    {
        $this->resetErrorBag();
        $this->reset('ruleId', 'ruleType', 'ruleFirstYear', 'ruleRenewal', 'ruleAgencyType', 'ruleAgencyPercent', 'ruleAgencyAmount', 'ruleNotes');
        $this->ruleCounterparty = (string) $seguradora;
        $this->ruleDelay = '0';

        if ($id !== null) {
            $regra = app(InsuranceCommissionService::class)->rules(auth()->user())->firstWhere('id', $id);
            abort_if($regra === null, 404);
            $this->ruleId = $regra->id;
            $this->ruleCounterparty = $regra->counterparty;
            $this->ruleType = (string) $regra->insurance_type?->value;
            $this->ruleFirstYear = (string) $regra->first_year_percent;
            $this->ruleRenewal = (string) $regra->renewal_percent;
            $this->ruleAgencyType = (string) $regra->agency_type?->value;
            $this->ruleAgencyPercent = (string) $regra->agency_percent;
            $this->ruleAgencyAmount = (string) $regra->agency_amount;
            $this->ruleDelay = (string) $regra->payout_delay_days;
            $this->ruleNotes = (string) $regra->notes;
        }

        $this->showRuleForm = true;
    }

    public function saveRule(InsuranceCommissionService $service): void
    {
        $dados = $this->validate([
            'ruleCounterparty' => ['required', 'string', 'max:120'],
            'ruleType' => ['nullable', Rule::in(array_keys(InsuranceType::options()))],
            'ruleFirstYear' => ['required', 'numeric', 'min:0', 'max:100'],
            'ruleRenewal' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'ruleAgencyType' => ['nullable', Rule::in(array_keys(CommissionType::options()))],
            'ruleAgencyPercent' => ['nullable', 'required_if:ruleAgencyType,percent', 'numeric', 'min:0', 'max:1000'],
            'ruleAgencyAmount' => ['nullable', 'required_if:ruleAgencyType,amount', 'numeric', 'min:0', 'max:999999999'],
            'ruleDelay' => ['required', 'integer', 'min:0', 'max:120'],
            'ruleNotes' => ['nullable', 'string', 'max:500'],
        ], attributes: [
            'ruleCounterparty' => 'seguradora', 'ruleType' => 'tipo de seguro', 'ruleFirstYear' => 'comissão do 1º ano', 'ruleRenewal' => 'comissão da renovação',
            'ruleAgencyType' => 'tipo de agenciamento', 'ruleAgencyPercent' => 'percentual do agenciamento', 'ruleAgencyAmount' => 'valor do agenciamento',
            'ruleDelay' => 'prazo de pagamento', 'ruleNotes' => 'observação',
        ]);

        if ($service->ruleExists(auth()->user(), $dados['ruleCounterparty'], $dados['ruleType'] ?? null, $this->ruleId)) {
            $this->addError('ruleCounterparty', 'Já existe uma regra para esta seguradora e este tipo de seguro.');

            return;
        }

        $service->saveRule(auth()->user(), $this->ruleId, [
            'counterparty' => $dados['ruleCounterparty'], 'insurance_type' => $dados['ruleType'] ?? null,
            'first_year_percent' => $dados['ruleFirstYear'], 'renewal_percent' => $dados['ruleRenewal'] ?? null,
            'agency_type' => $dados['ruleAgencyType'] ?? null, 'agency_percent' => $dados['ruleAgencyPercent'] ?? null,
            'agency_amount' => $dados['ruleAgencyAmount'] ?? null, 'payout_delay_days' => $dados['ruleDelay'], 'notes' => $dados['ruleNotes'] ?? null,
        ]);

        $this->showRuleForm = false;
        $this->aviso = 'Regra salva. A previsão de recebimentos já foi refeita.';
    }

    public function toggleRule(InsuranceCommissionService $service, string $id, bool $ativo): void
    {
        $service->setRuleActive(auth()->user(), $id, $ativo);
        $this->aviso = $ativo ? 'Regra reativada.' : 'Regra pausada. A previsão que dependia dela saiu do livro.';
    }

    public function deleteRule(InsuranceCommissionService $service, string $id): void
    {
        $service->deleteRule(auth()->user(), $id);
        $this->aviso = 'Regra excluída.';
    }

    public function toggleLink(InsuranceCommissionService $service, string $id, bool $ativo): void
    {
        $service->setLinkActive(auth()->user(), $id, $ativo);
        $this->aviso = $ativo ? 'A apólice voltou a gerar comissão.' : 'A apólice deixou de gerar comissão.';
    }

    public function openIncludeForm(): void
    {
        abort_unless(auth()->user()->isConsultant(), 403);

        $this->resetErrorBag();
        $this->includePolicyId = '';
        $this->showIncludeForm = true;
    }

    public function includePolicy(InsuranceCommissionService $service): void
    {
        abort_unless(auth()->user()->isConsultant(), 403);

        $this->validate(['includePolicyId' => ['required', Rule::in(array_keys($service->includablePolicies(auth()->user())))]], attributes: ['includePolicyId' => 'apólice']);

        $service->includePolicy(auth()->user(), $this->includePolicyId);

        $this->showIncludeForm = false;
        $this->aviso = 'Apólice incluída.';
    }

    public function recalculate(InsuranceCommissionService $service): void
    {
        $service->linkSoldPolicies(auth()->user());
        $r = $service->reconcile(auth()->user());
        $this->aviso = "Previsão refeita: {$r['criados']} nova(s), {$r['atualizados']} corrigida(s), {$r['removidos']} retirada(s).";
    }

    public function render(InsuranceCommissionService $service)
    {
        $usuario = auth()->user();
        $ehConsultor = $usuario->isConsultant();

        return view('livewire.consultant.commission-rules', [
            'regras' => $service->rules($usuario),
            'apolices' => $service->policyRows($usuario),
            'semRegra' => $service->insurersWithoutRule($usuario),
            'incluiveis' => $ehConsultor && $this->showIncludeForm ? $service->includablePolicies($usuario) : [],
            'tipos' => InsuranceType::options(),
            'seguradoras' => Insurer::names(),
            'ehConsultor' => $ehConsultor,
        ]);
    }
}
