<?php

namespace App\Services;

use App\Enums\DebtAmortization;
use App\Enums\DebtInstallmentStatus;
use App\Enums\DebtKind;
use App\Enums\DebtRateBasis;
use App\Enums\DebtStatus;
use App\Enums\Necessity;
use App\Models\BankAccount;
use App\Models\Debt;
use App\Models\DebtInstallment;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Support\Debts\DebtScheduleCalculator;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Regras do módulo Dívidas (financiamento e empréstimo): criar o contrato com o cronograma, pagar parcelas, ligar uma parcela a
 * uma despesa que já existe e reclassificar.
 *
 * Toda parcela paga fica LIGADA a uma despesa do fluxo de caixa, com a categoria do momento: é assim que o dinheiro sai uma vez
 * só (a despesa) e a dívida (o saldo) cai junto, sem contar duas vezes. O dinheiro é bcmath e as somas, SQL (regra 3).
 */
class DebtService
{
    /**
     * Cria o contrato e gera as parcelas. As `paid_before` primeiras entram como pagas (já foram pagas antes do Cerne), sem
     * despesa ligada.
     *
     * @param  array<string, mixed>  $d  kind, name, creditor?, principal?, amortization, rate?, rate_basis?, term, first_due_date,
     *                                   installment_amount?, monthly_fees?, paid_before?, necessity?, category_id, subcategory_id?,
     *                                   bank_account_id?, member_id?, is_private?, notes?
     */
    public function create(array $d, ?string $userId = null): Debt
    {
        $tipo = DebtKind::from($d['kind']);

        if (! in_array($tipo, DebtKind::creatable(), true)) {
            throw new InvalidArgumentException('Este tipo de dívida ainda não pode ser cadastrado.');
        }

        $sistema = DebtAmortization::from($d['amortization'] ?? 'fixed');
        $prazo = (int) $d['term'];
        $jaPagas = (int) ($d['paid_before'] ?? 0);

        if ($prazo < 1 || $prazo > 480) {
            throw new InvalidArgumentException('O prazo deve ficar entre 1 e 480 parcelas.');
        }

        if ($jaPagas < 0 || $jaPagas > $prazo) {
            throw new InvalidArgumentException('As parcelas já pagas não podem passar do prazo.');
        }

        $this->validateClassification($d['category_id'] ?? null, $d['subcategory_id'] ?? null);

        $primeira = CarbonImmutable::parse($d['first_due_date'])->startOfDay();
        $taxas = Money::parse($d['monthly_fees'] ?? 0);
        $parcelas = $this->buildSchedule($sistema, $d, $prazo, $taxas);

        return DB::transaction(function () use ($d, $tipo, $sistema, $prazo, $jaPagas, $primeira, $taxas, $parcelas, $userId): Debt {
            $divida = Debt::create([
                'member_id' => $d['member_id'] ?? null,
                'is_private' => ($d['member_id'] ?? null) !== null && ! empty($d['is_private']),
                'kind' => $tipo,
                'status' => DebtStatus::Active,
                'name' => $d['name'],
                'creditor' => $d['creditor'] ?? null,
                'principal' => isset($d['principal']) && $d['principal'] !== '' ? Money::parse($d['principal']) : null,
                'amortization' => $sistema,
                'rate' => $sistema === DebtAmortization::Fixed ? null : $d['rate'],
                'rate_basis' => $sistema === DebtAmortization::Fixed ? null : DebtRateBasis::from($d['rate_basis']),
                'term' => $prazo,
                'first_due_date' => $primeira,
                'installment_amount' => $sistema === DebtAmortization::Fixed ? Money::parse($d['installment_amount']) : null,
                'monthly_fees' => $taxas,
                'paid_before' => $jaPagas,
                'necessity' => Necessity::from($d['necessity'] ?? Necessity::Essential->value),
                'category_id' => $d['category_id'],
                'subcategory_id' => $d['subcategory_id'] ?? null,
                'bank_account_id' => $d['bank_account_id'] ?? null,
                'notes' => $d['notes'] ?? null,
                'created_by_user_id' => $userId ?? auth()->id(),
            ]);

            foreach ($parcelas as $i => $p) {
                $numero = $i + 1;
                $vencimento = $primeira->addMonthsNoOverflow($i);
                $paga = $numero <= $jaPagas;

                DebtInstallment::create([
                    'debt_id' => $divida->id,
                    'member_id' => $divida->member_id,
                    'is_private' => $divida->is_private,
                    'number' => $numero,
                    'due_date' => $vencimento,
                    'principal_amount' => $p['principal'],
                    'interest_amount' => $p['interest'],
                    'fees_amount' => $p['fees'],
                    'amount' => $p['amount'],
                    'status' => $paga ? DebtInstallmentStatus::Paid : DebtInstallmentStatus::Open,
                    'paid_on' => $paga ? $vencimento : null,
                    'paid_amount' => $paga ? $p['amount'] : null,
                ]);
            }

            $this->refreshStatus($divida);

            return $divida->refresh();
        });
    }

