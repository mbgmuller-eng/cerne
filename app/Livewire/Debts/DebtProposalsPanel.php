<?php

namespace App\Livewire\Debts;

use App\Models\Debt;
use App\Models\DebtSimulation;
use App\Support\Debts\DebtProposals;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * "Propostas de acordo" na tela da dívida em aberto: TODAS as propostas guardadas numa lista só (as das simulações e as cadastradas uma a uma),
 * com cadastro de proposta nova, edição, exclusão, comparação lado a lado e o atalho "Usar esta proposta" (leva ao Registrar acordo).
 *
 * O armazenamento é o de `DebtSimulation` (um recipiente com nome, saldo da data e as propostas, cifradas); a proposta cadastrada sozinha vira um
 * recipiente de uma proposta só. Por isso herda dono e privacidade da dívida e some para quem não a vê. A conta de comparação está em
 * `App\Support\Debts\DebtProposals`, em cima do `DebtAgreementSimulator`.
 */
class DebtProposalsPanel extends Component
{
    #[Locked]
    public string $debtId = '';

    /** Chaves ("idDaSimulação:posição") das propostas marcadas para comparar. @var list<string> */
    public array $selected = [];

    public bool $showForm = false;

    /** Chave da proposta em edição; vazio = cadastrando uma nova. */
    #[Locked]
    public string $editKey = '';

    public string $pLabel = '';

    public string $pTotal = '';

    public string $pDown = '';

    public string $pInstallments = '12';

    public string $pReceivedOn = '';

    public string $pBalance = '';

    public string $pNote = '';

    public function mount(string $debtId): void
    {
        abort_if(auth()->user()?->isBroker(), 403);

        $this->debtId = $debtId;
        $this->debt();
    }

    private function debt(): Debt
    {
        return Debt::query()->findOrFail($this->debtId);
    }

    // ------------------------------------------------------------------ cadastro e edição

    public function openNew(): void
    {
        $this->resetValidation();
        $this->reset('pLabel', 'pTotal', 'pDown', 'pBalance', 'pNote', 'editKey');
        $this->pInstallments = '12';
        $this->pReceivedOn = now()->toDateString();
        $this->showForm = true;
    }

    public function openEdit(string $key): void
    {
        [$sim, $i] = $this->resolve($key);
        $p = $sim->proposals[$i];

        $this->resetValidation();
        $this->editKey = $key;
        $this->pLabel = (string) $p['label'];
        $this->pTotal = (string) $p['total'];
        $this->pDown = bccomp((string) ($p['down'] ?? '0'), '0', 2) > 0 ? (string) $p['down'] : '';
        $this->pInstallments = (string) $p['installments'];
        $this->pReceivedOn = (string) ($p['received_on'] ?? $sim->created_at->toDateString());
        $this->pBalance = count($sim->proposals) === 1 ? (string) $sim->balance : '';
        $this->pNote = (string) ($p['note'] ?? '');
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
    }

    public function save(): void
    {
        $this->abortIfNotWritable();

        $d = $this->validate([
            'pLabel' => ['required', 'string', 'max:40'],
            'pTotal' => ['required', 'string', 'max:20', 'regex:/^[0-9.,\s]+$/'],
            'pDown' => ['nullable', 'string', 'max:20', 'regex:/^[0-9.,\s]*$/'],
            'pInstallments' => ['required', 'integer', 'min:1', 'max:480'],
            'pReceivedOn' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'pBalance' => ['nullable', 'string', 'max:20', 'regex:/^[0-9.,\s]*$/'],
            'pNote' => ['nullable', 'string', 'max:200'],
        ], [
            'pTotal.regex' => 'Informe o valor só com números (ex.: 3000,00).',
            'pDown.regex' => 'Informe a entrada só com números (ex.: 600,00).',
            'pBalance.regex' => 'Informe o saldo só com números (ex.: 4250,00).',
            'pReceivedOn.before_or_equal' => 'A data não pode ser no futuro.',
        ], [
            'pLabel' => 'nome da proposta', 'pTotal' => 'total a pagar', 'pDown' => 'entrada', 'pInstallments' => 'parcelas',
            'pReceivedOn' => 'data em que foi recebida', 'pBalance' => 'saldo na época', 'pNote' => 'observação',
        ]);

        $total = Money::parse($d['pTotal']);
        $entrada = Money::parse($d['pDown'] ?? 0);

        if (bccomp($entrada, $total, 2) >= 0) {
            $this->addError('pDown', 'A entrada precisa ser menor que o total.');

            return;
        }

        $item = [
            'label' => $d['pLabel'], 'total' => $total, 'down' => $entrada, 'installments' => (int) $d['pInstallments'],
            'received_on' => $d['pReceivedOn'], 'note' => trim((string) $d['pNote']),
        ];
        $saldoInformado = ($d['pBalance'] ?? '') !== '' ? Money::parse($d['pBalance']) : null;

        if ($this->editKey === '') {
            $debt = $this->debt();

            if (DebtSimulation::query()->where('debt_id', $debt->id)->count() >= DebtShow::MAX_SAVED_SIMULATIONS) {
                $this->addError('pLabel', 'Esta dívida já tem '.DebtShow::MAX_SAVED_SIMULATIONS.' registros de propostas. Exclua alguns para cadastrar outra.');

                return;
            }

            DebtSimulation::create([
                'profile_id' => $debt->profile_id, 'debt_id' => $debt->id,
                // Dono e privacidade da dívida, como nas parcelas, nos reajustes e nas simulações.
                'member_id' => $debt->member_id, 'is_private' => $debt->is_private,
                'name' => $item['label'],
                // Sem saldo informado, vale o de hoje (a proposta é cadastrada quando chega, na maioria dos casos).
                'balance' => $saldoInformado ?? Money::parse($debt->current_balance),
                'proposals' => [$item],
                'created_by_user_id' => auth()->id(),
            ]);
        } else {
            [$sim, $i] = $this->resolve($this->editKey);
            $propostas = $sim->proposals;
            $propostas[$i] = $item;
            $sim->proposals = $propostas;

            if (count($propostas) === 1) {
                // Recipiente de uma proposta só: o nome acompanha a proposta e o saldo da época pode ser corrigido.
                $sim->name = $item['label'];
                $sim->balance = $saldoInformado ?? $sim->balance;
            }

            $sim->save();
        }

        $this->selected = [];
        $this->showForm = false;
        $this->dispatch('simulations-changed');
        session()->flash('proposals_status', $this->editKey === '' ? 'Proposta cadastrada.' : 'Proposta atualizada.');
    }

