<?php

namespace App\Services;

use App\Enums\DebtInstallmentStatus;
use App\Enums\FixedBillPaymentStatus;
use App\Enums\RecurrenceType;
use App\Models\Debt;
use App\Models\DebtInstallment;
use App\Models\ExpenseRecord;
use App\Models\FixedBill;
use App\Models\FixedBillPayment;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A dívida (financiamento, empréstimo, consórcio, acordo) aparece sozinha em Contas fixas, para ninguém cadastrar duas vezes.
 *
 * A conta fixa de uma dívida é um ESPELHO das parcelas: cada vencimento dela é UMA parcela (`fixed_bill_payments.debt_installment_id`), com o
 * valor e a data da parcela real (SAC, reajuste e contemplação mudam o valor). O dinheiro sai uma vez só: quem paga é a parcela
 * (`FixedBillService::pay` chama `DebtService::pay`), e o vencimento da conta fixa só acompanha. Pagar pela dívida também baixa o vencimento, e
 * desfazer o pagamento o reabre. Quando a última parcela é paga, a conta é encerrada sozinha (`auto_ended_at`).
 *
 * Idempotente por índice único (regra 4): `fixed_bills.debt_id` (uma conta por dívida) e `fixed_bill_payments.debt_installment_id` (um
 * vencimento por parcela). Roda no cadastro e nas mudanças da dívida (`DebtService`) e na rotina diária, sem contexto de perfil: por isso
 * lê sem os escopos e carimba o `profile_id` da dívida.
 *
 * Conta fixa que já existia e é da mesma dívida (mesmo nome ou credor, ou o mesmo valor no mesmo dia) é VINCULADA em vez de duplicada.
 */
class DebtFixedBillSync
{
    /** @var array<string, bool> evita a recursão: ligar uma despesa à parcela chama o `DebtService`, que sincroniza de novo. */
    private array $emAndamento = [];

    /**
     * @return array{acao: string, conta: ?FixedBill}  acao: criada, vinculada, atualizada, encerrada, removida (a pessoa a excluiu) ou nenhuma
     */
    public function sync(Debt $divida): array
    {
        if (isset($this->emAndamento[$divida->id])) {
            return ['acao' => 'nenhuma', 'conta' => null];
        }

        $this->emAndamento[$divida->id] = true;

        try {
            return $this->run($divida->id);
        } finally {
            unset($this->emAndamento[$divida->id]);
        }
    }

    /**
     * Rotina diária e comando: todas as dívidas em andamento, mais as que já têm conta (para encerrar a que acabou).
     *
     * @return array<string, int>  contagem por ação
     */
    public function syncAll(?callable $porDivida = null): array
    {
        $contagem = [];

        Debt::withoutGlobalScopes()
            ->where(fn ($q) => $q->where('status', \App\Enums\DebtStatus::Active->value)->orWhereIn('id', FixedBill::withoutGlobalScopes()->whereNotNull('debt_id')->where('is_active', true)->select('debt_id')))
            ->chunkById(100, function ($dividas) use (&$contagem, $porDivida): void {
                foreach ($dividas as $divida) {
                    try {
                        $r = $this->sync($divida);
                    } catch (\Throwable $e) {
                        // Uma dívida que falha não derruba as outras.
                        report($e);
                        $r = ['acao' => 'erro', 'conta' => null];
                    }

                    $contagem[$r['acao']] = ($contagem[$r['acao']] ?? 0) + 1;

                    if ($porDivida !== null) {
                        $porDivida($divida, $r);
                    }
                }
            });

        return $contagem;
    }

    /** A dívida foi excluída: a conta fixa dela deixa de gerar vencimento (o que já foi pago fica). */
    public function release(Debt $divida): void
    {
        $conta = FixedBill::withoutGlobalScopes()->where('debt_id', $divida->id)->first();

        if ($conta !== null) {
            $this->end($conta, 'Dívida excluída');
        }
    }

    /**
     * Os vencimentos da conta numa competência: uma por parcela em aberto que vence nela. Chamado ao abrir o mês em Contas fixas e pela rotina.
     */
    public function generateMonth(FixedBill $conta, int $year, int $month): int
    {
        $parcelas = DebtInstallment::withoutGlobalScopes()
            ->where('debt_id', $conta->debt_id)
            ->where('status', DebtInstallmentStatus::Open)
            ->whereYear('due_date', $year)->whereMonth('due_date', $month)
            ->get();

        $criados = 0;

        foreach ($parcelas as $parcela) {
            $existe = FixedBillPayment::withoutGlobalScopes()->where('debt_installment_id', $parcela->id)->exists();

            if (! $existe && $this->createOccurrence($conta, $parcela)) {
                $criados++;
            }
        }

        return $criados;
    }