    /**
     * Edita o que não muda o cronograma: nome, credor, classificação, conta, dono, privacidade e observações. Com
     * `$reclassificarPagas`, as despesas das parcelas já pagas passam para a classificação nova (por padrão, só valem as próximas).
     *
     * @param  array<string, mixed>  $d
     */
    public function update(Debt $divida, array $d, bool $reclassificarPagas = false): Debt
    {
        if (array_key_exists('category_id', $d)) {
            $this->validateClassification($d['category_id'], $d['subcategory_id'] ?? null);
        }

        return DB::transaction(function () use ($divida, $d, $reclassificarPagas): Debt {
            $divida->update(collect($d)->only(['name', 'creditor', 'necessity', 'category_id', 'subcategory_id', 'bank_account_id', 'notes', 'member_id', 'is_private'])->all());

            if ($reclassificarPagas) {
                $this->reclassifyLinkedExpenses($divida);
            }

            return $divida->refresh();
        });
    }

    public function delete(Debt $divida): void
    {
        // As despesas já lançadas ficam (é dinheiro que saiu): só perdem a ligação com a parcela, pelo nullOnDelete.
        $divida->delete();
    }

    // ------------------------------------------------------------------ parcelas

    /**
     * Paga a parcela: cria a despesa (com a classificação do contrato, ou a informada), debita a conta quando houver e marca a
     * parcela como paga e ligada. O valor pago pode diferir do previsto (juros de mora, desconto).
     */
    public function pay(DebtInstallment $parcela, CarbonInterface $dataPagamento, ?string $valor = null, ?string $contaId = null, ?string $userId = null): DebtInstallment
    {
        return DB::transaction(function () use ($parcela, $dataPagamento, $valor, $contaId, $userId): DebtInstallment {
            $parcela = DebtInstallment::query()->with('debt')->lockForUpdate()->findOrFail($parcela->id);

            if ($parcela->isPaid()) {
                throw new InvalidArgumentException('Esta parcela já foi paga.');
            }

            $divida = $parcela->debt;
            $pago = Money::parse($valor ?? $parcela->amount);

            if (bccomp($pago, '0', 2) <= 0) {
                throw new InvalidArgumentException('Informe o valor pago.');
            }

            $conta = ($contaId ?? $divida->bank_account_id) !== null ? BankAccount::query()->findOrFail($contaId ?? $divida->bank_account_id) : null;

            $despesa = ExpenseRecord::create([
                'member_id' => $divida->member_id,
                'is_private' => $divida->is_private,
                'description' => $this->describe($parcela, $divida),
                'necessity' => $divida->necessity,
                'category_id' => $divida->category_id,
                'subcategory_id' => $divida->subcategory_id,
                'amount' => $pago,
                'expense_date' => $dataPagamento,
                'bank_account_id' => $conta?->id,
                'created_by_user_id' => $userId ?? auth()->id(),
            ]);

            $conta?->applyToBalance('-'.$pago);

            $parcela->update([
                'status' => DebtInstallmentStatus::Paid,
                'paid_on' => $dataPagamento,
                'paid_amount' => $pago,
                'expense_id' => $despesa->id,
            ]);

            $this->refreshStatus($divida);

            return $parcela->refresh();
        });
    }

