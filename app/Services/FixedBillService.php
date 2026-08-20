<?php

namespace App\Services;

use App\Enums\FixedBillPaymentStatus;
use App\Enums\Necessity;
use App\Models\BankAccount;
use App\Models\ExpenseRecord;
use App\Models\FixedBill;
use App\Models\FixedBillPayment;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class FixedBillService
{
    /**
     * Garante que existe o vencimento de uma conta numa competência.
     *
     * Idempotente pelo índice único (conta, ano, mês): o cron da hospedagem
     * compartilhada pode disparar duas vezes, e uma conta duplicada no mês
     * faria o cliente achar que deve o dobro.
     */
    public function ensurePayment(FixedBill $bill, int $year, int $month): FixedBillPayment
    {
        return FixedBillPayment::firstOrCreate(
            [
                'fixed_bill_id' => $bill->id,
                'year' => $year,
                'month' => $month,
            ],
            [
                'profile_id' => $bill->profile_id,
                'due_date' => $bill->dueDateFor($year, $month),
                'status' => FixedBillPaymentStatus::Pending,
            ],
        );
    }

    /**
     * Gera os vencimentos do mês para todas as contas ativas.
     *
     * Roda sem escopo de perfil de propósito: é uma rotina do sistema que
     * atravessa todos os clientes.
     *
     * @return int quantos vencimentos foram criados
     */
    public function generateForMonth(int $year, int $month): int
    {
        $criados = 0;

        FixedBill::withoutProfileScope()
            ->where('is_active', true)
            ->chunkById(200, function ($contas) use ($year, $month, &$criados): void {
                foreach ($contas as $conta) {
                    $antes = FixedBillPayment::withoutProfileScope()
                        ->where('fixed_bill_id', $conta->id)
                        ->where('year', $year)
                        ->where('month', $month)
                        ->exists();

                    if (! $antes) {
                        FixedBillPayment::withoutProfileScope()->create([
                            'profile_id' => $conta->profile_id,
                            'fixed_bill_id' => $conta->id,
                            'year' => $year,
                            'month' => $month,
                            'due_date' => $conta->dueDateFor($year, $month),
                            'status' => FixedBillPaymentStatus::Pending,
                        ]);
                        $criados++;
                    }
                }
            });

        return $criados;
    }

    /**
     * Marca como vencido o que passou do vencimento sem pagamento.
     *
     * @return int quantos foram marcados
     */
    public function markOverdue(?CarbonImmutable $hoje = null): int
    {
        $hoje ??= CarbonImmutable::now();

        return FixedBillPayment::withoutProfileScope()
            ->where('status', FixedBillPaymentStatus::Pending)
            ->whereDate('due_date', '<', $hoje->toDateString())
            ->update(['status' => FixedBillPaymentStatus::Overdue]);
    }

    /**
     * Registra o pagamento.
     *
     * Conta de valor variável (luz, água) EXIGE o valor informado: o
     * `amount` da conta é só estimativa, e assumir a estimativa como valor
     * pago corromperia o fluxo de caixa silenciosamente.
     *
     * Quando há conta bancária vinculada, gera também o lançamento de
     * despesa e debita o saldo — senão a conta fixa não apareceria no
     * fluxo de caixa do mês.
     */
    public function pay(
        FixedBillPayment $payment,
        ?string $amount = null,
        ?CarbonImmutable $paidAt = null,
        ?string $userId = null,
    ): FixedBillPayment {
        $bill = $payment->fixedBill;

        if ($bill->is_variable && $amount === null) {
            throw new \InvalidArgumentException(
                'Conta de valor variável exige informar o valor pago.'
            );
        }

        $valor = Money::parse($amount ?? $bill->amount);
        $paidAt ??= CarbonImmutable::now();

        return DB::transaction(function () use ($payment, $bill, $valor, $paidAt, $userId): FixedBillPayment {
            $payment->update([
                'status' => FixedBillPaymentStatus::Paid,
                'amount_paid' => $valor,
                'paid_at' => $paidAt,
            ]);

            if ($bill->bank_account_id !== null && $bill->category_id !== null) {
                ExpenseRecord::create([
                    'profile_id' => $bill->profile_id,
                    'member_id' => $bill->member_id,
                    'description' => $bill->name,
                    'necessity' => Necessity::Essential,
                    'category_id' => $bill->category_id,
                    'subcategory_id' => $bill->subcategory_id,
                    'amount' => $valor,
                    'expense_date' => $paidAt,
                    'bank_account_id' => $bill->bank_account_id,
                    'created_by_user_id' => $userId ?? $bill->profile->owner_user_id,
                ]);

                BankAccount::withoutProfileScope()
                    ->find($bill->bank_account_id)
                    ?->applyToBalance('-'.$valor);
            }

            return $payment->refresh();
        });
    }

    /** Pula o mês — conta que não veio, serviço cancelado no meio do ciclo. */
    public function skip(FixedBillPayment $payment, ?string $motivo = null): FixedBillPayment
    {
        $payment->update([
            'status' => FixedBillPaymentStatus::Skipped,
            'notes' => $motivo,
        ]);

        return $payment;
    }

    /**
     * Rotina diária completa: gera o mês corrente e marca os atrasos.
     *
     * @return array{gerados: int, vencidos: int}
     */
    public function runDailyMaintenance(?CarbonImmutable $hoje = null): array
    {
        $hoje ??= CarbonImmutable::now();

        return [
            'gerados' => $this->generateForMonth($hoje->year, $hoje->month),
            'vencidos' => $this->markOverdue($hoje),
        ];
    }
}
