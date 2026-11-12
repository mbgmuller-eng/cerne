<?php

namespace App\Services;

use App\Enums\FixedBillPaymentStatus;
use App\Enums\Necessity;
use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
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
use Illuminate\Database\UniqueConstraintViolationException;
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

        // Conta no cartão: o que já venceu neste mês entra na fatura agora, sem esperar a rotina da madrugada.
        $this->postDueCardCharges($hoje, $bill);

        return $bill;
    }

    /**
     * Edita uma conta fixa existente.
     *
     * Não mexe em vencimento já gerado: `amount`/`category_id`/etc. são
     * lidos ao vivo da conta na hora de pay() (ver ali), então a edição já
     * vale pro próximo pagamento sem nada extra aqui. `due_day`/
     * `recurrence` só valem pra vencimentos futuros ainda não gerados —
     * mudar o dia não reagenda o vencimento deste mês que já existe.
     *
     * @param  array<string, mixed>  $dados
     */
    public function update(FixedBill $bill, array $dados): FixedBill
    {
        $bill->update($dados);

        // Conta que passou a ser de cartão: o que já venceu e está em aberto entra na fatura agora.
        $this->postDueCardCharges(null, $bill->refresh());

        return $bill;
    }

    /**
     * "Exclui" a conta fixa — nunca um DELETE de verdade: fixed_bill_payments
     * tem cascadeOnDelete, apagar a conta apagaria o histórico de
     * pagamentos junto, inclusive o que já foi pago de verdade. Marca
     * is_active=false (some da geração futura, ver generateForMonth) e
     * pula quem ainda está em aberto (Pending/Overdue) — não faz sentido
     * continuar cobrando por uma conta que a pessoa acabou de excluir. O
     * que já foi PAGO fica intocado: é fluxo de caixa real que já aconteceu.
     */
    public function deactivate(FixedBill $bill): void
    {
        DB::transaction(function () use ($bill): void {
            $bill->update(['is_active' => false]);

            FixedBillPayment::withoutProfileScope()
                ->where('fixed_bill_id', $bill->id)
                ->whereIn('status', [FixedBillPaymentStatus::Pending, FixedBillPaymentStatus::Overdue])
                ->get()
                ->each(fn (FixedBillPayment $p) => $this->skip($p, 'Conta fixa excluída'));
        });
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

            try {
                FixedBillPayment::withoutProfileScope()->create([
                    'profile_id' => $conta->profile_id,
                    'fixed_bill_id' => $conta->id,
                    'year' => $vencimento->year,
                    'month' => $vencimento->month,
                    'due_date' => $vencimento,
                    'status' => FixedBillPaymentStatus::Pending,
                ]);
                $criados++;
            } catch (UniqueConstraintViolationException) {
                // Outra execução (o cron, ou a mesma tela aberta em duas abas) criou entre a conferência e a gravação: o índice único segurou, que é o que a idempotência pede.
            }
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

            $cartao = $this->activeCard($bill);

            if ($cartao !== null && $bill->category_id !== null) {
                // Conta no cartão: o valor vai para a fatura (sem mexer em saldo nenhum; o dinheiro sai quando a fatura for paga).
                $this->launchOnCard($bill, $cartao, $valor, CarbonImmutable::parse($paidAt)->startOfDay(), $userId);
            } elseif ($bill->bank_account_id !== null && $bill->category_id !== null) {
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
                    // A despesa gerada herda a privacidade da conta fixa: sem isto, quem marcou "Ocultar do meu cônjuge" via a despesa aparecer no fluxo dele ao pagar.
                    'is_private' => $bill->is_private,
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

        $gerados = $this->generateForMonth($hoje->year, $hoje->month);

        // Antes de marcar atraso: a conta no cartão que vence hoje é lançada na fatura e fica paga, não atrasada.
        $this->postDueCardCharges($hoje);

        return [
            'gerados' => $gerados,
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

                    // Quem acompanha as finanças da casa (os filhos): só o que não é privado e das pessoas que cada um acompanha.
                    $notificados += app(FamilyNotifier::class)->billDueSoon($pagamento);
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

    /**
     * Lança na fatura do cartão as contas fixas de CARTÃO que já venceram e seguem em aberto.
     *
     * Regras que não se quebram:
     *  - Só conta de valor FIXO: a de valor variável (luz, água) espera a pessoa informar o valor em "Pagar" (que também vai para a fatura).
     *  - Idempotente por UPDATE condicional: o vencimento é "reivindicado" (em aberto -> pago) e só quem conseguiu muda a linha lança a compra.
     *    O cron, a criação da conta e outra aba podem chegar juntos; `exists()` seguido de criação perderia a corrida (regra 4).
     *  - Cada vencimento é lançado na fatura que recebe compras daquela data (a "vigente" para ele); se essa fatura já não aceita compra
     *    (fechada, paga, vencida), cai na do ciclo seguinte, como uma compra feita agora cairia.
     *  - Um vencimento que falha (cartão sem fatura possível, erro de banco) não derruba os outros: fica em aberto para a próxima rodada.
     *
     * @return int quantos vencimentos foram lançados
     */
    public function postDueCardCharges(?CarbonImmutable $hoje = null, ?FixedBill $apenas = null): int
    {
        $hoje ??= CarbonImmutable::now();
        $lancados = 0;

        $query = FixedBill::withoutProfileScope()
            ->where('is_active', true)
            ->where('is_variable', false)
            ->whereNotNull('credit_card_id')
            ->whereNotNull('category_id');

        if ($apenas !== null) {
            $query->whereKey($apenas->id);
        }

        $query->chunkById(200, function ($contas) use ($hoje, &$lancados): void {
            foreach ($contas as $conta) {
                $cartao = $this->activeCard($conta);

                if ($cartao === null) {
                    continue;
                }

                $vencimentos = FixedBillPayment::withoutProfileScope()
                    ->where('fixed_bill_id', $conta->id)
                    ->whereIn('status', [FixedBillPaymentStatus::Pending->value, FixedBillPaymentStatus::Overdue->value])
                    ->whereDate('due_date', '<=', $hoje->toDateString())
                    ->orderBy('due_date')
                    ->get();

                foreach ($vencimentos as $vencimento) {
                    try {
                        if ($this->postCardCharge($conta, $vencimento, $cartao)) {
                            $lancados++;
                        }
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            }
        });

        return $lancados;
    }

    private function postCardCharge(FixedBill $conta, FixedBillPayment $vencimento, CreditCard $cartao): bool
    {
        return DB::transaction(function () use ($conta, $vencimento, $cartao): bool {
            $valor = Money::parse($conta->amount);
            $data = CarbonImmutable::parse($vencimento->due_date)->startOfDay();

            $reivindicou = FixedBillPayment::withoutProfileScope()
                ->whereKey($vencimento->id)
                ->whereIn('status', [FixedBillPaymentStatus::Pending->value, FixedBillPaymentStatus::Overdue->value])
                ->update([
                    'status' => FixedBillPaymentStatus::Paid->value,
                    'amount_paid' => $valor,
                    'paid_at' => $data->toDateTimeString(),
                ]) === 1;

            if (! $reivindicou) {
                return false;
            }

            $this->launchOnCard($conta, $cartao, $valor, $data, null);

            return true;
        });
    }

    /** O cartão da conta fixa, quando está em uso: existe, está ativo e é do mesmo perfil. Sem escopo de perfil porque a rotina do cron não tem perfil ativo. */
    private function activeCard(FixedBill $conta): ?CreditCard
    {
        if ($conta->credit_card_id === null) {
            return null;
        }

        $cartao = CreditCard::withoutProfileScope()->find($conta->credit_card_id);

        return $cartao !== null && $cartao->is_active && $cartao->profile_id === $conta->profile_id ? $cartao : null;
    }

    /** Cria a compra na fatura do cartão. A despesa herda a privacidade da conta fixa e o total da fatura é refeito pela soma no banco. */
    private function launchOnCard(FixedBill $conta, CreditCard $cartao, string $valor, CarbonImmutable $data, ?string $userId): void
    {
        $fatura = $this->invoiceForCharge($cartao, $data);

        ExpenseRecord::create([
            'profile_id' => $conta->profile_id,
            'member_id' => $conta->member_id,
            'description' => $conta->name,
            'necessity' => $conta->necessity ?? Necessity::Essential,
            'category_id' => $conta->category_id,
            'subcategory_id' => $conta->subcategory_id,
            'amount' => $valor,
            'expense_date' => $data,
            'credit_card_id' => $cartao->id,
            'credit_card_invoice_id' => $fatura->id,
            'is_private' => $conta->is_private,
            'created_by_user_id' => $userId ?? $conta->profile->owner_user_id,
        ]);

        app(InvoiceService::class)->recalculateTotal($fatura);
    }

    /** A fatura que recebe a cobrança desta data; se ela já não aceita compra, a do ciclo seguinte. */
    private function invoiceForCharge(CreditCard $cartao, CarbonImmutable $data): CreditCardInvoice
    {
        $faturas = app(InvoiceService::class);
        $fatura = $faturas->invoiceForPurchase($cartao, $data);

        for ($i = 0; $i < 3 && ! $fatura->status->acceptsNewExpenses(); $i++) {
            $proxima = CarbonImmutable::create($fatura->year, $fatura->month, 1)->addMonth();
            $fatura = $faturas->ensureInvoice($cartao, $proxima->year, $proxima->month);
        }

        return $fatura;
    }
}
