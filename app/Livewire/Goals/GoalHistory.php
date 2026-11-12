<?php

namespace App\Livewire\Goals;

use App\Models\Goal;
use App\Models\GoalContribution;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Histórico de aportes de UM objetivo, numa janela aberta pela lista de Objetivos: registrar aporte ou retirada, a linha do tempo mês a mês (aportes do
 * mês, acumulado e % do objetivo), o ritmo real dos últimos 3 meses e a lista dos lançamentos com edição e exclusão.
 *
 * Só para objetivo sem ligação a investimento ou consórcio (`Goal::acceptsContributions`): nos outros o avanço vem do saldo do investimento ou das
 * parcelas pagas. O que o objetivo tem = saldo inicial (`current_amount`) + a soma dos lançamentos (`Goal::accumulated`), então registrar aqui já
 * muda o progresso, o quanto falta e a previsão. Retirada que deixaria o objetivo abaixo de zero é recusada.
 */
class GoalHistory extends Component
{
    #[Locked]
    public string $goalId = '';

    public bool $show = false;

    /** Lançamento em edição; vazio = registrando um novo. */
    #[Locked]
    public string $editId = '';

    /** 'aporte' ou 'retirada'. */
    public string $type = 'aporte';

    public string $amount = '';

    public string $date = '';

    public string $note = '';

    #[On('open-goal-history')]
    public function open(string $id): void
    {
        $objetivo = $this->goalFor($id);
        abort_unless($objetivo->acceptsContributions(), 404);

        $this->resetValidation();
        $this->goalId = $objetivo->id;
        $this->resetForm();
        $this->show = true;
    }

    public function close(): void
    {
        $this->show = false;
    }

    public function edit(string $id): void
    {
        $lancamento = $this->entry($id);

        $this->resetValidation();
        $this->editId = $lancamento->id;
        $this->type = $lancamento->isWithdrawal() ? 'retirada' : 'aporte';
        $this->amount = ltrim((string) $lancamento->amount, '-');
        $this->date = $lancamento->contributed_on->toDateString();
        $this->note = (string) $lancamento->note;
    }

    public function cancelEdit(): void
    {
        $this->resetValidation();
        $this->resetForm();
    }

    public function save(): void
    {
        $objetivo = $this->goal();
        abort_unless($objetivo->acceptsContributions(), 404);

        $d = $this->validate([
            'type' => ['required', 'in:aporte,retirada'],
            'amount' => ['required', 'string', 'max:20', 'regex:/^[0-9.,\s]+$/'],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:200'],
        ], [
            'amount.regex' => 'Informe o valor só com números (ex.: 500,00).',
            'date.before_or_equal' => 'A data não pode ser no futuro.',
        ], ['type' => 'tipo', 'amount' => 'valor', 'date' => 'data', 'note' => 'observação']);

        $valor = Money::parse($d['amount']);

        if (bccomp($valor, '0', 2) <= 0) {
            $this->addError('amount', 'O valor precisa ser maior que zero.');

            return;
        }

        $assinado = $d['type'] === 'retirada' ? bcsub('0', $valor, 2) : $valor;
        $existente = $this->editId !== '' ? $this->entry($this->editId) : null;

        // Quanto o objetivo teria SEM este lançamento: a retirada não pode deixá-lo abaixo de zero.
        $semEste = $existente !== null ? bcsub($objetivo->accumulated(), (string) $existente->amount, 2) : $objetivo->accumulated();

        if (bccomp(bcadd($semEste, $assinado, 2), '0', 2) < 0) {
            $this->addError('amount', 'A retirada é maior que o que o objetivo tem'.($existente !== null ? ' sem este lançamento' : ' hoje').' ('.Money::format(bccomp($semEste, '0', 2) > 0 ? $semEste : '0.00').').');

            return;
        }

        $campos = ['amount' => $assinado, 'contributed_on' => $d['date'], 'note' => trim((string) $d['note']) !== '' ? trim((string) $d['note']) : null];

        if ($existente !== null) {
            $existente->update($campos);
            $mensagem = 'Lançamento atualizado.';
        } else {
            GoalContribution::create($campos + [
                'profile_id' => $objetivo->profile_id, 'goal_id' => $objetivo->id,
                // Dono e privacidade do objetivo, como nas parcelas e simulações de dívida.
                'member_id' => $objetivo->member_id, 'is_private' => $objetivo->is_private,
                'created_by_user_id' => auth()->id(),
            ]);
            $mensagem = $d['type'] === 'retirada' ? 'Retirada registrada.' : 'Aporte registrado.';
        }

        $this->resetForm();
        $this->dispatch('goals-changed');
        session()->flash('goal_history_status', $mensagem);
    }