    /** O vencimento já foi pago pela parcela: a conta fixa só acompanha (sem criar despesa nem mexer no saldo). */
    public function settle(FixedBillPayment $vencimento, DebtInstallment $parcela): FixedBillPayment
    {
        if (! $vencimento->status->isOutstanding()) {
            return $vencimento;
        }

        $campos = [
            'status' => FixedBillPaymentStatus::Paid,
            'amount_paid' => $parcela->paid_amount ?? $parcela->amount,
            'paid_at' => $parcela->paid_on ?? now(),
        ];

        try {
            $vencimento->update($campos + ['expense_id' => $parcela->expense_id]);
        } catch (UniqueConstraintViolationException) {
            // A despesa já baixa outro vencimento (uma conciliação antiga): este fica pago sem a ligação.
            $vencimento->update($campos);
        }

        return $vencimento->refresh();
    }

    /** O pagamento da parcela foi desfeito: o vencimento volta a ficar em aberto. */
    public function reopen(DebtInstallment $parcela): void
    {
        $vencimento = FixedBillPayment::withoutGlobalScopes()->where('debt_installment_id', $parcela->id)->first();

        if ($vencimento === null || $vencimento->status !== FixedBillPaymentStatus::Paid) {
            return;
        }

        $atrasado = $parcela->due_date->lt(CarbonImmutable::today());

        $vencimento->update([
            'status' => $atrasado ? FixedBillPaymentStatus::Overdue : FixedBillPaymentStatus::Pending,
            'amount_paid' => null,
            'paid_at' => null,
            'expense_id' => null,
        ]);
    }

    // ------------------------------------------------------------------ o trabalho

    /** @return array{acao: string, conta: ?FixedBill} */
    private function run(string $dividaId): array
    {
        $divida = Debt::withoutGlobalScopes()->find($dividaId);

        if ($divida === null) {
            return ['acao' => 'nenhuma', 'conta' => null];
        }

        $conta = FixedBill::withoutGlobalScopes()->where('debt_id', $divida->id)->first();

        // A pessoa tirou a conta da lista de propósito: fica fora (só a que o Cerne encerrou volta, se a dívida voltar a ter parcela).
        if ($conta !== null && ! $conta->is_active && $conta->auto_ended_at === null) {
            return ['acao' => 'removida', 'conta' => $conta];
        }

        $abertas = DebtInstallment::withoutGlobalScopes()->where('debt_id', $divida->id)->where('status', DebtInstallmentStatus::Open)->orderBy('due_date')->orderBy('number')->get();

        if ($abertas->isEmpty()) {
            if ($conta === null) {
                return ['acao' => 'nenhuma', 'conta' => null];
            }

            $this->reconcile($conta, $divida);
            $encerrada = $conta->is_active;
            $this->end($conta, 'Dívida quitada');

            return ['acao' => $encerrada ? 'encerrada' : 'nenhuma', 'conta' => $conta->refresh()];
        }

        $proxima = $abertas->first();
        $acao = 'atualizada';

        if ($conta === null) {
            $candidata = $this->candidate($divida, $proxima);

            if ($candidata !== null) {
                $conta = $this->link($candidata, $divida);
                $acao = 'vinculada';
            }

            if ($conta === null) {
                $conta = $this->create($divida, $proxima);
                $acao = 'criada';
            }
        }

        $campos = [
            'name' => $divida->name,
            'amount' => $proxima->amount,
            'due_day' => $proxima->due_date->day,
            'member_id' => $divida->member_id,
            'is_private' => (bool) $divida->is_private,
            'necessity' => $divida->necessity,
            'category_id' => $divida->category_id,
            'subcategory_id' => $divida->subcategory_id,
            'bank_account_id' => $divida->bank_account_id,
            'is_variable' => false,
        ];

        // Encerrada pelo Cerne e a dívida voltou a ter parcela em aberto (pagamento desfeito): volta a valer.
        if (! $conta->is_active) {
            $campos += ['is_active' => true, 'auto_ended_at' => null];
        }

        $conta->fill($campos);
        $mudou = $conta->isDirty();
        $conta->save();

        $this->reconcile($conta, $divida);

        // Os vencimentos do mês de hoje já aparecem na hora, sem esperar a rotina da madrugada.
        $hoje = CarbonImmutable::today();
        $this->generateMonth($conta, $hoje->year, $hoje->month);

        return ['acao' => $acao === 'atualizada' && ! $mudou ? 'nenhuma' : $acao, 'conta' => $conta->refresh()];
    }

