<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    /**
     * Garante que existe a fatura de uma competência, criando se faltar.
     *
     * Idempotente por construção: o índice único (cartão, ano, mês) impede
     * duplicata mesmo se o job rodar duas vezes — o que acontece quando o
     * cron da hospedagem compartilhada dispara concorrente.
     */
    public function ensureInvoice(CreditCard $card, int $year, int $month): CreditCardInvoice
    {
        return CreditCardInvoice::firstOrCreate(
            [
                'credit_card_id' => $card->id,
                'year' => $year,
                'month' => $month,
            ],
            [
                'profile_id' => $card->profile_id,
                'closing_date' => $card->closingDateFor($year, $month),
                'due_date' => $card->dueDateFor($year, $month),
                'total_amount' => '0.00',
                'status' => InvoiceStatus::Open,
            ],
        );
    }

    /** A fatura em que uma compra desta data cai. */
    public function invoiceForPurchase(CreditCard $card, CarbonImmutable $purchaseDate): CreditCardInvoice
    {
        [$year, $month] = $card->billingPeriodFor($purchaseDate);

        return $this->ensureInvoice($card, $year, $month);
    }

    /**
     * Recalcula o total a partir dos lançamentos vinculados.
     *
     * A soma vem do banco, não de um contador incremental: um contador
     * dessincroniza silenciosamente na primeira exclusão de lançamento que
     * escapar do fluxo normal, e ninguém percebe até a fatura não bater.
     */
    public function recalculateTotal(CreditCardInvoice $invoice): CreditCardInvoice
    {
        $total = DB::table('expense_records')
            ->where('credit_card_invoice_id', $invoice->id)
            ->sum('amount');

        $invoice->update(['total_amount' => Money::parse($total ?? 0)]);

        return $invoice;
    }

    /**
     * Fecha a fatura cuja data de fechamento já passou. Compras a partir
     * daqui vão para o ciclo seguinte.
     */
    public function close(CreditCardInvoice $invoice): CreditCardInvoice
    {
        if ($invoice->status === InvoiceStatus::Open) {
            $invoice->update(['status' => InvoiceStatus::Closed]);
        }

        return $invoice;
    }

    /**
     * Paga a fatura e debita a conta escolhida.
     *
     * Em transação: uma fatura marcada como paga sem o débito da conta
     * inflaria o patrimônio do cliente — exatamente o tipo de erro que
     * ninguém confere manualmente.
     */
    public function pay(
        CreditCardInvoice $invoice,
        BankAccount $account,
        ?string $amount = null,
        ?CarbonImmutable $paidAt = null,
    ): CreditCardInvoice {
        $amount = Money::parse($amount ?? $invoice->total_amount);
        $paidAt ??= CarbonImmutable::now();

        return DB::transaction(function () use ($invoice, $account, $amount, $paidAt): CreditCardInvoice {
            $invoice->update([
                'status' => InvoiceStatus::Paid,
                'paid_at' => $paidAt,
                'paid_amount' => $amount,
                'paid_from_account_id' => $account->id,
            ]);

            $account->applyToBalance('-'.$amount);

            return $invoice->refresh();
        });
    }

    /**
     * Rotina diária: fecha o que venceu o fechamento e marca como vencida
     * a fatura não paga que passou do vencimento.
     *
     * @return array{closed: int, overdue: int}
     */
    public function runDailyMaintenance(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now();

        $closed = CreditCardInvoice::withoutProfileScope()
            ->where('status', InvoiceStatus::Open)
            ->whereDate('closing_date', '<', $today)
            ->update(['status' => InvoiceStatus::Closed]);

        $overdue = CreditCardInvoice::withoutProfileScope()
            ->where('status', InvoiceStatus::Closed)
            ->whereDate('due_date', '<', $today)
            ->update(['status' => InvoiceStatus::Overdue]);

        return ['closed' => $closed, 'overdue' => $overdue];
    }
}
