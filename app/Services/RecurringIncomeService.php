<?php

namespace App\Services;

use App\Enums\RecurringIncomeStatus;
use App\Models\BankAccount;
use App\Models\IncomeRecord;
use App\Models\RecurringIncome;
use App\Models\RecurringIncomeOccurrence;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Espelho de FixedBillService do lado da receita — mesmo raciocínio de
 * idempotência (índice único por due_date, CLAUDE.md regra 4).
 */
class RecurringIncomeService
{
    /**
     * Cadastra uma receita recorrente nova e já gera o recebimento do mês
     * corrente, pra quem cadastrou ver na hora.
     *
     * @param  array{
     *     name: string, amount: string, recurrence: \App\Enums\RecurrenceType,
     *     due_day?: ?int, due_weekday?: ?int, due_month?: ?int,
     *     member_id?: ?string, bank_account_id?: ?string, category_id?: ?string,
     *     is_variable?: bool, notes?: ?string,
     * }  $dados
     */
    public function create(array $dados): RecurringIncome
    {
        $receita = RecurringIncome::create($dados);

        $hoje = CarbonImmutable::now();
        $this->generateOccurrencesFor($receita, $hoje->year, $hoje->month);

        return $receita;
    }

    /**
     * Gera os recebimentos do mês para todas as receitas recorrentes
     * ativas. Roda sem escopo de perfil de propósito — rotina de sistema.
     *
     * @return int quantos recebimentos foram criados
     */
    public function generateForMonth(int $year, int $month): int
    {
        $criados = 0;

        RecurringIncome::withoutProfileScope()
            ->where('is_active', true)
            ->chunkById(200, function ($receitas) use ($year, $month, &$criados): void {
                foreach ($receitas as $receita) {
                    $criados += $this->generateOccurrencesFor($receita, $year, $month);
                }
            });

        return $criados;
    }

    private function generateOccurrencesFor(RecurringIncome $receita, int $year, int $month): int
    {
        $criados = 0;

        foreach ($receita->occurrencesInMonth($year, $month) as $data) {
            $existe = RecurringIncomeOccurrence::withoutProfileScope()
                ->where('recurring_income_id', $receita->id)
                ->where('due_date', $data->toDateString())
                ->exists();

            if ($existe) {
                continue;
            }

            RecurringIncomeOccurrence::withoutProfileScope()->create([
                'profile_id' => $receita->profile_id,
                'recurring_income_id' => $receita->id,
                'year' => $data->year,
                'month' => $data->month,
                'due_date' => $data,
                'status' => RecurringIncomeStatus::Pending,
            ]);
            $criados++;
        }

        return $criados;
    }

    /** Marca como atrasado o que passou da data prevista sem confirmação. */
    public function markOverdue(?CarbonImmutable $hoje = null): int
    {
        $hoje ??= CarbonImmutable::now();

        return RecurringIncomeOccurrence::withoutProfileScope()
            ->where('status', RecurringIncomeStatus::Pending)
            ->whereDate('due_date', '<', $hoje->toDateString())
            ->update(['status' => RecurringIncomeStatus::Overdue]);
    }

    /**
     * Confirma o recebimento. Receita de valor variável (comissão, freela)
     * EXIGE o valor informado, mesmo raciocínio de FixedBillService::pay().
     *
     * Quando há conta bancária vinculada, gera também o lançamento de
     * receita e credita o saldo.
     */
    public function receive(
        RecurringIncomeOccurrence $occurrence,
        ?string $amount = null,
        ?CarbonImmutable $receivedAt = null,
        ?string $userId = null,
    ): RecurringIncomeOccurrence {
        $receita = $occurrence->recurringIncome;

        if ($receita->is_variable && $amount === null) {
            throw new \InvalidArgumentException(
                'Receita de valor variável exige informar o valor recebido.'
            );
        }

        $valor = Money::parse($amount ?? $receita->amount);
        $receivedAt ??= CarbonImmutable::now();

        return DB::transaction(function () use ($occurrence, $receita, $valor, $receivedAt, $userId): RecurringIncomeOccurrence {
            $occurrence->update([
                'status' => RecurringIncomeStatus::Received,
                'amount_received' => $valor,
                'received_at' => $receivedAt,
            ]);

            if ($receita->bank_account_id !== null && $receita->category_id !== null) {
                IncomeRecord::create([
                    'profile_id' => $receita->profile_id,
                    'member_id' => $receita->member_id,
                    'category_id' => $receita->category_id,
                    'description' => $receita->name,
                    'amount' => $valor,
                    'received_date' => $receivedAt,
                    'bank_account_id' => $receita->bank_account_id,
                    'is_recurring' => true,
                    'created_by_user_id' => $userId ?? $receita->profile->owner_user_id,
                ]);

                BankAccount::withoutProfileScope()
                    ->find($receita->bank_account_id)
                    ?->applyToBalance($valor);
            }

            return $occurrence->refresh();
        });
    }

    /** Pula o mês — receita que não veio, contrato encerrado no meio do ciclo. */
    public function skip(RecurringIncomeOccurrence $occurrence, ?string $motivo = null): RecurringIncomeOccurrence
    {
        $occurrence->update([
            'status' => RecurringIncomeStatus::Skipped,
            'notes' => $motivo,
        ]);

        return $occurrence;
    }

    /**
     * Rotina diária completa: gera o mês corrente e marca os atrasos.
     *
     * @return array{gerados: int, atrasados: int}
     */
    public function runDailyMaintenance(?CarbonImmutable $hoje = null): array
    {
        $hoje ??= CarbonImmutable::now();

        return [
            'gerados' => $this->generateForMonth($hoje->year, $hoje->month),
            'atrasados' => $this->markOverdue($hoje),
        ];
    }
}