    /** Liga a parcela a uma despesa que já existe (lançada à mão ou importada): a parcela fica paga, sem criar outra despesa. */
    public function linkExpense(DebtInstallment $parcela, ExpenseRecord $despesa, bool $reclassificar = false): DebtInstallment
    {
        if ($parcela->isPaid()) {
            throw new InvalidArgumentException('Esta parcela já foi paga.');
        }

        $parcela->loadMissing('debt');

        if (DebtInstallment::query()->where('expense_id', $despesa->id)->exists()) {
            throw new InvalidArgumentException('Esta despesa já está ligada a outra parcela.');
        }

        return DB::transaction(function () use ($parcela, $despesa, $reclassificar): DebtInstallment {
            $divida = $parcela->debt;

            if ($reclassificar) {
                $despesa->update(['category_id' => $divida->category_id, 'subcategory_id' => $divida->subcategory_id, 'necessity' => $divida->necessity]);
            }

            $parcela->update([
                'status' => DebtInstallmentStatus::Paid,
                'paid_on' => $despesa->expense_date,
                'paid_amount' => $despesa->amount,
                'expense_id' => $despesa->id,
            ]);

            $this->refreshStatus($divida);

            return $parcela->refresh();
        });
    }

    /** Desfaz o pagamento da parcela. A despesa NÃO é apagada (o dinheiro saiu): ela só deixa de estar ligada. */
    public function unlink(DebtInstallment $parcela): DebtInstallment
    {
        $parcela->loadMissing('debt');
        $parcela->update(['status' => DebtInstallmentStatus::Open, 'paid_on' => null, 'paid_amount' => null, 'expense_id' => null]);
        $this->refreshStatus($parcela->debt);

        return $parcela->refresh();
    }

    /** Ajusta valor ou vencimento de uma parcela em aberto (a parcela real nem sempre é a calculada). */
    public function updateInstallment(DebtInstallment $parcela, ?string $valor, ?CarbonInterface $vencimento): DebtInstallment
    {
        if ($parcela->isPaid()) {
            throw new InvalidArgumentException('Parcela paga não se edita: desfaça o pagamento primeiro.');
        }

        $campos = [];

        if ($valor !== null) {
            $novo = Money::parse($valor);

            if (bccomp($novo, '0', 2) <= 0) {
                throw new InvalidArgumentException('O valor da parcela deve ser maior que zero.');
            }

            // A diferença vai para os juros, para a amortização (e o saldo devedor) continuar fechando com o contrato.
            $campos['interest_amount'] = bcadd((string) $parcela->interest_amount, bcsub($novo, (string) $parcela->amount, 2), 2);
            $campos['amount'] = $novo;

            if (bccomp($campos['interest_amount'], '0', 2) < 0) {
                $campos['principal_amount'] = bcadd((string) $parcela->principal_amount, $campos['interest_amount'], 2);
                $campos['interest_amount'] = '0.00';
            }
        }

        if ($vencimento !== null) {
            $campos['due_date'] = CarbonImmutable::instance($vencimento)->startOfDay();
        }

        $parcela->update($campos);

        return $parcela->refresh();
    }

    // ------------------------------------------------------------------ resumo

