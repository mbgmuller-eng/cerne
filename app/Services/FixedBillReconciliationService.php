<?php

namespace App\Services;

use App\Enums\FixedBillPaymentStatus;
use App\Models\ExpenseCategorizationRule;
use App\Models\ExpenseRecord;
use App\Models\FixedBillPayment;
use App\Services\Extraction\CategorizationRuleMatcher;
use App\Support\Budget\BudgetStructure;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * "Conciliar": a conta fixa que já foi paga PELO EXTRATO. O lançamento existe (veio da importação, ou foi lançado à mão) e a ocorrência continua em
 * aberto, porque a regra de categorização só foi ligada à conta depois, ou o pagamento saiu fora da janela do casamento. Conciliar marca a ocorrência
 * como PAGA ligada àquele lançamento (`fixed_bill_payments.expense_id`): não cria despesa nova e não mexe no saldo (o dinheiro já saiu quando o
 * lançamento entrou). "Pagar" faria os dois e contaria o valor em dobro.
 *
 * Só sugere; quem confirma é a pessoa (regra 5: casamento nunca é automático). O candidato vem de duas pistas, da mais forte para a mais fraca:
 * 1) uma regra de categorização ativa LIGADA a essa conta fixa reconhece a descrição (e o valor, se a regra o trava): é o mesmo teste da importação;
 * 2) o nome da conta fixa aparece na descrição e o valor é o da conta (conta de valor fixo; a variável só pela regra, para não casar qualquer coisa).
 * Cada lançamento baixa UMA conta (índice único) e cada conta recebe UM lançamento (o mais próximo do vencimento).
 *
 * Roda sob o ProfileContext de quem pergunta: só enxerga os lançamentos dele. Lançamento privado não baixa conta de todos (o valor apareceria para o cônjuge).
 */
class FixedBillReconciliationService
{
    /** O lançamento pode ser até 15 dias ANTES do vencimento (pagou adiantado) ou até 10 dias DEPOIS (atraso). Mesma janela do casamento na importação. */
    public const DAYS_BEFORE_DUE = CategorizationRuleMatcher::FIXED_BILL_DAYS_BEFORE_DUE;

    public const DAYS_AFTER_DUE = CategorizationRuleMatcher::FIXED_BILL_DAYS_AFTER_DUE;

    /** Nome curto demais ("Luz", "Gás") casaria com qualquer descrição. */
    private const MIN_NAME_LENGTH = 4;

