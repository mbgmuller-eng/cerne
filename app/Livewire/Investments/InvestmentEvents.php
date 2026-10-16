<?php

namespace App\Livewire\Investments;

use App\Enums\TransactionType;
use App\Models\BankAccount;
use App\Models\InvestmentRecord;
use App\Services\InvestmentTransactionService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Eventos do ativo que não são compra nem venda: proventos (dividendo, JCP, amortização) e desdobramento ou grupamento de
 * cotas. Toda a conta está em InvestmentTransactionService; aqui só entram o formulário, a prévia e a validação.
 */
class InvestmentEvents extends Component
{
    public bool $show = false;

    public ?string $investmentId = null;

    /** 'income' ou 'ratio'. */
    public string $action = '';

    /** Provento: dividend, jcp ou amortization. Proporção: split ou grouping. */
    public string $type = '';

    public string $gross = '';

    public string $tax = '';

    public string $accountId = '';

    public string $from = '';

    public string $to = '';

    public string $date = '';

    public function mount(): void
    {
        // Corretor só entra em Seguros — nenhuma tela financeira (mesmo bloqueio de InvestmentsIndex).
        abort_if(auth()->user()?->isBroker(), 403);
    }

    #[On('open-event')]
    public function open(string $id, string $action): void
    {
        if (! in_array($action, ['income', 'ratio'], true)) {
            return;
        }

        $investimento = InvestmentRecord::query()->findOrFail($id);

        // Provento e proporção só fazem sentido para um ativo em cotas, com posição aberta.
        if (! $investimento->hasPosition()) {
            return;
        }

        $this->reset('gross', 'tax', 'accountId', 'from', 'to');
        $this->resetErrorBag();
        $this->investmentId = $investimento->id;
        $this->action = $action;
        $this->type = $action === 'income' ? TransactionType::Dividend->value : TransactionType::Split->value;
        $this->date = CarbonImmutable::today()->toDateString();
        $this->show = true;
    }

    public function close(): void
    {
        $this->show = false;
    }

    /** JCP sai com 15% retido na fonte: sugere o valor quando o campo está vazio. */
    public function applyJcpTax(): void
    {
        $bruto = $this->number($this->gross);

        if ($bruto !== null) {
            $this->tax = $this->cents(bcmul($bruto, '0.15', 6));
        }
    }

    public function save(InvestmentTransactionService $service): void
    {
        $investimento = InvestmentRecord::query()->findOrFail($this->investmentId);
        $tipo = TransactionType::tryFrom($this->type);
        $data = CarbonImmutable::parse($this->validated($tipo)['date']);

        try {
            if ($this->action === 'income') {
                $conta = $this->accountId !== '' ? BankAccount::query()->findOrFail($this->accountId) : null;
                $service->registerIncome($investimento, $tipo, $this->number($this->gross) ?? '0', $this->number($this->tax, true) ?? '0', $data, $conta, auth()->id());
            } else {
                $service->registerRatio($investimento, $tipo, $this->number($this->from) ?? '0', $this->number($this->to) ?? '0', $data, auth()->id());
            }
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([$this->action === 'income' ? 'tax' : 'to' => $e->getMessage()]);
        }

        session()->flash('status', $this->action === 'income'
            ? $tipo->label().' registrado.'.($this->accountId !== '' ? ' O valor líquido entrou na conta escolhida.' : '')
            : $tipo->label().' registrado. A quantidade e o preço médio foram ajustados.');

        $this->show = false;
        $this->dispatch('position-saved');
    }

    /** @return array<string, mixed> */
    private function validated(?TransactionType $tipo): array
    {
        $tiposValidos = $this->action === 'income'
            ? [TransactionType::Dividend, TransactionType::Jcp, TransactionType::Amortization]
            : [TransactionType::Split, TransactionType::Grouping];

        if ($tipo === null || ! in_array($tipo, $tiposValidos, true)) {
            throw ValidationException::withMessages(['type' => 'Escolha o tipo.']);
        }

        $decimal = ['numeric', 'gt:0', 'max:999999999', 'regex:/^\d+(\.\d+)?$/'];

        $rules = ['date' => ['required', 'date', 'before_or_equal:today'], 'accountId' => ['nullable']];

        if ($this->action === 'income') {
            $rules['gross'] = ['required', ...$decimal];
            $rules['tax'] = ['nullable', 'numeric', 'gte:0', 'regex:/^\d+(\.\d+)?$/'];
        } else {
            $rules['from'] = ['required', ...$decimal];
            $rules['to'] = ['required', ...$decimal];
        }

        // O Livewire valida o texto como digitado; vírgula decimal vira ponto antes, para "numeric" aceitar.
        foreach (['gross', 'tax', 'from', 'to'] as $campo) {
            $this->{$campo} = str_replace(',', '.', trim($this->{$campo}));
        }

        return $this->validate($rules, [], [
            'date' => 'data', 'gross' => 'valor bruto', 'tax' => 'imposto retido', 'from' => 'de', 'to' => 'para',
        ]);
    }