    private function create(Debt $divida, DebtInstallment $proxima): FixedBill
    {
        try {
            return FixedBill::withoutGlobalScopes()->create([
                'profile_id' => $divida->profile_id,
                'debt_id' => $divida->id,
                'member_id' => $divida->member_id,
                'name' => $divida->name,
                'amount' => $proxima->amount,
                'recurrence' => RecurrenceType::Monthly,
                'due_day' => $proxima->due_date->day,
                'bank_account_id' => $divida->bank_account_id,
                'necessity' => $divida->necessity,
                'category_id' => $divida->category_id,
                'subcategory_id' => $divida->subcategory_id,
                'is_variable' => false,
                'is_active' => true,
                'is_private' => (bool) $divida->is_private,
            ]);
        } catch (UniqueConstraintViolationException) {
            // O cadastro, a rotina e outra aba chegaram juntos: o índice único segurou, e a conta que já nasceu é a que vale.
            return FixedBill::withoutGlobalScopes()->where('debt_id', $divida->id)->firstOrFail();
        }
    }

    /** Liga uma conta fixa que já existia à dívida (sem duplicar). Privacidade: nunca fica menos oculta do que as duas eram. */
    private function link(FixedBill $conta, Debt $divida): ?FixedBill
    {
        try {
            $conta->update(['debt_id' => $divida->id, 'is_private' => $conta->is_private || $divida->is_private]);
        } catch (UniqueConstraintViolationException) {
            return FixedBill::withoutGlobalScopes()->where('debt_id', $divida->id)->first();
        }

        return $conta->refresh();
    }

    /**
     * A conta fixa que já existia e é desta dívida: mensal, ativa, de conta (não de cartão), ainda sem dívida, do mesmo perfil, e com o mesmo
     * nome ou credor, ou o mesmo valor no mesmo dia da parcela. Havendo várias, vale a de maior pontuação (depois, a mais antiga).
     */
    public function candidate(Debt $divida, DebtInstallment $proxima): ?FixedBill
    {
        return $this->findCandidate($divida->profile_id, $divida->name, $divida->creditor, (string) $proxima->amount, $proxima->due_date->day);
    }

    /** O mesmo teste, com o que a pessoa já digitou (o "Ao salvar" do formulário da dívida ainda não tem model). */
    public function findCandidate(string $perfilId, string $nomeDaDivida, ?string $credorDaDivida, ?string $valorDaParcela, int $diaDaParcela): ?FixedBill
    {
        $nome = $this->normalize($nomeDaDivida);
        $credor = $this->normalize((string) $credorDaDivida);

        $pontuada = FixedBill::withoutGlobalScopes()
            ->where('profile_id', $perfilId)
            ->whereNull('debt_id')
            ->where('is_active', true)
            ->where('recurrence', RecurrenceType::Monthly->value)
            ->whereNull('credit_card_id')
            ->orderBy('created_at')
            ->get()
            ->map(function (FixedBill $c) use ($nome, $credor, $valorDaParcela, $diaDaParcela): array {
                $n = $this->normalize($c->name);
                $pontos = 0;

                if (strlen($n) >= 4 && strlen($nome) >= 4 && (str_contains($n, $nome) || str_contains($nome, $n))) {
                    $pontos += 2;
                }

                if (strlen($credor) >= 4 && str_contains($n, $credor)) {
                    $pontos += 2;
                }

                if ($valorDaParcela !== null && $c->due_day === $diaDaParcela && bccomp((string) $c->amount, $valorDaParcela, 2) === 0) {
                    $pontos += 2;
                }

                return ['conta' => $c, 'pontos' => $pontos];
            })
            ->filter(fn (array $x) => $x['pontos'] >= 2)
            ->sortByDesc('pontos')
            ->first();

        return $pontuada['conta'] ?? null;
    }

