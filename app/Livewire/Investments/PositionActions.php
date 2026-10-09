<?php

namespace App\Livewire\Investments;

use App\Enums\TransactionType;
use App\Models\InvestmentRecord;
use App\Services\InvestmentTransactionService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Janela das operações de posição de um ativo em cotas: comprar mais, vender, atualizar a cotação e
 * informar as cotas de um ativo que foi cadastrado só pelo valor total.
 *
 * Toda a conta (preço médio, valor, foto do mês) está em InvestmentTransactionService; aqui só entram o
 * formulário, a validação e a prévia do que vai acontecer. As quatro operações mexem apenas no total do
 * patrimônio: nenhuma altera saldo de conta bancária.
 */
class PositionActions extends Component
{
    public bool $show = false;

    public ?string $investmentId = null;

    /** 'buy', 'sell', 'quote' ou 'initial'. */
    public string $action = '';

    public string $quantity = '';

    /** Preço por cota: pago (buy), recebido (sell), a cotação (quote) ou o preço médio (initial, opcional). */
    public string $price = '';

    public string $fees = '';

    public string $date = '';

    public function mount(): void
    {
        // Corretor só entra em Seguros — nenhuma tela financeira (mesmo bloqueio de InvestmentsIndex).
        abort_if(auth()->user()?->isBroker(), 403);
    }

    #[On('open-position')]
    public function open(string $id, string $action): void
    {
        if (! in_array($action, ['buy', 'sell', 'quote', 'initial'], true)) {
            return;
        }

        $investimento = InvestmentRecord::query()->findOrFail($id);

        if (! $investimento->asset_class->hasQuantity()) {
            return;
        }

        // Cada operação só faz sentido no estado certo do ativo.
        $permitido = match ($action) {
            'sell', 'quote' => $investimento->hasPosition(),
            'initial' => $investimento->needsQuantity(),
            default => true,
        };

        if (! $permitido) {
            return;
        }

        $this->reset('quantity', 'price', 'fees');
        $this->resetErrorBag();
        $this->investmentId = $investimento->id;
        $this->action = $action;
        $this->date = CarbonImmutable::today()->toDateString();
        $this->price = $action === 'quote' && $investimento->current_price !== null ? $this->plain($investimento->current_price) : '';
        $this->show = true;
    }

    public function close(): void
    {
        $this->show = false;
    }

    public function save(InvestmentTransactionService $service): void
    {
        $investimento = InvestmentRecord::query()->findOrFail($this->investmentId);
        $dados = $this->validated($investimento);
        $data = CarbonImmutable::parse($dados['date']);

        try {
            match ($this->action) {
                'buy' => $service->trade($investimento, TransactionType::Buy, (string) $dados['quantity'], (string) $dados['price'], $dados['fees'] ?? null, $data, auth()->id()),
                'sell' => $service->trade($investimento, TransactionType::Sell, (string) $dados['quantity'], (string) $dados['price'], $dados['fees'] ?? null, $data, auth()->id()),
                'quote' => $service->updateQuote($investimento, (string) $dados['price'], $data),
                'initial' => $service->setInitialPosition($investimento, (string) $dados['quantity'], $dados['price'] ?? null, $data, auth()->id()),
            };
        } catch (\InvalidArgumentException $e) {
            // A regra do serviço vira mensagem no campo certo (ex.: venda que deixaria a posição negativa na data).
            throw ValidationException::withMessages([$this->action === 'quote' ? 'price' : 'quantity' => $this->friendly($e->getMessage())]);
        }

        session()->flash('status', match ($this->action) {
            'buy' => 'Compra registrada. O preço médio foi recalculado.',
            'sell' => 'Venda registrada.',
            'quote' => 'Cotação atualizada.',
            'initial' => 'Cotas informadas. O valor atual foi mantido.',
        });

        $this->show = false;
        $this->dispatch('position-saved');
    }