    /**
     * Totais para a lista e o painel. Arrays de texto, sem models: o painel guarda isto em cache.
     *
     * @return array{total: string, month_total: string, month_paid: string, month_open: string, next: list<array<string, string>>}
     */
    public function summary(?int $ano = null, ?int $mes = null): array
    {
        $hoje = CarbonImmutable::now();
        $ano ??= $hoje->year;
        $mes ??= $hoje->month;

        $abertasAtivas = fn () => DebtInstallment::query()
            ->where('status', DebtInstallmentStatus::Open)
            ->whereHas('debt', fn ($q) => $q->where('status', DebtStatus::Active));

        $calculado = (string) $abertasAtivas()->whereHas('debt', fn ($q) => $q->where('amortization', '!=', DebtAmortization::Fixed->value))->sum('principal_amount');
        $informado = (string) $abertasAtivas()->whereHas('debt', fn ($q) => $q->where('amortization', DebtAmortization::Fixed->value))->sum('amount');

        $doMes = fn () => DebtInstallment::query()->whereYear('due_date', $ano)->whereMonth('due_date', $mes);
        $mesTotal = (string) $doMes()->sum('amount');
        $mesPago = (string) $doMes()->where('status', DebtInstallmentStatus::Paid)->sum(DB::raw('COALESCE(paid_amount, amount)'));
        $mesAberto = (string) $doMes()->where('status', DebtInstallmentStatus::Open)->sum('amount');

        $proximas = $abertasAtivas()->with('debt')->orderBy('due_date')->limit(5)->get()->map(fn (DebtInstallment $p) => [
            'debt_id' => $p->debt_id,
            'name' => $p->debt->name,
            'number' => (string) $p->number,
            'term' => (string) $p->debt->term,
            'due_date' => $p->due_date->toDateString(),
            'amount' => (string) $p->amount,
        ])->all();

        return [
            'total' => Money::parse(bcadd(Money::parse($calculado), Money::parse($informado), 2)),
            'month_total' => Money::parse($mesTotal),
            'month_paid' => Money::parse($mesPago),
            'month_open' => Money::parse($mesAberto),
            'next' => $proximas,
        ];
    }

    // ------------------------------------------------------------------ internos

    /** @return list<array{principal: string, interest: string, fees: string, amount: string}> */
    private function buildSchedule(DebtAmortization $sistema, array $d, int $prazo, string $taxas): array
    {
        if ($sistema === DebtAmortization::Fixed) {
            $parcela = Money::parse($d['installment_amount'] ?? 0);

            if (bccomp($parcela, '0', 2) <= 0) {
                throw new InvalidArgumentException('Informe o valor da parcela.');
            }

            // Parcelas informadas: o contrato não diz quanto é juro, então a parcela inteira conta como valor a pagar.
            return array_fill(0, $prazo, ['principal' => $parcela, 'interest' => '0.00', 'fees' => '0.00', 'amount' => $parcela]);
        }

        if (empty($d['principal']) || bccomp(Money::parse($d['principal']), '0', 2) <= 0) {
            throw new InvalidArgumentException('Informe o valor financiado.');
        }

        if (! isset($d['rate']) || $d['rate'] === '' || empty($d['rate_basis'])) {
            throw new InvalidArgumentException('Informe a taxa de juros do contrato.');
        }

        $taxaMensal = DebtScheduleCalculator::monthlyRate((string) $d['rate'], DebtRateBasis::from($d['rate_basis']));

        return DebtScheduleCalculator::schedule($sistema, Money::parse($d['principal']), $taxaMensal, $prazo, $taxas);
    }

    private function validateClassification(?string $categoriaId, ?string $subcategoriaId): void
    {
        if ($categoriaId === null || $categoriaId === '') {
            throw new InvalidArgumentException('Escolha a categoria das parcelas.');
        }

        $categoria = ExpenseCategory::query()->findOrFail($categoriaId);

        if ($subcategoriaId !== null && $subcategoriaId !== '' && ! $categoria->subcategories()->whereKey($subcategoriaId)->exists()) {
            throw new InvalidArgumentException('A subcategoria não pertence à categoria escolhida.');
        }
    }

    private function reclassifyLinkedExpenses(Debt $divida): void
    {
        $despesas = DebtInstallment::query()->where('debt_id', $divida->id)->whereNotNull('expense_id')->pluck('expense_id');

        ExpenseRecord::query()->whereIn('id', $despesas)->update([
            'category_id' => $divida->category_id, 'subcategory_id' => $divida->subcategory_id, 'necessity' => $divida->necessity,
        ]);
    }

    private function describe(DebtInstallment $parcela, Debt $divida): string
    {
        return "Parcela {$parcela->number}/{$divida->term} · {$divida->name}";
    }

    /** Sem parcela em aberto, a dívida está quitada; voltou a ter, volta a andamento. */
    private function refreshStatus(Debt $divida): void
    {
        $abertas = DebtInstallment::query()->where('debt_id', $divida->id)->where('status', DebtInstallmentStatus::Open)->exists();
        $divida->update(['status' => $abertas ? DebtStatus::Active : DebtStatus::PaidOff]);
    }
}
