<?php

namespace App\Livewire\Goals;

use App\Enums\DebtKind;
use App\Enums\DebtStatus;
use App\Enums\FundingMethod;
use App\Enums\GoalStatus;
use App\Livewire\Concerns\RequiresActiveProfile;
use App\Models\Debt;
use App\Models\Goal;
use App\Models\InvestmentRecord;
use App\Models\ProfileMember;
use App\Support\Money;
use App\Support\ProfileContext;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Tela 7 — Sonhos & Objetivos, ordenados por prioridade. Aqui também se cria, edita, marca como conquistado e exclui.
 *
 * Um objetivo avança de um de três jeitos: pelo dinheiro que a pessoa informa, por um investimento ligado (o saldo dele é o
 * acumulado) ou por um consórcio ligado (o avanço é a fração das parcelas pagas; ver Goal::accumulated).
 */
#[Layout('components.layouts.app')]
class GoalsIndex extends Component
{
    use RequiresActiveProfile;

    public bool $showForm = false;

    public ?string $editingId = null;

    public string $name = '';

    public string $estimatedValue = '';

    public string $targetDate = '';

    public string $fundingMethod = 'lump_sum';

    public string $installmentAmount = '';

    public string $currentAmount = '';

    /** '' (sem ligação), 'investment' ou 'debt' (consórcio). */
    public string $linkType = '';

    public string $linkedInvestmentId = '';

    public string $linkedDebtId = '';

    public string $memberId = '';

    public bool $isPrivate = false;

    public string $priority = '';

    public string $status = 'active';

    public string $notes = '';

    public function mount(): void
    {
        $this->redirectOrAbortWithoutProfile();
        // Corretor só entra em Seguros — nenhuma outra tela financeira.
        abort_if(auth()->user()?->isBroker(), 403);
    }

    // ------------------------------------------------------------------ formulário

    public function openForm(): void
    {
        $this->resetValidation();
        $this->reset('editingId', 'name', 'estimatedValue', 'targetDate', 'installmentAmount', 'currentAmount', 'linkType', 'linkedInvestmentId', 'linkedDebtId', 'isPrivate', 'notes');
        $this->fundingMethod = FundingMethod::LumpSum->value;
        $this->status = GoalStatus::Active->value;
        $this->priority = (string) ((int) Goal::query()->max('priority') + 1);
        $this->memberId = (string) app(ProfileContext::class)->memberId();
        $this->showForm = true;
    }

    public function edit(string $id): void
    {
        $objetivo = $this->editable($id);

        $this->resetValidation();
        $this->editingId = $objetivo->id;
        $this->name = $objetivo->name;
        $this->estimatedValue = (string) $objetivo->estimated_value;
        $this->targetDate = (string) $objetivo->target_date?->toDateString();
        $this->fundingMethod = $objetivo->funding_method->value;
        $this->installmentAmount = (string) $objetivo->installment_amount;
        $this->currentAmount = (string) $objetivo->current_amount;
        $this->linkedInvestmentId = (string) $objetivo->linked_investment_id;
        $this->linkedDebtId = (string) $objetivo->linked_debt_id;
        $this->linkType = $objetivo->linked_debt_id ? 'debt' : ($objetivo->linked_investment_id ? 'investment' : '');
        $this->memberId = (string) $objetivo->member_id;
        $this->isPrivate = $objetivo->is_private;
        $this->priority = (string) $objetivo->priority;
        $this->status = $objetivo->status === GoalStatus::Achieved ? GoalStatus::Achieved->value : GoalStatus::Active->value;
        $this->notes = (string) $objetivo->notes;
        $this->showForm = true;
    }

    /** Ligar a um consórcio faz o objetivo avançar pelas parcelas e usar a carta como valor. */
    public function updatedLinkedDebtId(string $valor): void
    {
        $consorcio = $valor !== '' ? $this->consorcios()->firstWhere('id', $valor) : null;

        if ($consorcio !== null) {
            $this->estimatedValue = (string) $consorcio->credit_amount;
            $this->fundingMethod = FundingMethod::Installments->value;
            $this->installmentAmount = (string) $consorcio->installment_amount;

            if ($this->name === '') {
                $this->name = (string) $consorcio->name;
            }
        }
    }