    private function normalize(string $texto): string
    {
        return preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($texto))) ?? '';
    }

    /**
     * Põe os vencimentos de acordo com as parcelas: o que a parcela mudou (data, valor), o que ela fechou (paga, pulada) e o que não tinha ligação
     * (vencimento de uma conta que já existia) é ligado à parcela do mesmo mês.
     */
    private function reconcile(FixedBill $conta, Debt $divida): void
    {
        /** @var Collection<string, DebtInstallment> $parcelas */
        $parcelas = DebtInstallment::withoutGlobalScopes()->where('debt_id', $divida->id)->get()->keyBy('id');
        $vencimentos = FixedBillPayment::withoutGlobalScopes()->where('fixed_bill_id', $conta->id)->get();

        foreach ($vencimentos as $v) {
            if ($v->debt_installment_id === null || ! $v->status->isOutstanding()) {
                continue;
            }

            $parcela = $parcelas->get($v->debt_installment_id);

            match (true) {
                $parcela === null => $v->update(['status' => FixedBillPaymentStatus::Skipped, 'notes' => 'Parcela removida da dívida']),
                $parcela->status === DebtInstallmentStatus::Open => $this->follow($v, $parcela),
                $parcela->status === DebtInstallmentStatus::Paid => $this->settle($v, $parcela),
                default => $v->update(['status' => FixedBillPaymentStatus::Skipped, 'notes' => 'Parcela pulada na dívida']),
            };
        }

        // Vencimentos que já existiam antes da ligação (sem parcela): cada um se liga à parcela em aberto do mesmo mês.
        $soltos = $vencimentos->filter(fn (FixedBillPayment $v) => $v->debt_installment_id === null);

        if ($soltos->isEmpty()) {
            return;
        }

        $ligadas = FixedBillPayment::withoutGlobalScopes()->whereNotNull('debt_installment_id')->whereIn('debt_installment_id', $parcelas->keys())->pluck('debt_installment_id')->all();

        foreach ($parcelas->where('status', DebtInstallmentStatus::Open) as $parcela) {
            if (in_array($parcela->id, $ligadas, true)) {
                continue;
            }

            $solto = $soltos->first(fn (FixedBillPayment $v) => $v->debt_installment_id === null && $v->year === $parcela->due_date->year && $v->month === $parcela->due_date->month);

            if ($solto === null) {
                continue;
            }

            if ($solto->status->isOutstanding()) {
                $solto->debt_installment_id = $parcela->id;
                $solto->expected_amount = $parcela->amount;
                $solto->due_date = $parcela->due_date;
                $this->saveQuietly($solto);
            } elseif ($solto->status === FixedBillPaymentStatus::Paid && $solto->expense_id !== null) {
                // Já pago pelo "Pagar" da conta antiga: a parcela fica paga pela mesma despesa, em vez de a dívida cobrar o mês de novo.
                $this->adoptPaid($solto, $parcela);
            }
        }
    }

    private function follow(FixedBillPayment $v, DebtInstallment $parcela): void
    {
        $v->expected_amount = $parcela->amount;
        $v->due_date = $parcela->due_date;
        $v->year = $parcela->due_date->year;
        $v->month = $parcela->due_date->month;

        if ($v->isDirty()) {
            $this->saveQuietly($v);
        }
    }

    private function saveQuietly(FixedBillPayment $v): void
    {
        try {
            $v->save();
        } catch (UniqueConstraintViolationException) {
            // Já existe um vencimento desta conta nessa data: o que ficou é o que vale.
        }
    }

    private function adoptPaid(FixedBillPayment $v, DebtInstallment $parcela): void
    {
        $despesa = ExpenseRecord::withoutGlobalScopes()->find($v->expense_id);

        if ($despesa === null) {
            return;
        }

        try {
            app(DebtService::class)->linkExpense($parcela, $despesa);
            $v->update(['debt_installment_id' => $parcela->id, 'expected_amount' => $parcela->amount]);
        } catch (InvalidArgumentException|UniqueConstraintViolationException) {
            // A despesa já paga outra parcela: este vencimento fica como estava.
        }
    }

    private function createOccurrence(FixedBill $conta, DebtInstallment $parcela): bool
    {
        try {
            FixedBillPayment::withoutGlobalScopes()->create([
                'profile_id' => $conta->profile_id,
                'fixed_bill_id' => $conta->id,
                'debt_installment_id' => $parcela->id,
                'year' => $parcela->due_date->year,
                'month' => $parcela->due_date->month,
                'due_date' => $parcela->due_date,
                'expected_amount' => $parcela->amount,
                'status' => $parcela->due_date->lt(CarbonImmutable::today()) ? FixedBillPaymentStatus::Overdue : FixedBillPaymentStatus::Pending,
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            // Outra execução criou este vencimento entre a conferência e a gravação: o índice único segurou.
            return false;
        }
    }

    /** A dívida acabou (ou foi excluída): a conta deixa de gerar vencimento. O pago fica; o que ainda estava em aberto é pulado. */
    private function end(FixedBill $conta, string $motivo): void
    {
        FixedBillPayment::withoutGlobalScopes()->where('fixed_bill_id', $conta->id)->whereIn('status', [FixedBillPaymentStatus::Pending->value, FixedBillPaymentStatus::Overdue->value])
            ->update(['status' => FixedBillPaymentStatus::Skipped->value, 'notes' => $motivo]);

        if ($conta->is_active) {
            $conta->update(['is_active' => false, 'auto_ended_at' => now()]);
        }
    }
}
