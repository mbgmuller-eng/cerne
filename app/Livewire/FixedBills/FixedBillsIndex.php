<?php

namespace App\Livewire\FixedBills;

use App\Enums\FixedBillPaymentStatus;
use App\Models\FixedBill;
use App\Models\FixedBillPayment;
use App\Services\FixedBillService;
use App\Support\Money;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Contas fixas do mês.
 *
 * A tela é a face da rotina agendada: mostra o que a geração mensal criou
 * e o que a marcação de atraso já sinalizou.
 */
#[Layout('components.layouts.app')]
class FixedBillsIndex extends Component
{
    #[Url]
    public ?int $year = null;

    #[Url]
    public ?int $month = null;

    /** Valor digitado para as contas variáveis, indexado pelo id do vencimento. */
    public array $valorPago = [];

    public function mount(): void
    {
        abort_if(app(ProfileContext::class)->profile() === null, 404);

        $hoje = CarbonImmutable::now();
        $this->year ??= $hoje->year;
        $this->month ??= $hoje->month;

        // Garante que o mês visto tem seus vencimentos — o usuário pode
        // navegar para um mês futuro antes de o cron chegar nele.
        app(FixedBillService::class)->generateForMonth($this->year, $this->month);
    }

    public function previousMonth(): void
    {
        $d = CarbonImmutable::create($this->year, $this->month, 1)->subMonth();
        $this->year = $d->year;
        $this->month = $d->month;
        app(FixedBillService::class)->generateForMonth($this->year, $this->month);
    }

    public function nextMonth(): void
    {
        $d = CarbonImmutable::create($this->year, $this->month, 1)->addMonth();
        $this->year = $d->year;
        $this->month = $d->month;
        app(FixedBillService::class)->generateForMonth($this->year, $this->month);
    }

    public function pay(string $paymentId, FixedBillService $service): void
    {
        $pagamento = FixedBillPayment::with('fixedBill')->findOrFail($paymentId);

        $valor = $pagamento->fixedBill->is_variable
            ? ($this->valorPago[$paymentId] ?? null)
            : null;

        if ($pagamento->fixedBill->is_variable && ! $valor) {
            $this->addError('valorPago.'.$paymentId, 'Informe o valor pago.');

            return;
        }

        $service->pay($pagamento, $valor ? Money::parse($valor) : null, null, auth()->id());

        unset($this->valorPago[$paymentId]);
        session()->flash('status', 'Pagamento registrado.');
    }

    public function skip(string $paymentId, FixedBillService $service): void
    {
        $service->skip(FixedBillPayment::findOrFail($paymentId), 'Pulada pelo usuário');

        session()->flash('status', 'Conta marcada como pulada.');
    }

    /** @return Collection<int, FixedBillPayment> */
    public function getPaymentsProperty(): Collection
    {
        return FixedBillPayment::query()
            ->forPeriod($this->year, $this->month)
            ->with('fixedBill.category', 'fixedBill.member')
            ->get()
            // Ordena por vencimento; as pendentes primeiro dentro do dia.
            ->sortBy([
                fn ($a, $b) => $a->due_date <=> $b->due_date,
                fn ($a, $b) => $a->isPaid() <=> $b->isPaid(),
            ])
            ->values();
    }

    public function getTotalProperty(): string
    {
        return Money::sum($this->payments->map(fn (FixedBillPayment $p) => $p->effectiveAmount()));
    }

    public function getOutstandingProperty(): string
    {
        return Money::sum(
            $this->payments
                ->filter(fn (FixedBillPayment $p) => $p->status->isOutstanding())
                ->map(fn (FixedBillPayment $p) => $p->effectiveAmount())
        );
    }

    public function render()
    {
        return view('livewire.fixed-bills.fixed-bills-index', [
            'payments' => $this->payments,
            'total' => $this->total,
            'outstanding' => $this->outstanding,
            'periodLabel' => CarbonImmutable::create($this->year, $this->month, 1)->translatedFormat('F \d\e Y'),
            'hasBills' => FixedBill::query()->active()->exists(),
        ]);
    }
}