    public function delete(string $key): void
    {
        $this->abortIfNotWritable();
        [$sim, $i] = $this->resolve($key);

        if (count($sim->proposals) === 1) {
            $sim->delete();
        } else {
            $propostas = $sim->proposals;
            unset($propostas[$i]);
            $sim->proposals = array_values($propostas);
            $sim->save();
        }

        // As posições mudam depois de uma exclusão: a seleção antiga poderia apontar para outra proposta.
        $this->selected = [];
        $this->dispatch('simulations-changed');
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    /** Leva a proposta ao "Registrar acordo" da tela da dívida (que só existe enquanto a dívida não tem acordo). */
    public function useProposal(string $key): void
    {
        abort_unless($this->debt()->status->isUnscheduled(), 404);
        [$sim, $i] = $this->resolve($key);
        $p = $sim->proposals[$i];

        $this->dispatch('use-agreement-proposal', total: (string) $p['total'], down: (string) ($p['down'] ?? '0.00'), installments: (int) $p['installments']);
    }

    /** A tela da dívida guardou ou excluiu uma simulação: a lista precisa ler de novo. */
    #[On('simulations-changed')]
    public function refreshList(): void {}

    // ------------------------------------------------------------------ apoio

    /**
     * Acha a simulação e a posição da proposta pela chave. A busca é sempre dentro DESTA dívida (e do escopo de perfil e privacidade): chave de outra
     * dívida, de outro perfil ou inventada dá 404.
     *
     * @return array{0: DebtSimulation, 1: int}
     */
    private function resolve(string $key): array
    {
        [$simId, $i] = array_pad(explode(':', $key, 2), 2, '');

        abort_unless($simId !== '' && ctype_digit($i), 404);

        $sim = DebtSimulation::query()->where('debt_id', $this->debtId)->findOrFail($simId);
        abort_unless(isset(($sim->proposals ?? [])[(int) $i]), 404);

        return [$sim, (int) $i];
    }

    /** Quem só acompanha (familiar) nem chega aqui, mas a gravação confere de novo: o modelo recusa escrita de quem não pode. */
    private function abortIfNotWritable(): void
    {
        abort_if(auth()->user()?->isBroker(), 403);
    }

    public function render()
    {
        $debt = $this->debt();
        $propostas = DebtProposals::flatten($debt->simulations()->get());
        $marcadas = array_values(array_filter($propostas, fn (array $p) => in_array($p['key'], $this->selected, true)));

        return view('livewire.debts.debt-proposals-panel', [
            'debt' => $debt,
            'propostas' => $propostas,
            'podeUsar' => $debt->status->isUnscheduled(),
            'comparacao' => count($marcadas) >= 2 ? DebtProposals::compare($marcadas, (string) $debt->current_balance) : null,
            'editandoSimulacaoUnica' => $this->editKey !== '' && $this->singleRound(),
            'hoje' => CarbonImmutable::now()->toDateString(),
        ]);
    }

    private function singleRound(): bool
    {
        [$simId] = array_pad(explode(':', $this->editKey, 2), 1, '');
        $sim = DebtSimulation::query()->where('debt_id', $this->debtId)->find($simId);

        return $sim !== null && count($sim->proposals ?? []) === 1;
    }
}