    /**
     * O que vai acontecer se a pessoa salvar agora, para ela conferir antes. Nulo enquanto os campos não
     * estão preenchidos com números válidos.
     *
     * @return ?array<string, string>
     */
    public function getPreviewProperty(): ?array
    {
        if ($this->investmentId === null) {
            return null;
        }

        $investimento = InvestmentRecord::query()->find($this->investmentId);

        if ($investimento === null) {
            return null;
        }

        $qtd = $this->number($this->quantity);
        $preco = $this->number($this->price);
        $taxas = $this->number($this->fees, allowZero: true) ?? '0';
        $qtdAtual = $this->scaled($investimento->quantity);
        $pmAtual = $this->scaled($investimento->average_price);

        return match ($this->action) {
            'buy' => $qtd !== null && $preco !== null ? $this->previewBuy($qtdAtual, $pmAtual, $qtd, $preco, $taxas) : null,
            'sell' => $qtd !== null && $preco !== null ? $this->previewSell($qtdAtual, $pmAtual, $qtd, $preco, $taxas) : null,
            'quote' => $preco !== null ? $this->previewQuote($investimento, $qtdAtual, $preco) : null,
            'initial' => $qtd !== null ? $this->previewInitial($investimento, $qtd, $preco) : null,
            default => null,
        };
    }

    /** @return array<string, string> */
    private function previewBuy(string $qtdAtual, string $pmAtual, string $qtd, string $preco, string $taxas): array
    {
        $custo = bcadd($this->cents(bcmul($qtd, $preco, 6)), Money::parse($taxas), 2);
        $qtdFinal = bcadd($qtdAtual, $qtd, 6);
        $novoPm = bcdiv(bcadd(bcmul($qtdAtual, $pmAtual, 6), $custo, 6), $qtdFinal, 6);

        return [
            'Custo desta compra' => Money::format($custo),
            'Cotas depois da compra' => $this->qty($qtdFinal),
            'Preço médio atual' => bccomp($qtdAtual, '0', 6) > 0 ? $this->price6($pmAtual) : '—',
            'Novo preço médio' => $this->price6($novoPm),
        ];
    }

    /** @return array<string, string> */
    private function previewSell(string $qtdAtual, string $pmAtual, string $qtd, string $preco, string $taxas): array
    {
        $recebido = bcsub($this->cents(bcmul($qtd, $preco, 6)), Money::parse($taxas), 2);
        $custoVendido = $this->cents(bcmul($qtd, $pmAtual, 6));
        $resultado = bcsub($recebido, $custoVendido, 2);

        return [
            'Valor recebido (líquido)' => Money::format($recebido),
            'Cotas depois da venda' => bccomp($qtd, $qtdAtual, 6) > 0 ? 'mais do que você tem' : $this->qty(bcsub($qtdAtual, $qtd, 6)),
            'Preço médio (não muda)' => $this->price6($pmAtual),
            'Resultado sobre o preço médio' => (bccomp($resultado, '0', 2) >= 0 ? '+' : '').Money::format($resultado),
        ];
    }

    /** @return array<string, string> */
    private function previewQuote(InvestmentRecord $investimento, string $qtdAtual, string $preco): array
    {
        $novo = $this->cents(bcmul($qtdAtual, $preco, 6));
        $variacao = bcsub($novo, Money::parse($investimento->current_amount), 2);

        return [
            'Novo valor do ativo' => Money::format($novo),
            'Valor atual' => Money::format($investimento->current_amount),
            'Variação' => (bccomp($variacao, '0', 2) >= 0 ? '+' : '').Money::format($variacao),
        ];
    }

