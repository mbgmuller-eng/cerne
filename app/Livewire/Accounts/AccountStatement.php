<?php

namespace App\Livewire\Accounts;

use App\Livewire\Concerns\RequiresActiveProfile;
use App\Models\BankAccount;
use App\Models\ExpenseRecord;
use App\Models\FixedBillPayment;
use App\Models\IncomeRecord;
use App\Models\RecurringIncomeOccurrence;
use App\Services\FixedBillService;
use App\Services\RecurringIncomeService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Extrato de UMA conta, dia a dia: o que entrou e saiu e o saldo ao fim de cada dia, mais o que ainda está previsto para o mês (contas fixas e
 * receitas recorrentes pendentes), com o botão "Efetivar" que dá baixa pelo MESMO serviço das telas de origem.
 *
 * O saldo do dia é calculado DE TRÁS PARA A FRENTE a partir do saldo atual da conta: o saldo do fim do mês é o atual desfeito de tudo o que foi
 * lançado depois dele, e cada dia anterior desfaz também os lançamentos do dia seguinte. Assim o último dia sempre bate com o saldo da conta,
 * e é uma conta única (sem somar a partir de um saldo inicial que pode ter sido digitado à mão). Só entram lançamentos que movem o saldo
 * (\`affects_balance\`); compra no cartão só mexe quando a fatura é paga.
 */
#[Layout('components.layouts.app')]
class AccountStatement extends Component
{
    use RequiresActiveProfile;

    #[Locked]
    public string $accountId = '';

    #[Url]
    public ?int $ano = null;

    #[Url]
    public ?int $mes = null;

    public ?string $aviso = null;

    public function mount(BankAccount $account): void
    {
        $this->redirectOrAbortWithoutProfile();
        // Corretor só entra em Seguros — nenhuma outra tela financeira.
        abort_if(auth()->user()?->isBroker(), 403);

        $this->accountId = $account->id;
    }

    public function previousMonth(): void
    {
        $alvo = $this->referencia()->subMonth();
        $this->ano = $alvo->year;
        $this->mes = $alvo->month;
    }

    public function nextMonth(): void
    {
        $alvo = $this->referencia()->addMonth();
        $this->ano = $alvo->year;
        $this->mes = $alvo->month;
    }

    public function currentMonth(): void
    {
        $this->ano = null;
        $this->mes = null;
    }

    private function referencia(): CarbonImmutable
    {
        $hoje = CarbonImmutable::now();

        return CarbonImmutable::create($this->ano ?? $hoje->year, min(12, max(1, $this->mes ?? $hoje->month)), 1);
    }

    /** Dá baixa em uma conta fixa pendente DESTA conta (mesmo serviço da tela de Contas fixas). Conta de valor variável pede o valor lá. */
    public function efetivarConta(string $id): void
    {
        $pagamento = FixedBillPayment::query()->outstanding()->with('fixedBill')->findOrFail($id);
        abort_unless($pagamento->fixedBill?->bank_account_id === $this->accountId, 404);

        if ($pagamento->fixedBill->is_variable) {
            $this->aviso = 'Esta conta tem valor variável: informe o valor em Contas fixas.';

            return;
        }

        app(FixedBillService::class)->pay($pagamento, null, null, auth()->id());
        $this->aviso = null;
    }

    /** Dá baixa em uma receita recorrente pendente DESTA conta (mesmo serviço da tela de receitas recorrentes). */
    public function efetivarReceita(string $id): void
    {
        $ocorrencia = RecurringIncomeOccurrence::query()->outstanding()->with('recurringIncome')->findOrFail($id);
        abort_unless($ocorrencia->recurringIncome?->bank_account_id === $this->accountId, 404);

        if ($ocorrencia->recurringIncome->is_variable) {
            $this->aviso = 'Esta receita tem valor variável: informe o valor em Contas fixas.';

            return;
        }

        app(RecurringIncomeService::class)->receive($ocorrencia, null, null, auth()->id());
        $this->aviso = null;
    }

    public function render()
    {
        $conta = BankAccount::query()->findOrFail($this->accountId);
        $ref = $this->referencia();
        $hoje = CarbonImmutable::now();
        $fimDoMes = $ref->endOfMonth();

        // Tudo o que mexeu no saldo, do começo do mês para trás nunca é necessário: só o mês mostrado e o que veio DEPOIS dele (para desfazer).
        $entradas = IncomeRecord::query()->where('bank_account_id', $conta->id)->where('affects_balance', true)
            ->whereDate('received_date', '>=', $ref->toDateString())->orderBy('received_date')->with('category')->get();
        $saidas = ExpenseRecord::query()->where('bank_account_id', $conta->id)->where('affects_balance', true)
            ->whereDate('expense_date', '>=', $ref->toDateString())->orderBy('expense_date')->with('category')->get();

        // Lançamentos depois do fim do mês: desfeitos do saldo atual para chegar ao saldo do fim do mês mostrado.
        $depois = '0';
        foreach ($entradas as $e) {
            if ($e->received_date->gt($fimDoMes)) {
                $depois = bcadd($depois, $e->amount, 2);
            }
        }
        foreach ($saidas as $s) {
            if ($s->expense_date->gt($fimDoMes)) {
                $depois = bcsub($depois, $s->amount, 2);
            }
        }
        $saldoFimDoMes = bcsub(Money::parse($conta->current_balance), $depois, 2);

        // Movimentações do mês agrupadas por dia (do mais novo para o mais antigo), com o saldo ao fim de cada dia.
        $itens = collect();
        foreach ($entradas as $e) {
            if ($e->received_date->between($ref, $fimDoMes)) {
                $itens->push(['dia' => $e->received_date->toDateString(), 'titulo' => $e->description ?: $e->category->name, 'categoria' => $e->category->name, 'valor' => Money::parse($e->amount), 'sinal' => 1]);
            }
        }
        foreach ($saidas as $s) {
            if ($s->expense_date->between($ref, $fimDoMes)) {
                $itens->push(['dia' => $s->expense_date->toDateString(), 'titulo' => $s->description, 'categoria' => $s->category->name, 'valor' => Money::parse($s->amount), 'sinal' => -1]);
            }
        }

        $dias = [];
        $saldo = $saldoFimDoMes;
        foreach ($itens->groupBy('dia')->sortKeysDesc() as $dia => $doDia) {
            $dias[] = ['dia' => CarbonImmutable::parse($dia), 'itens' => $doDia->values()->all(), 'saldo' => $saldo];
            // O saldo do dia anterior desfaz o que este dia movimentou.
            foreach ($doDia as $i) {
                $saldo = $i['sinal'] === 1 ? bcsub($saldo, $i['valor'], 2) : bcadd($saldo, $i['valor'], 2);
            }
        }
        $saldoInicial = $saldo;

        // Previstos do mês: ainda não efetivados. Só faz sentido do mês de hoje em diante (mês passado com pendência é atraso, listado igual).
        $previstos = collect();
        foreach (FixedBillPayment::query()->outstanding()->forPeriod($ref->year, $ref->month)->with('fixedBill')->get() as $p) {
            if ($p->fixedBill?->bank_account_id === $conta->id) {
                $previstos->push(['tipo' => 'conta', 'id' => $p->id, 'titulo' => $p->fixedBill->name, 'vencimento' => $p->due_date, 'valor' => $p->effectiveAmount(), 'sinal' => -1, 'variavel' => (bool) $p->fixedBill->is_variable, 'atrasada' => $p->due_date->lt($hoje->startOfDay())]);
            }
        }
        foreach (RecurringIncomeOccurrence::query()->outstanding()->forPeriod($ref->year, $ref->month)->with('recurringIncome')->get() as $o) {
            if ($o->recurringIncome?->bank_account_id === $conta->id) {
                $previstos->push(['tipo' => 'receita', 'id' => $o->id, 'titulo' => $o->recurringIncome->name, 'vencimento' => $o->due_date, 'valor' => $o->effectiveAmount(), 'sinal' => 1, 'variavel' => (bool) $o->recurringIncome->is_variable, 'atrasada' => $o->due_date->lt($hoje->startOfDay())]);
            }
        }
        $previstos = $previstos->sortBy(fn (array $p) => $p['vencimento']->toDateString())->values();

        $saldoPrevisto = $saldoFimDoMes;
        foreach ($previstos as $p) {
            $saldoPrevisto = $p['sinal'] === 1 ? bcadd($saldoPrevisto, $p['valor'], 2) : bcsub($saldoPrevisto, $p['valor'], 2);
        }

        return view('livewire.accounts.account-statement', [
            'conta' => $conta,
            'referencia' => $ref,
            'ehMesAtual' => $ref->isSameMonth($hoje),
            'dias' => $dias,
            'previstos' => $previstos->all(),
            'saldoInicial' => $saldoInicial,
            'saldoFinal' => $saldoFimDoMes,
            'saldoPrevisto' => $saldoPrevisto,
            'hoje' => $hoje,
        ]);
    }
}