    public function save(): void
    {
        $ligadoADivida = $this->linkType === 'debt';
        $ligadoAInvestimento = $this->linkType === 'investment';
        $dinheiro = ['nullable', 'string', 'max:20', 'regex:/^[0-9.,\s]*$/'];

        $dados = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'estimatedValue' => ['required', 'string', 'max:20', 'regex:/^[0-9.,\s]+$/'],
            'targetDate' => ['nullable', 'date_format:Y-m-d'],
            'fundingMethod' => ['required', Rule::enum(FundingMethod::class)],
            'installmentAmount' => $this->fundingMethod === FundingMethod::Installments->value && ! $ligadoADivida ? ['required', 'string', 'max:20', 'regex:/^[0-9.,\s]+$/'] : $dinheiro,
            'currentAmount' => $dinheiro,
            'linkType' => ['nullable', 'in:investment,debt'],
            'linkedInvestmentId' => [$ligadoAInvestimento ? 'required' : 'nullable', 'string'],
            'linkedDebtId' => [$ligadoADivida ? 'required' : 'nullable', 'string'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:999'],
            'status' => ['required', 'in:active,achieved'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'estimatedValue.regex' => 'Informe o valor só com números (ex.: 100000,00).',
            'installmentAmount.regex' => 'Informe o valor só com números (ex.: 1500,00).',
            'currentAmount.regex' => 'Informe o valor só com números (ex.: 5000,00).',
        ], [
            'name' => 'nome', 'estimatedValue' => 'valor do objetivo', 'targetDate' => 'data-alvo', 'installmentAmount' => 'valor da parcela',
            'currentAmount' => 'quanto já tem', 'linkedInvestmentId' => 'investimento', 'linkedDebtId' => 'consórcio', 'priority' => 'prioridade',
        ]);

        $valor = Money::parse($dados['estimatedValue']);

        if (bccomp($valor, '0', 2) <= 0) {
            $this->addError('estimatedValue', 'O valor do objetivo precisa ser maior que zero.');

            return;
        }

        $investimento = null;
        $consorcio = null;

        if ($ligadoAInvestimento) {
            $investimento = InvestmentRecord::query()->active()->find($dados['linkedInvestmentId']);

            if ($investimento === null) {
                $this->addError('linkedInvestmentId', 'Escolha um investimento da lista.');

                return;
            }
        }

        if ($ligadoADivida) {
            $consorcio = $this->consorcios()->firstWhere('id', $dados['linkedDebtId']);

            if ($consorcio === null) {
                $this->addError('linkedDebtId', 'Escolha um consórcio da lista (que ainda não esteja ligado a outro objetivo).');

                return;
            }
        }

        $membro = $this->memberId !== '' && $this->membros()->contains('id', $this->memberId) ? $this->memberId : null;

        $campos = [
            'name' => $dados['name'],
            'estimated_value' => $valor,
            'target_date' => $dados['targetDate'] ?: null,
            'funding_method' => $ligadoADivida ? FundingMethod::Installments : FundingMethod::from($dados['fundingMethod']),
            'installment_amount' => $ligadoADivida
                ? $consorcio->installment_amount
                : (($dados['installmentAmount'] ?? '') !== '' ? Money::parse($dados['installmentAmount']) : null),
            'current_amount' => ($dados['currentAmount'] ?? '') !== '' && ! $ligadoAInvestimento && ! $ligadoADivida ? Money::parse($dados['currentAmount']) : '0.00',
            'linked_investment_id' => $investimento?->id,
            'linked_debt_id' => $consorcio?->id,
            'priority' => (int) ($dados['priority'] ?: ((int) Goal::query()->max('priority') + 1)),
            'status' => GoalStatus::from($dados['status']),
            'notes' => $dados['notes'] ?: null,
            'member_id' => $membro,
            'is_private' => $membro !== null && $this->isPrivate,
        ];

        if ($this->editingId !== null) {
            $this->editable($this->editingId)->update($campos);
        } else {
            Goal::create($campos + ['created_by_user_id' => auth()->id()]);
        }

        $this->showForm = false;
    }