    /** @return array<string, string> */
    private function previewInitial(InvestmentRecord $investimento, string $qtd, ?string $preco): array
    {
        $custo = $preco !== null
            ? $this->cents(bcmul($qtd, $preco, 6))
            : Money::parse($investimento->invested_amount ?? $investimento->current_amount);

        return [
            'Custo total' => Money::format($custo),
            'Preço médio' => $this->price6(bcdiv($custo, $qtd, 6)),
            'Valor atual (mantido)' => Money::format($investimento->current_amount),
            'Cotação implícita' => $this->price6(bcdiv(Money::parse($investimento->current_amount), $qtd, 6)),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(InvestmentRecord $investimento): array
    {
        $rules = [
            'date' => ['required', 'date', 'before_or_equal:today'],
            'quantity' => ['nullable'],
            'price' => ['nullable'],
            'fees' => ['nullable', 'numeric', 'gte:0', 'regex:/^\d+(\.\d+)?$/'],
        ];

        $precoObrigatorio = in_array($this->action, ['buy', 'sell', 'quote'], true);
        $quantidadeObrigatoria = $this->action !== 'quote';

        // Só dígitos e ponto decimal: notação científica ("1e3") passaria em "numeric" e quebraria o cálculo exato.
        $rules['quantity'] = [$quantidadeObrigatoria ? 'required' : 'nullable', 'numeric', 'gt:0', 'max:999999999', 'regex:/^\d+(\.\d+)?$/'];
        $rules['price'] = [$precoObrigatorio ? 'required' : 'nullable', 'numeric', 'gt:0', 'max:999999999', 'regex:/^\d+(\.\d+)?$/'];

        if ($this->action === 'sell') {
            $rules['quantity'][] = 'lte:'.$this->scaled($investimento->quantity);
        }

        if (in_array($this->action, ['quote', 'initial'], true)) {
            $rules['fees'] = ['nullable'];
        }

        return $this->validate($rules, [
            'quantity.lte' => 'Você tem '.$this->qty($this->scaled($investimento->quantity)).' cotas. Não dá para vender mais que isso.',
        ], [
            'date' => 'data', 'quantity' => 'quantidade', 'price' => $this->action === 'initial' ? 'preço médio' : ($this->action === 'quote' ? 'cotação' : 'preço por cota'),
            'fees' => 'taxas',
        ]);
    }

    private function friendly(string $mensagem): string
    {
        return $mensagem === 'Venda maior que a posição: não é possível vender o que não se tem.'
            ? 'Nessa data a posição não tem cotas suficientes para essa venda.'
            : $mensagem;
    }

    /** Número digitado válido e maior que zero (ou zero, se permitido), ou nulo. Aceita vírgula decimal. */
    private function number(string $texto, bool $allowZero = false): ?string
    {
        $limpo = str_replace(',', '.', trim($texto));

        // Só dígitos e ponto: "1e3" é numérico para o PHP mas não para o bcmath.
        if (preg_match('/^\d+(\.\d+)?$/', $limpo) !== 1) {
            return null;
        }

        $valor = bcadd($limpo, '0', 6);

        if (bccomp($valor, '0', 6) < 0 || (! $allowZero && bccomp($valor, '0', 6) === 0)) {
            return null;
        }

        return $valor;
    }

    private function scaled(string|float|null $valor): string
    {
        return bcadd((string) ($valor ?? '0'), '0', 6);
    }

    /** Arredonda para centavos (meio para cima). */
    private function cents(string $valor): string
    {
        return bcadd($valor, '0.005', 2);
    }

    private function qty(string $valor): string
    {
        return rtrim(rtrim($valor, '0'), '.') ?: '0';
    }

    private function price6(string $valor): string
    {
        // Preço de cota: até 6 casas, sem zeros sobrando, mas nunca menos de 2.
        $texto = rtrim($valor, '0');
        $decimais = strlen(substr(strrchr($texto, '.') ?: '.', 1));

        return 'R$ '.number_format((float) $valor, max(2, $decimais), ',', '.');
    }

    private function plain(string $valor): string
    {
        $texto = rtrim(rtrim($valor, '0'), '.');

        return $texto === '' ? '0' : $texto;
    }

    public function render()
    {
        $investimento = $this->investmentId !== null ? InvestmentRecord::query()->find($this->investmentId) : null;

        return view('livewire.investments.position-actions', [
            'investimento' => $investimento,
            'preview' => $this->preview,
            'titulo' => match ($this->action) {
                'buy' => 'Comprar mais',
                'sell' => 'Vender',
                'quote' => 'Atualizar cotação',
                'initial' => 'Informar cotas',
                default => '',
            },
        ]);
    }
}