    /**
     * @return list<array{payment: FixedBillPayment, expense: ExpenseRecord, basis: string, distance: int}> ordenado pelo vencimento; basis: rule ou name
     */
    public function suggestions(int $year, int $month): array
    {
        $pagamentos = FixedBillPayment::query()->forPeriod($year, $month)->outstanding()->with('fixedBill')->get();

        if ($pagamentos->isEmpty()) {
            return [];
        }

        // Cópia imutável: subDays/addDays em cima do Carbon do próprio model mexeria na data dele.
        $de = CarbonImmutable::instance($pagamentos->min('due_date'))->subDays(self::DAYS_BEFORE_DUE);
        $ate = CarbonImmutable::instance($pagamentos->max('due_date'))->addDays(self::DAYS_AFTER_DUE);

        // Lançamentos do período que ainda não quitaram nenhuma conta, fora estorno e compra de cartão (linha de fatura não é movimento da conta).
        $despesas = ExpenseRecord::query()
            ->whereBetween('expense_date', [$de->toDateString(), $ate->toDateString()])
            ->where('is_refund', false)->whereNull('credit_card_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('fixed_bill_payments')->whereColumn('fixed_bill_payments.expense_id', 'expense_records.id'))
            ->orderBy('expense_date')->limit(1500)->get();

        if ($despesas->isEmpty()) {
            return [];
        }

        $regras = ExpenseCategorizationRule::query()->active()->whereNotNull('fixed_bill_id')->get()->groupBy('fixed_bill_id');
        $pares = [];

        foreach ($pagamentos as $pagamento) {
            foreach ($despesas as $despesa) {
                if (! $this->inWindow($pagamento, $despesa) || ! $this->privacyAllows($pagamento, $despesa)) {
                    continue;
                }

                $base = $this->basis($pagamento, $despesa, $regras->get($pagamento->fixed_bill_id, collect()));

                if ($base !== null) {
                    $pares[] = ['payment' => $pagamento, 'expense' => $despesa, 'basis' => $base, 'distance' => (int) abs($despesa->expense_date->diffInDays($pagamento->due_date))];
                }
            }
        }

        // O mais forte primeiro (regra antes de nome), depois o mais perto do vencimento; cada conta e cada lançamento entram uma vez só.
        usort($pares, fn ($a, $b) => [$a['basis'] === 'rule' ? 0 : 1, $a['distance']] <=> [$b['basis'] === 'rule' ? 0 : 1, $b['distance']]);

        $contas = [];
        $lancamentos = [];
        $saida = [];

        foreach ($pares as $par) {
            if (isset($contas[$par['payment']->id]) || isset($lancamentos[$par['expense']->id])) {
                continue;
            }

            $contas[$par['payment']->id] = true;
            $lancamentos[$par['expense']->id] = true;
            $saida[] = $par;
        }

        usort($saida, fn ($a, $b) => $a['payment']->due_date <=> $b['payment']->due_date);

        return $saida;
    }

    /**
     * Marca a conta como paga ligada ao lançamento. Os dois ids vêm de uma tela, então tudo é conferido de novo aqui, sob o escopo de quem pede.
     *
     * @throws InvalidArgumentException com a mensagem que a tela mostra
     */
    public function reconcile(string $paymentId, string $expenseId): FixedBillPayment
    {
        $despesa = ExpenseRecord::query()->findOrFail($expenseId);

        try {
            return DB::transaction(function () use ($paymentId, $despesa): FixedBillPayment {
                // Trava a linha: duas abas (ou o cron) não baixam a mesma conta ao mesmo tempo.
                $pagamento = FixedBillPayment::query()->with('fixedBill')->whereKey($paymentId)->lockForUpdate()->firstOrFail();

                if (! $pagamento->status->isOutstanding()) {
                    throw new InvalidArgumentException('Esta conta já foi paga ou pulada.');
                }

                if ($despesa->is_refund || $despesa->credit_card_id !== null) {
                    throw new InvalidArgumentException('Este lançamento não é um pagamento feito pela conta.');
                }

                if (FixedBillPayment::query()->where('expense_id', $despesa->id)->exists()) {
                    throw new InvalidArgumentException('Este lançamento já está ligado a uma conta fixa.');
                }

                if (! $this->privacyAllows($pagamento, $despesa)) {
                    throw new InvalidArgumentException('Este lançamento é privado e a conta fixa é de todos: o valor apareceria para o cônjuge.');
                }

                if (! $this->inWindow($pagamento, $despesa)) {
                    throw new InvalidArgumentException('A data do lançamento está longe do vencimento desta conta.');
                }

                // Pelo model (e não um update em massa): a trilha de auditoria e o cache do painel dependem dos eventos dele.
                $pagamento->update([
                    'status' => FixedBillPaymentStatus::Paid,
                    'amount_paid' => Money::parse(ltrim((string) $despesa->amount, '-')),
                    'paid_at' => $despesa->expense_date,
                    'expense_id' => $despesa->id,
                ]);

                return $pagamento->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            // O índice único pegou a corrida: outro pedido ligou este lançamento a outra conta no meio do caminho.
            throw new InvalidArgumentException('Este lançamento já está ligado a uma conta fixa.');
        }
    }

    // ------------------------------------------------------------------ pistas

    private function inWindow(FixedBillPayment $pagamento, ExpenseRecord $despesa): bool
    {
        $data = CarbonImmutable::instance($despesa->expense_date)->startOfDay();
        $vencimento = CarbonImmutable::instance($pagamento->due_date)->startOfDay();

        return $data->greaterThanOrEqualTo($vencimento->subDays(self::DAYS_BEFORE_DUE)) && $data->lessThanOrEqualTo($vencimento->addDays(self::DAYS_AFTER_DUE));
    }

    /** Conta de todos não pode ser baixada por lançamento privado: o valor pago ficaria à vista do cônjuge. */
    private function privacyAllows(FixedBillPayment $pagamento, ExpenseRecord $despesa): bool
    {
        return (bool) $pagamento->fixedBill->is_private || ! $despesa->is_private;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ExpenseCategorizationRule>  $regras  as regras ligadas a ESTA conta fixa
     */
    private function basis(FixedBillPayment $pagamento, ExpenseRecord $despesa, $regras): ?string
    {
        $descricao = (string) $despesa->description;
        $valor = Money::parse(ltrim((string) $despesa->amount, '-'));

        // 1) A mesma conta que a importação faz: o texto da regra e, se ela trava o valor, o valor exato.
        foreach ($regras as $regra) {
            if (mb_stripos($descricao, (string) $regra->pattern) !== false && ($regra->amount === null || bccomp((string) $regra->amount, $valor, 2) === 0)) {
                return 'rule';
            }
        }

        // 2) O nome da conta na descrição, só para conta de valor fixo e com o valor dela.
        $conta = $pagamento->fixedBill;
        $nome = BudgetStructure::fold((string) $conta->name);

        if (! $conta->is_variable && mb_strlen($nome) >= self::MIN_NAME_LENGTH
            && str_contains(BudgetStructure::fold($descricao), $nome) && bccomp(Money::parse((string) $conta->amount), $valor, 2) === 0) {
            return 'name';
        }

        return null;
    }
}
