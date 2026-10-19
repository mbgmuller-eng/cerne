<?php

namespace App\Livewire\Consultant;

use App\Models\DebtCommission;
use App\Services\ConsortiumCommissionService;
use App\Support\ConsortiumSchedule;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Comissão de consórcio do consultor: regras por administradora e os consórcios dos clientes em que ele tem comissão
 * (ele vendeu ou um terceiro vendeu). Mora na aba "Regras de comissionamento" da tela de Recebimentos; só mexe nos dados
 * do próprio consultor.
 */
class ConsortiumCommissions extends Component
{
    private const SCHEDULE_HINT = 'Informe as parcelas assim: 2, 4, 6 (partes iguais) ou 2:40, 4:30, 6:30 (a divisão em %, somando 100).';

    public ?string $aviso = null;

    public bool $showRuleForm = false;

    public ?string $ruleId = null;

    public string $ruleCounterparty = '';

    public string $ruleCreditPercent = '';

    public string $ruleSchedule = '';

    public string $ruleDelay = '0';

    public string $ruleNotes = '';

    public bool $showLinkForm = false;

    public ?string $linkId = null;

    public string $linkDebtId = '';

    public string $linkRole = 'sold';

    public string $linkPercent = '';

    public string $linkSchedule = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isConsultant(), 403);
    }

    // --- Regras ------------------------------------------------------------------------------------------------

    public function openRuleForm(?string $id = null): void
    {
        $this->resetErrorBag();
        $this->reset('ruleId', 'ruleCounterparty', 'ruleCreditPercent', 'ruleSchedule', 'ruleNotes');
        $this->ruleDelay = '0';

        if ($id !== null) {
            $regra = app(ConsortiumCommissionService::class)->rules(auth()->user())->firstWhere('id', $id);
            abort_if($regra === null, 404);
            $this->ruleId = $regra->id;
            $this->ruleCounterparty = $regra->counterparty;
            $this->ruleCreditPercent = (string) $regra->credit_percent;
            $this->ruleSchedule = (string) $regra->installment_schedule;
            $this->ruleDelay = (string) $regra->payout_delay_days;
            $this->ruleNotes = (string) $regra->notes;
        }

        $this->showRuleForm = true;
    }

    public function saveRule(ConsortiumCommissionService $service): void
    {
        $dados = $this->validate([
            'ruleCounterparty' => ['required', 'string', 'max:120'],
            'ruleCreditPercent' => ['required', 'numeric', 'min:0.0001', 'max:100'],
            'ruleSchedule' => ['required', 'string', 'max:80', $this->scheduleRule()],
            'ruleDelay' => ['required', 'integer', 'min:0', 'max:120'],
            'ruleNotes' => ['nullable', 'string', 'max:500'],
        ], attributes: [
            'ruleCounterparty' => 'administradora', 'ruleCreditPercent' => 'percentual da carta', 'ruleSchedule' => 'parcelas',
            'ruleDelay' => 'prazo de pagamento', 'ruleNotes' => 'observação',
        ]);

        if ($service->ruleExists(auth()->user(), $dados['ruleCounterparty'], $this->ruleId)) {
            $this->addError('ruleCounterparty', 'Já existe uma regra para esta administradora.');

            return;
        }

        $service->saveRule(auth()->user(), $this->ruleId, [
            'counterparty' => $dados['ruleCounterparty'], 'credit_percent' => $dados['ruleCreditPercent'], 'installment_schedule' => $dados['ruleSchedule'],
            'payout_delay_days' => $dados['ruleDelay'], 'notes' => $dados['ruleNotes'] ?? null,
        ]);

        $this->showRuleForm = false;
        $this->aviso = 'Regra salva. A previsão já foi refeita.';
    }

    public function toggleRule(ConsortiumCommissionService $service, string $id, bool $ativo): void
    {
        $service->setRuleActive(auth()->user(), $id, $ativo);
        $this->aviso = $ativo ? 'Regra reativada.' : 'Regra pausada. A previsão que dependia dela saiu do livro.';
    }

    public function deleteRule(ConsortiumCommissionService $service, string $id): void
    {
        $service->deleteRule(auth()->user(), $id);
        $this->aviso = 'Regra excluída.';
    }

    // --- Consórcios com comissão ------------------------------------------------------------------------------------

    public function openLinkForm(?string $id = null): void
    {
        $this->resetErrorBag();
        $this->reset('linkId', 'linkDebtId', 'linkPercent', 'linkSchedule');
        $this->linkRole = DebtCommission::ROLE_SOLD;

        if ($id !== null) {
            $vinculo = DebtCommission::query()->where('professional_id', auth()->id())->findOrFail($id);
            $this->linkId = $vinculo->id;
            $this->linkRole = $vinculo->role;
            $this->linkPercent = (string) $vinculo->percent_override;
            $this->linkSchedule = (string) $vinculo->schedule_override;
        }

        $this->showLinkForm = true;
    }

    public function saveLink(ConsortiumCommissionService $service): void
    {
        $regras = [
            'linkRole' => ['required', Rule::in([DebtCommission::ROLE_SOLD, DebtCommission::ROLE_THIRD_PARTY])],
            'linkPercent' => [$this->linkRole === DebtCommission::ROLE_THIRD_PARTY ? 'required' : 'nullable', 'numeric', 'min:0.0001', 'max:100'],
            'linkSchedule' => ['nullable', 'string', 'max:80', $this->scheduleRule()],
        ];

        if ($this->linkId === null) {
            $regras['linkDebtId'] = ['required', Rule::in(array_keys($service->includableDebts(auth()->user())))];
        }

        $dados = $this->validate($regras, attributes: [
            'linkDebtId' => 'consórcio', 'linkRole' => 'quem vendeu', 'linkPercent' => 'percentual da carta', 'linkSchedule' => 'parcelas',
        ]);

        if ($this->linkId !== null) {
            $service->updateLink(auth()->user(), $this->linkId, $dados['linkRole'], $dados['linkPercent'] ?? null, $dados['linkSchedule'] ?? null);
        } else {
            $service->includeDebt(auth()->user(), $dados['linkDebtId'], $dados['linkRole'], $dados['linkPercent'] ?? null, $dados['linkSchedule'] ?? null);
        }

        $this->showLinkForm = false;
        $this->aviso = 'Consórcio salvo. A previsão já foi refeita.';
    }

    public function toggleLink(ConsortiumCommissionService $service, string $id, bool $ativo): void
    {
        $service->setLinkActive(auth()->user(), $id, $ativo);
        $this->aviso = $ativo ? 'O consórcio voltou a gerar comissão.' : 'O consórcio deixou de gerar comissão.';
    }

    public function deleteLink(ConsortiumCommissionService $service, string $id): void
    {
        $service->deleteLink(auth()->user(), $id);
        $this->aviso = 'Consórcio removido da lista. O que já foi recebido continua no livro.';
    }

    public function render(ConsortiumCommissionService $service)
    {
        $usuario = auth()->user();

        return view('livewire.consultant.consortium-commissions', [
            'regras' => $service->rules($usuario),
            'consorcios' => $service->linkRows($usuario),
            'incluiveis' => $this->showLinkForm && $this->linkId === null ? $service->includableDebts($usuario) : [],
            'administradoras' => $this->showRuleForm ? $service->administratorsOf($usuario) : [],
            'dica' => self::SCHEDULE_HINT,
        ]);
    }

    private function scheduleRule(): \Closure
    {
        return function (string $attribute, mixed $valor, \Closure $fail): void {
            if (filled($valor) && ConsortiumSchedule::parse((string) $valor) === null) {
                $fail(self::SCHEDULE_HINT);
            }
        };
    }
}
