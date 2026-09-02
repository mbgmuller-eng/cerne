<?php

namespace App\Services;

use App\Enums\FixedBillPaymentStatus;
use App\Enums\Necessity;
use App\Models\BankAccount;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\FixedBill;
use App\Models\FixedBillPayment;
use App\Models\ProfileMember;
use App\Models\Scopes\MemberPrivacyScope;
use App\Models\Scopes\ProfileScope;
use App\Notifications\FixedBillDueSoon;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FixedBillService
{
    /**
     * Cadastra uma conta fixa nova. A geração dos vencimentos fica pro
     * generateForMonth de sempre — chamado logo em seguida pra quem
     * cadastrou já ver o vencimento do mês corrente na hora.
     *
     * @param  array{
     *     name: string, amount: string, recurrence: \App\Enums\RecurrenceType,
     *     due_day?: ?int, due_weekday?: ?int, due_month?: ?int,
     *     member_id?: ?string, bank_account_id?: ?string, credit_card_id?: ?string,
     *     necessity?: ?Necessity, category_id?: ?string, subcategory_id?: ?string,
     *     is_variable?: bool, notes?: ?string,
     * }  $dados
     */
    public function create(array $dados): FixedBill
    {
        $bill = FixedBill::create($dados);

        $hoje = CarbonImmutable::now();
        $this->generateOccurrencesFor($bill, $hoje->year, $hoje->month);

        return $bill;
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
                    $criados += $this->generateOccurrencesFor($conta, $year, $month);
                }
            });

        return $criados;
    }

    /**
     * Gera os vencimentos de UMA conta numa competência.
     *
     * Idempotente por (conta, due_date) — não por (conta, ano, mês): conta
     * semanal tem 4-5 vencimentos no mesmo mês, cada um com sua própria
     * data. O cron da hospedagem compartilhada pode disparar duas vezes; é
     * o índice único de fixed_bill_payments que garante que isso não
     * duplica nada, esta checagem aqui é só pra não gerar a query de INSERT
     * à toa.
     */
    private function generateOccurrencesFor(FixedBill $conta, int $year, int $month): int
    {
        $criados = 0;

        foreach ($conta->occurrencesInMonth($year, $month) as $vencimento) {
            $existe = FixedBillPayment::withoutProfileScope()
                ->where('fixed_bill_id', $conta->id)
                ->where('due_date', $vencimento->toDateString())
                ->exists();

            if ($existe) {
                continue;
            }

            FixedBillPayment::withoutProfileScope()->create([
                'profile_id' => $conta->profile_id,
                'fixed_bill_id' => $conta->id,
                'year' => $vencimento->year,
                'month' => $vencimento->month,
                'due_date' => $vencimento,
                'status' => FixedBillPaymentStatus::Pending,
            ]);
            $criados++;
        }

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
                    'necessity' => $bill->necessity ?? Necessity::Essential,
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

    /**
     * Notifica quem vai ter uma conta fixa vencendo daqui a
     * `cerne.notifications.days_before_due` dias.
     *
     * @return int quantas notificações foram enviadas
     */
    public function notifyUpcomingDueDates(?CarbonImmutable $hoje = null, ?int $diasAntes = null): int
    {
        $hoje ??= CarbonImmutable::now();
        $alvo = $hoje->addDays($diasAntes ?? config('cerne.notifications.days_before_due'))->toDateString();
        $notificados = 0;

        FixedBillPayment::withoutProfileScope()
            ->where('status', FixedBillPaymentStatus::Pending)
            ->whereDate('due_date', $alvo)
            ->with(['fixedBill' => fn ($q) => $q
                ->withoutGlobalScope(ProfileScope::class)
                ->withoutGlobalScope(MemberPrivacyScope::class)])
            ->chunkById(200, function ($vencimentos) use (&$notificados): void {
                foreach ($vencimentos as $pagamento) {
                    if ($pagamento->fixedBill === null) {
                        continue;
                    }

                    foreach ($this->recipientsFor($pagamento->fixedBill) as $user) {
                        if ($this->alreadyNotifiedToday($user, FixedBillDueSoon::class, 'fixed_bill_payment_id', $pagamento->id)) {
                            continue;
                        }

                        $user->notify(FixedBillDueSoon::forPayment($pagamento));
                        $notificados++;
                    }
                }
            });

        return $notificados;
    }

    /**
     * Réplica manual da lógica de MemberPrivacyScope pra FixedBill — sem
     * ProfileContext ativo aqui (é o cron), o escopo global não tem como
     * decidir isso sozinho.
     *
     * @return Collection<int, \App\Models\User>
     */
    private function recipientsFor(FixedBill $bill): Collection
    {
        if ($bill->member_id === null || ! $bill->is_private) {
            return FinancialProfile::find($bill->profile_id)
                ?->activeMembers()->whereNotNull('user_id')->with('user')->get()
                ->pluck('user')->filter()->values() ?? collect();
        }

        return collect([ProfileMember::find($bill->member_id)?->user])->filter();
    }

    /** Evita duplicar aviso se a rotina for reexecutada manualmente no mesmo dia. */
    private function alreadyNotifiedToday(\App\Models\User $user, string $tipo, string $chave, string $id): bool
    {
        return $user->notifications()
            ->where('type', $tipo)
            ->whereJsonContains("data->{$chave}", $id)
            ->whereDate('created_at', today())
            ->exists();
    }
}