    public function delete(string $id): void
    {
        $this->entry($id)->delete();

        if ($this->editId === $id) {
            $this->resetForm();
        }

        $this->dispatch('goals-changed');
    }

    private function resetForm(): void
    {
        $this->reset('editId', 'amount', 'note');
        $this->type = 'aporte';
        $this->date = now()->toDateString();
    }

    private function goalFor(string $id): Goal
    {
        return Goal::query()->withContributionTotal()->findOrFail($id);
    }

    private function goal(): Goal
    {
        return $this->goalFor($this->goalId);
    }

    /** O lançamento é buscado SEMPRE dentro do objetivo aberto (e do escopo de perfil e privacidade): id de outro objetivo ou inventado dá 404. */
    private function entry(string $id): GoalContribution
    {
        return GoalContribution::query()->where('goal_id', $this->goalId)->findOrFail($id);
    }

    public function render()
    {
        if (! $this->show || $this->goalId === '') {
            return view('livewire.goals.goal-history', ['objetivo' => null]);
        }

        $objetivo = $this->goal();

        // Mês a mês, em SQL (regra 3): o total dos lançamentos de cada mês. O acumulado parte do saldo inicial do objetivo.
        $meses = GoalContribution::query()->where('goal_id', $objetivo->id)
            ->select(DB::raw('YEAR(contributed_on) as ano'), DB::raw('MONTH(contributed_on) as mes'), DB::raw('SUM(amount) as total'))
            ->groupBy('ano', 'mes')->orderBy('ano')->orderBy('mes')->get();

        $acumulado = (string) $objetivo->current_amount;
        $linhaDoTempo = [];

        foreach ($meses as $m) {
            $acumulado = bcadd($acumulado, Money::parse((string) $m->total), 2);
            $positivo = bccomp($acumulado, '0', 2) > 0 ? $acumulado : '0.00';
            $linhaDoTempo[] = [
                'label' => str_pad((string) $m->mes, 2, '0', STR_PAD_LEFT).'/'.$m->ano,
                'total' => Money::parse((string) $m->total),
                'accumulated' => $positivo,
                'percent' => min(100, Money::percentageOf($positivo, (string) $objetivo->estimated_value)),
            ];
        }

        // Ritmo real: a média dos 3 últimos meses (contando o mês de hoje), com o mês sem lançamento valendo zero.
        $inicio = CarbonImmutable::now()->startOfMonth()->subMonthsNoOverflow(2)->toDateString();
        $ultimos = Money::parse((string) GoalContribution::query()->where('goal_id', $objetivo->id)->where('contributed_on', '>=', $inicio)->sum('amount'));
        $media = bcdiv($ultimos, '3', 2);

        return view('livewire.goals.goal-history', [
            'objetivo' => $objetivo,
            'acumulado' => $objetivo->accumulated(),
            'linhaDoTempo' => array_reverse(array_slice($linhaDoTempo, -24)),
            'lancamentos' => GoalContribution::query()->where('goal_id', $objetivo->id)->orderByDesc('contributed_on')->orderByDesc('created_at')->limit(120)->get(),
            'mediaTresMeses' => $media,
            'hoje' => now()->toDateString(),
        ]);
    }
}