    /**
     * O que acontece se salvar agora, para conferir antes. Nulo até os campos terem números válidos.
     *
     * @return ?array<string, string>
     */
    public function getPreviewProperty(): ?array
    {
        $investimento = $this->investmentId !== null ? InvestmentRecord::query()->find($this->investmentId) : null;

        if ($investimento === null) {
            return null;
        }

        if ($this->action === 'income') {
            $bruto = $this->number($this->gross);
            $imposto = $this->number($this->tax, true) ?? '0';

            if ($bruto === null || bccomp($imposto, $bruto, 2) >= 0) {
                return null;
            }

            $liquido = bcsub($this->cents($bruto), $this->cents($imposto), 2);
            $linhas = ['Valor líquido' => Money::format($liquido)];

            if ($this->type === TransactionType::Amortization->value) {
                $qtd = bcadd((string) $investimento->quantity, '0', 6);
                $custo = bcmul($qtd, bcadd((string) $investimento->average_price, '0', 6), 6);
                $novo = max(0, (float) bcsub($custo, $liquido, 2));
                $linhas['Custo total'] = Money::format($custo).' → '.Money::format((string) $novo);
                $linhas['Preço médio'] = 'R$ '.number_format((float) $investimento->average_price, 2, ',', '.').' → R$ '.number_format($novo / (float) $qtd, 2, ',', '.');
            }

            return $linhas;
        }

        $de = $this->number($this->from);
        $para = $this->number($this->to);

        if ($de === null || $para === null) {
            return null;
        }

        $qtd = bcadd((string) $investimento->quantity, '0', 6);
        $final = bcdiv(bcmul($qtd, $para, 6), $de, 6);
        $custo = bcmul($qtd, bcadd((string) $investimento->average_price, '0', 6), 6);

        return [
            'Cotas' => $this->plain($qtd).' → '.$this->plain($final),
            'Preço médio' => 'R$ '.number_format((float) $investimento->average_price, 2, ',', '.').' → R$ '.number_format((float) bcdiv($custo, $final, 6), 2, ',', '.'),
            'Custo total' => Money::format($custo).' (não muda)',
        ];
    }

    /** Número digitado válido e maior que zero (ou zero, se permitido), ou nulo. Aceita vírgula decimal. */
    private function number(string $texto, bool $allowZero = false): ?string
    {
        $limpo = str_replace(',', '.', trim($texto));

        if (preg_match('/^\d+(\.\d+)?$/', $limpo) !== 1) {
            return null;
        }

        $valor = bcadd($limpo, '0', 6);

        if ($allowZero ? bccomp($valor, '0', 6) < 0 : bccomp($valor, '0', 6) <= 0) {
            return null;
        }

        return $valor;
    }

    private function cents(string $valor): string
    {
        return bcadd($valor, '0.005', 2);
    }

    private function plain(string $valor): string
    {
        return rtrim(rtrim($valor, '0'), '.') ?: '0';
    }

    /** @return Collection<int, BankAccount> */
    private function accounts(): Collection
    {
        return BankAccount::query()->orderBy('bank_name')->get();
    }

    public function render()
    {
        $investimento = $this->investmentId !== null ? InvestmentRecord::query()->find($this->investmentId) : null;

        return view('livewire.investments.investment-events', [
            'investimento' => $investimento,
            'preview' => $this->preview,
            'accounts' => $this->show ? $this->accounts() : collect(),
            'titulo' => $this->action === 'income' ? 'Registrar provento' : 'Desdobramento ou grupamento',
            'tipos' => $this->action === 'income'
                ? [TransactionType::Dividend, TransactionType::Jcp, TransactionType::Amortization]
                : [TransactionType::Split, TransactionType::Grouping],
        ]);
    }
}