    // ------------------------------------------------------------------ ações

    public function delete(string $id): void
    {
        $this->editable($id)->delete();

        $this->showForm = false;
    }

    public function markAchieved(string $id): void
    {
        $this->editable($id)->update(['status' => GoalStatus::Achieved]);
    }

    public function reopen(string $id): void
    {
        $this->editable($id)->update(['status' => GoalStatus::Active]);
    }

    /** O objetivo, só se a pessoa pode mexer nele (o de outro membro, privado, nem aparece). */
    private function editable(string $id): Goal
    {
        $objetivo = Goal::query()->findOrFail($id);

        abort_unless($objetivo->isEditableByCurrentMember(), 403);

        return $objetivo;
    }

    /** @return Collection<int, ProfileMember> */
    private function membros(): Collection
    {
        return ProfileMember::query()
            ->where('profile_id', app(ProfileContext::class)->profileId())
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /** Consórcios ativos que ainda não têm objetivo (um consórcio, um objetivo), mais o deste objetivo, quando se edita. */
    private function consorcios(): Collection
    {
        $ocupados = Goal::query()->whereNotNull('linked_debt_id')
            ->when($this->editingId, fn ($q) => $q->where('id', '!=', $this->editingId))
            ->pluck('linked_debt_id');

        return Debt::query()
            ->where('kind', DebtKind::Consortium->value)
            ->where('status', DebtStatus::Active->value)
            ->whereNotIn('id', $ocupados)
            ->orderBy('name')
            ->get();
    }

    /**
     * O que "Salvar" vai deixar na tela de objetivos, com o que a pessoa digitou. Monta um Goal sem gravar e usa os mesmos métodos que a lista
     * (`accumulated`, `progressPercentage`, `monthlyNeeded`), então o número do aviso é o que a lista vai mostrar depois de salvar.
     *
     * @return list<string>
     */
    private function goalConsequence(): array
    {
        if (! $this->showForm) {
            return [];
        }

        $valor = Money::parse($this->estimatedValue);

        if (bccomp($valor, '0', 2) <= 0 || preg_match('/^[0-9.,\s]+$/', $this->estimatedValue) !== 1) {
            return [];
        }

        $investimento = $this->linkType === 'investment' && $this->linkedInvestmentId !== '' ? InvestmentRecord::query()->active()->find($this->linkedInvestmentId) : null;
        $consorcio = $this->linkType === 'debt' && $this->linkedDebtId !== '' ? $this->consorcios()->firstWhere('id', $this->linkedDebtId) : null;
        $semLigacao = $investimento === null && $consorcio === null;

        $objetivo = (new Goal)->forceFill([
            'estimated_value' => $valor,
            'target_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->targetDate) === 1 ? $this->targetDate : null,
            'current_amount' => $semLigacao && preg_match('/^[0-9.,\s]+$/', $this->currentAmount) === 1 ? Money::parse($this->currentAmount) : '0.00',
            'linked_investment_id' => $investimento?->id,
            'linked_debt_id' => $consorcio?->id,
        ]);
        $objetivo->setRelation('linkedInvestment', $investimento);
        $objetivo->setRelation('linkedDebt', $consorcio);

        $linhas = [];
        $pct = number_format($objetivo->progressPercentage(), 1, ',', '.').'%';

        if ($investimento !== null) {
            $linhas[] = 'O avanço vem do saldo de '.$investimento->displayName().': '.Money::format($objetivo->accumulated()).' de '.Money::format($valor).' ('.$pct.'). Você não precisa informar quanto já tem.';
        } elseif ($consorcio !== null) {
            $progresso = $objetivo->installmentProgress();
            $linhas[] = 'O avanço vem das parcelas pagas do consórcio: '.$progresso['paid'].' de '.$progresso['total'].' ('.$pct.' de '.Money::format($valor).').';
        } else {
            $linhas[] = 'Com '.Money::format($objetivo->accumulated()).' de '.Money::format($valor).', o objetivo fica em '.$pct.'.';
        }

        $falta = $objetivo->remaining();

        if (bccomp($falta, '0', 2) > 0) {
            $mensal = $objetivo->monthlyNeeded();

            if ($mensal !== null) {
                $linhas[] = 'Faltam '.Money::format($falta).' até '.$objetivo->target_date->format('d/m/Y').': cerca de '.Money::format($mensal).' por mês.';
            } elseif ($objetivo->target_date !== null) {
                $linhas[] = 'A data-alvo já passou, então o Cerne não calcula quanto guardar por mês.';
            } else {
                $linhas[] = 'Sem data-alvo, o Cerne não calcula quanto guardar por mês.';
            }
        }

        if ($this->editingId !== null) {
            $antes = Goal::query()->find($this->editingId);

            if (! $semLigacao && $antes !== null && $antes->linked_investment_id === null && $antes->linked_debt_id === null && bccomp((string) $antes->current_amount, '0', 2) > 0) {
                $linhas[] = 'O valor que estava em "Quanto já tem" ('.Money::format($antes->current_amount).') deixa de valer: o avanço passa a vir da ligação escolhida.';
            }

            if ($this->status === GoalStatus::Achieved->value && $antes?->status !== GoalStatus::Achieved) {
                $linhas[] = 'Conquistado: o objetivo sai da lista em andamento e deixa de entrar nos totais do topo. Você pode reabrir depois.';
            }
        }

        if ($this->memberId !== '' && $this->isPrivate) {
            $nome = $this->membros()->firstWhere('id', $this->memberId)?->name ?? 'a pessoa escolhida';
            $linhas[] = 'Só '.$nome.' e o consultor verão este objetivo.';
        }

        return $linhas;
    }

    // ------------------------------------------------------------------ leitura

    /** @return Collection<int, Goal> */
    public function getGoalsProperty(): Collection
    {
        return Goal::query()
            ->active()
            ->byPriority()
            ->with('member', 'linkedInvestment', 'linkedDebt')
            ->get();
    }

    /** @return Collection<int, Goal> */
    public function getAchievedProperty(): Collection
    {
        return Goal::query()
            ->where('status', GoalStatus::Achieved)
            ->with('member')
            ->get();
    }

    public function getTotalTargetProperty(): string
    {
        return Money::sum($this->goals->pluck('estimated_value'));
    }

    public function getTotalAccumulatedProperty(): string
    {
        return Money::sum($this->goals->map(fn (Goal $g) => $g->accumulated()));
    }

    /** Soma do que precisa ser guardado por mês para cumprir os prazos. */
    public function getTotalMonthlyNeededProperty(): string
    {
        return Money::sum(
            $this->goals->map(fn (Goal $g) => $g->monthlyNeeded())->filter()
        );
    }

    public function render()
    {
        return view('livewire.goals.goals-index', [
            'goals' => $this->goals,
            'achieved' => $this->achieved,
            'totalTarget' => $this->totalTarget,
            'totalAccumulated' => $this->totalAccumulated,
            'totalMonthlyNeeded' => $this->totalMonthlyNeeded,
            'membros' => $this->membros(),
            'investimentos' => $this->showForm ? InvestmentRecord::query()->active()->orderBy('institution')->orderBy('name')->get() : collect(),
            'consorcios' => $this->showForm ? $this->consorcios() : collect(),
            'metodos' => FundingMethod::options(),
            'goalConsequence' => $this->goalConsequence(),
        ]);
    }
}
