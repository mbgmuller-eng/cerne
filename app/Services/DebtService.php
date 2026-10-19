<?php

namespace App\Services;

use App\Enums\AdjustmentIndex;
use App\Enums\ConsortiumType;
use App\Enums\ContemplationMode;
use App\Enums\DebtAmortization;
use App\Enums\DebtInstallmentStatus;
use App\Enums\DebtKind;
use App\Enums\DebtRateBasis;
use App\Enums\DebtStatus;
use App\Enums\GoalStatus;
use App\Enums\Necessity;
use App\Models\BankAccount;
use App\Models\Debt;
use App\Models\DebtAdjustment;
use App\Models\Goal;
use App\Models\DebtInstallment;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Support\Debts\DebtClassification;
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

        if (! in_array($tipo, [DebtKind::Financing, DebtKind::Loan], true)) {
            throw new InvalidArgumentException('Consórcio e dívida em aberto têm cadastro próprio.');
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

    // ------------------------------------------------------------------ consórcio

    /**
     * Cadastra um consórcio ainda não contemplado: a carta (o crédito), as parcelas e o reajuste. Antes da contemplação a parcela
     * paga é investimento (categoria Consórcio) e o consórcio NÃO é dívida nem patrimônio: o que vale é a carta.
     *
     * Com `goal_id`, liga o consórcio a um objetivo existente; com `goal_name`, cria o objetivo (valor = a carta). O progresso do
     * objetivo passa a ser pelas parcelas pagas.
     *
     * @param  array<string, mixed>  $d  consortium_type, name?, creditor?, consortium_group?, credit_amount, term, installment_amount,
     *                                   first_due_date, paid_before?, is_flex?, adjustment_index?, adjustment_every_months?,
     *                                   adjustment_first_after_months?, bank_account_id?, member_id?, is_private?, notes?, goal_id?, goal_name?
     */
    public function createConsortium(array $d, ?string $userId = null): Debt
    {
        $tipo = ConsortiumType::from($d['consortium_type']);
        $prazo = (int) $d['term'];
        $jaPagas = (int) ($d['paid_before'] ?? 0);
        $parcela = Money::parse($d['installment_amount'] ?? 0);
        $carta = Money::parse($d['credit_amount'] ?? 0);

        if ($prazo < 1 || $prazo > 480) {
            throw new InvalidArgumentException('O prazo deve ficar entre 1 e 480 parcelas.');
        }

        if ($jaPagas < 0 || $jaPagas > $prazo) {
            throw new InvalidArgumentException('As parcelas já pagas não podem passar do prazo.');
        }

        if (bccomp($parcela, '0', 2) <= 0 || bccomp($carta, '0', 2) <= 0) {
            throw new InvalidArgumentException('Informe o valor da carta e o valor da parcela.');
        }

        $indice = ! empty($d['adjustment_index']) ? AdjustmentIndex::from($d['adjustment_index']) : null;
        $cada = $indice !== null ? (int) ($d['adjustment_every_months'] ?? 12) : null;
        $primeiro = $indice !== null ? (int) ($d['adjustment_first_after_months'] ?? $cada) : null;

        if ($indice !== null && ($cada < 1 || $cada > 60 || $primeiro < 1 || $primeiro > 120)) {
            throw new InvalidArgumentException('Informe de quantos em quantos meses a carta é reajustada.');
        }

        $flex = $this->flexPercent($d);

        // Parcela pulada (valor diluído nas seguintes): vale entre as que já venceram, ou seja, as pagas e as próprias puladas.
        $pulos = collect($d['skipped_installments'] ?? [])->map(fn ($n) => (int) $n)->filter(fn (int $n) => $n >= 1)->unique()->sort()->values();

        if ($pulos->isNotEmpty() && ($pulos->last() > $jaPagas + $pulos->count() || $jaPagas + $pulos->count() > $prazo)) {
            throw new InvalidArgumentException('As parcelas puladas precisam estar entre as que já venceram (as pagas e as puladas).');
        }

        $classificacao = DebtClassification::consortiumInvestment();

        if ($classificacao['category_id'] === null) {
            throw new InvalidArgumentException('A categoria de investimento "Consórcio" não foi encontrada.');
        }

        $primeira = CarbonImmutable::parse($d['first_due_date'])->startOfDay();

        return DB::transaction(function () use ($d, $tipo, $prazo, $jaPagas, $parcela, $carta, $indice, $cada, $primeiro, $classificacao, $primeira, $userId, $flex, $pulos): Debt {
            $divida = Debt::create([
                'member_id' => $d['member_id'] ?? null,
                'is_private' => ($d['member_id'] ?? null) !== null && ! empty($d['is_private']),
                'kind' => DebtKind::Consortium,
                'status' => DebtStatus::Active,
                'name' => ! empty($d['name']) ? $d['name'] : 'Consórcio de '.mb_strtolower($tipo->label()),
                'creditor' => $d['creditor'] ?? null,
                'consortium_type' => $tipo,
                'consortium_group' => $d['consortium_group'] ?? null,
                'credit_amount' => $carta,
                'is_flex' => $flex !== null || ! empty($d['is_flex']),
                'flex_percent' => $flex,
                'consortium_contract' => $d['consortium_contract'] ?? null,
                'adjustment_index' => $indice,
                'adjustment_every_months' => $cada,
                'adjustment_first_after_months' => $primeiro,
                'next_adjustment_on' => $indice !== null ? $primeira->addMonthsNoOverflow($primeiro) : null,
                'amortization' => DebtAmortization::Fixed,
                'term' => $prazo,
                'first_due_date' => $primeira,
                'installment_amount' => $parcela,
                'paid_before' => $jaPagas,
                'necessity' => Necessity::Investment,
                'category_id' => $classificacao['category_id'],
                'subcategory_id' => null,
                'bank_account_id' => $d['bank_account_id'] ?? null,
                'notes' => $d['notes'] ?? null,
                'created_by_user_id' => $userId ?? auth()->id(),
            ]);

            $this->generateFixedInstallments($divida, $prazo, $parcela, $primeira, $jaPagas + $pulos->count());

            if ($pulos->isNotEmpty()) {
                DebtInstallment::query()->where('debt_id', $divida->id)->whereIn('number', $pulos->all())
                    ->update(['status' => DebtInstallmentStatus::Diluted, 'paid_on' => null, 'paid_amount' => null]);
            }

            $this->linkGoal($divida, $d['goal_id'] ?? null, $d['goal_name'] ?? null);
            $this->refreshStatus($divida);

            return $divida->refresh();
        });
    }

    /**
     * Registra o reajuste da carta pelo índice: sobe a carta e as parcelas em aberto no mesmo percentual, guarda o histórico e
     * marca o próximo reajuste. O percentual vem de quem registra (o Cerne avisa na data, não busca o índice sozinho). O objetivo
     * ligado sobe junto, no mesmo percentual.
     */
    public function registerAdjustment(Debt $divida, string $percentual, CarbonInterface $aplicadoEm, ?string $userId = null): DebtAdjustment
    {
        if (! $divida->isConsortium()) {
            throw new InvalidArgumentException('Só o consórcio tem reajuste da carta.');
        }

        $p = bcadd(str_replace(',', '.', trim($percentual)), '0', 4);

        if (bccomp($p, '-50', 4) < 0 || bccomp($p, '200', 4) > 0) {
            throw new InvalidArgumentException('Informe o percentual do índice (por exemplo, 4,5).');
        }

        $fator = bcadd('1', bcdiv($p, '100', 8), 8);

        return DB::transaction(function () use ($divida, $p, $fator, $aplicadoEm, $userId): DebtAdjustment {
            $cartaAntes = (string) $divida->credit_amount;
            $parcelaAntes = (string) $divida->installment_amount;
            $cartaDepois = bcadd(bcmul($cartaAntes, $fator, 8), '0.005', 2);

            $abertas = DebtInstallment::query()->where('debt_id', $divida->id)->where('status', DebtInstallmentStatus::Open)->orderBy('number')->get();

            foreach ($abertas as $parcela) {
                $novo = bcadd(bcmul((string) $parcela->amount, $fator, 8), '0.005', 2);
                $parcela->update(['amount' => $novo, 'principal_amount' => $novo]);
            }

            $parcelaDepois = $abertas->isNotEmpty() ? (string) $abertas->first()->fresh()->amount : bcadd(bcmul($parcelaAntes, $fator, 8), '0.005', 2);

            $base = $divida->next_adjustment_on ?? CarbonImmutable::instance($aplicadoEm);

            $divida->update([
                'credit_amount' => $cartaDepois,
                'installment_amount' => $parcelaDepois,
                'next_adjustment_on' => $divida->adjustment_every_months ? CarbonImmutable::instance($base)->addMonthsNoOverflow($divida->adjustment_every_months) : null,
            ]);

            $this->scaleLinkedGoal($divida, $fator);

            return DebtAdjustment::create([
                'debt_id' => $divida->id, 'member_id' => $divida->member_id, 'is_private' => $divida->is_private,
                'applied_on' => $aplicadoEm, 'percent' => $p, 'credit_before' => $cartaAntes, 'credit_after' => $cartaDepois,
                'installment_before' => $parcelaAntes, 'installment_after' => $parcelaDepois, 'created_by_user_id' => $userId ?? auth()->id(),
            ]);
        });
    }

    /**
     * Registra a contemplação. A partir dela o consórcio passa a ser dívida: as parcelas em aberto são refeitas com o NOVO valor
     * da parcela e a quantidade restante informados (o lance pode baixar a parcela ou encurtar o prazo; o sorteio, numa parcela
     * flex, pode aumentá-la bastante), e a classificação passa de investimento para a despesa do bem (casa, carro...).
     *
     * @param  array{contemplated_on: string, mode: string, bid_amount?: ?string, credit_released?: ?string, new_installment_amount: string, remaining_installments: int|string, category_id: string, subcategory_id?: ?string, necessity?: string, mark_goal_achieved?: bool}  $d
     */
    public function contemplate(Debt $divida, array $d): Debt
    {
        if (! $divida->isConsortium() || $divida->isContemplated()) {
            throw new InvalidArgumentException('Este consórcio não pode ser contemplado.');
        }

        $modo = ContemplationMode::from($d['mode']);
        $novaParcela = Money::parse($d['new_installment_amount'] ?? 0);
        $restantes = (int) $d['remaining_installments'];
        $lance = $modo->hasBid() ? Money::parse($d['bid_amount'] ?? 0) : null;

        if (bccomp($novaParcela, '0', 2) <= 0) {
            throw new InvalidArgumentException('Informe o valor da parcela depois da contemplação.');
        }

        if ($restantes < 1 || $restantes > 480) {
            throw new InvalidArgumentException('Informe quantas parcelas restam (de 1 a 480).');
        }

        if ($modo->hasBid() && bccomp((string) $lance, '0', 2) <= 0) {
            throw new InvalidArgumentException('Informe o valor do lance.');
        }

        $this->validateClassification($d['category_id'] ?? null, $d['subcategory_id'] ?? null);

        return DB::transaction(function () use ($divida, $d, $modo, $novaParcela, $restantes, $lance): Debt {
            $abertas = DebtInstallment::query()->where('debt_id', $divida->id)->where('status', DebtInstallmentStatus::Open)->orderBy('number')->get();
            $pagas = $divida->paidCount();
            $proximoVencimento = $abertas->first()?->due_date ?? CarbonImmutable::parse($d['contemplated_on'])->addMonth();

            DebtInstallment::query()->where('debt_id', $divida->id)->where('status', DebtInstallmentStatus::Open)->delete();

            $this->generateFixedInstallments($divida, $pagas + $restantes, $novaParcela, CarbonImmutable::instance($proximoVencimento), 0, $pagas);

            $divida->update([
                'contemplated_on' => $d['contemplated_on'],
                'contemplation_mode' => $modo,
                'bid_amount' => $lance,
                'credit_released' => ! empty($d['credit_released']) ? Money::parse($d['credit_released']) : $divida->credit_amount,
                'installment_amount' => $novaParcela,
                'term' => $pagas + $restantes,
                'necessity' => Necessity::from($d['necessity'] ?? Necessity::Essential->value),
                'category_id' => $d['category_id'],
                'subcategory_id' => $d['subcategory_id'] ?? null,
            ]);

            if (! empty($d['mark_goal_achieved'])) {
                Goal::query()->where('linked_debt_id', $divida->id)->update(['status' => GoalStatus::Achieved->value]);
            }

            $this->refreshStatus($divida);

            return $divida->refresh();
        });
    }

    // ------------------------------------------------------------------ parcela pulada e extrato da administradora

    /** O quanto a parcela flex é menor até a contemplação: 10, 20, 30, 40 ou 50%. Vazio = não é flex. */
    private function flexPercent(array $d): ?int
    {
        $valor = $d['flex_percent'] ?? null;

        if ($valor === null || $valor === '' || (int) $valor === 0) {
            return null;
        }

        if (! in_array((int) $valor, [10, 20, 30, 40, 50], true)) {
            throw new InvalidArgumentException('O flex da parcela é de 10%, 20%, 30%, 40% ou 50%.');
        }

        return (int) $valor;
    }

    /**
     * Marca uma parcela em aberto como pulada: não foi paga e o valor foi diluído nas seguintes. Fica fora do que está em aberto e
     * do que foi pago. Só vale para parcela sem despesa ligada.
     */
    public function markDiluted(DebtInstallment $parcela): DebtInstallment
    {
        if ($parcela->status !== DebtInstallmentStatus::Open) {
            throw new InvalidArgumentException('Só uma parcela em aberto pode ser marcada como pulada.');
        }

        $parcela->update(['status' => DebtInstallmentStatus::Diluted]);
        $this->refreshStatus($parcela->debt()->firstOrFail());

        return $parcela->refresh();
    }

    public function reopenDiluted(DebtInstallment $parcela): DebtInstallment
    {
        if ($parcela->status !== DebtInstallmentStatus::Diluted) {
            throw new InvalidArgumentException('Esta parcela não está marcada como pulada.');
        }

        $parcela->update(['status' => DebtInstallmentStatus::Open]);
        $this->refreshStatus($parcela->debt()->firstOrFail());

        return $parcela->refresh();
    }

    /**
     * Cria ou atualiza o consórcio a partir do extrato da administradora, já conferido pela pessoa (regra 5). O extrato é a fonte
     * da verdade: cada parcela paga entra com a data e o valor realmente pagos, as que ficaram para trás sem pagamento viram
     * "diluídas" e as demais ficam em aberto com a parcela atual. Rodar de novo com o mesmo extrato não muda nada (idempotente), e
     * parcela paga pelo Cerne (com despesa ligada) nunca é mexida.
     *
     * @param  array<string, mixed>  $d  consortium_type, name?, creditor?, consortium_group?, consortium_contract?, credit_amount, installment_amount,
     *                                   term, flex_percent?, adjustment_index?, adjustment_every_months?, last_adjustment_on?, anchor_number,
     *                                   anchor_due (vencimento da parcela anchor_number), statement_on?, statement_balance?, statement_remaining?,
     *                                   insurance_percent?, paid: list<array{number, paid_on, due_amount, paid_amount}>, member_id?, is_private?,
     *                                   goal_id?, goal_name?, bank_account_id?
     */
    public function importConsortiumStatement(array $d, ?Debt $existente = null, ?string $userId = null): Debt
    {
        $tipo = ConsortiumType::from($d['consortium_type']);
        $prazo = (int) $d['term'];
        $parcela = Money::parse($d['installment_amount'] ?? 0);
        $carta = Money::parse($d['credit_amount'] ?? 0);
        $ancora = (int) ($d['anchor_number'] ?? 0);

        if ($prazo < 1 || $prazo > 480) {
            throw new InvalidArgumentException('O prazo deve ficar entre 1 e 480 parcelas.');
        }

        if (bccomp($parcela, '0', 2) <= 0 || bccomp($carta, '0', 2) <= 0) {
            throw new InvalidArgumentException('Informe o valor da carta e o valor da parcela atual.');
        }

        if ($ancora < 1 || $ancora > $prazo || empty($d['anchor_due'])) {
            throw new InvalidArgumentException('Informe o número e o vencimento de uma parcela, para as datas do cronograma.');
        }

        if ($existente !== null && ! $existente->isConsortium()) {
            throw new InvalidArgumentException('O contrato escolhido não é um consórcio.');
        }

        $flex = $this->flexPercent($d);
        $pagas = collect($d['paid'] ?? [])->keyBy(fn (array $p) => (int) $p['number']);

        if ($pagas->keys()->contains(fn (int $n) => $n < 1 || $n > $prazo)) {
            throw new InvalidArgumentException('Há parcela paga fora do prazo do plano.');
        }

        $indice = ! empty($d['adjustment_index']) ? AdjustmentIndex::from($d['adjustment_index']) : $existente?->adjustment_index;
        $cada = $indice !== null ? (int) ($d['adjustment_every_months'] ?? $existente?->adjustment_every_months ?? 12) : null;
        $ultimoReajuste = ! empty($d['last_adjustment_on']) ? CarbonImmutable::parse($d['last_adjustment_on'])->startOfDay() : null;
        $primeira = CarbonImmutable::parse($d['anchor_due'])->startOfDay()->subMonthsNoOverflow($ancora - 1);
        $ultimaPaga = (int) ($pagas->keys()->max() ?? 0);

        $classificacao = $existente !== null && $existente->isContemplated()
            ? ['category_id' => $existente->category_id, 'subcategory_id' => $existente->subcategory_id]
            : DebtClassification::consortiumInvestment();

        if ($classificacao['category_id'] === null) {
            throw new InvalidArgumentException('A categoria de investimento "Consórcio" não foi encontrada.');
        }

        return DB::transaction(function () use ($d, $existente, $tipo, $prazo, $parcela, $carta, $flex, $pagas, $indice, $cada, $ultimoReajuste, $primeira, $ultimaPaga, $classificacao, $userId): Debt {
            $campos = [
                'consortium_type' => $tipo,
                'consortium_group' => $d['consortium_group'] ?? $existente?->consortium_group,
                'consortium_contract' => $d['consortium_contract'] ?? $existente?->consortium_contract,
                'credit_amount' => $carta,
                'is_flex' => $flex !== null,
                'flex_percent' => $flex,
                'adjustment_index' => $indice,
                'adjustment_every_months' => $cada,
                'last_adjustment_on' => $ultimoReajuste ?? $existente?->last_adjustment_on,
                'term' => $prazo,
                'first_due_date' => $primeira,
                'installment_amount' => $parcela,
                'statement_on' => $d['statement_on'] ?? null,
                'statement_balance' => ! empty($d['statement_balance']) ? Money::parse($d['statement_balance']) : null,
                'statement_remaining' => ! empty($d['statement_remaining']) ? (int) $d['statement_remaining'] : null,
                'insurance_percent' => isset($d['insurance_percent']) && $d['insurance_percent'] !== '' ? bcadd(str_replace(',', '.', (string) $d['insurance_percent']), '0', 6) : null,
            ];

            // O reajuste seguinte conta a partir do último que a administradora informou (e não da 1ª parcela).
            if ($indice !== null && $cada !== null) {
                $base = $ultimoReajuste ?? ($existente?->last_adjustment_on ? CarbonImmutable::instance($existente->last_adjustment_on) : null);
                $campos['next_adjustment_on'] = $base?->addMonthsNoOverflow($cada)
                    ?? $existente?->next_adjustment_on
                    ?? $primeira->addMonthsNoOverflow((int) ($d['adjustment_first_after_months'] ?? $cada));
                $campos['adjustment_first_after_months'] = $existente?->adjustment_first_after_months ?? (int) ($d['adjustment_first_after_months'] ?? $cada);
            } else {
                $campos['next_adjustment_on'] = null;
            }

            if ($existente === null) {
                $divida = Debt::create($campos + [
                    'member_id' => $d['member_id'] ?? null,
                    'is_private' => ($d['member_id'] ?? null) !== null && ! empty($d['is_private']),
                    'kind' => DebtKind::Consortium,
                    'status' => DebtStatus::Active,
                    'name' => ! empty($d['name']) ? $d['name'] : 'Consórcio de '.mb_strtolower($tipo->label()),
                    'creditor' => $d['creditor'] ?? null,
                    'amortization' => DebtAmortization::Fixed,
                    'necessity' => Necessity::Investment,
                    'category_id' => $classificacao['category_id'],
                    'subcategory_id' => $classificacao['subcategory_id'],
                    'bank_account_id' => $d['bank_account_id'] ?? null,
                    'created_by_user_id' => $userId ?? auth()->id(),
                ]);
            } else {
                $divida = $existente;
                $parcelaAntes = (string) $existente->installment_amount;
                $divida->update($campos + array_filter(['creditor' => $d['creditor'] ?? null]));
            }

            $linhas = DebtInstallment::query()->where('debt_id', $divida->id)->get()->keyBy('number');
            $parcelaAntes ??= $parcela;

            for ($n = 1; $n <= $prazo; $n++) {
                $vencimento = $primeira->addMonthsNoOverflow($n - 1);
                $linha = $linhas->get($n);
                $extrato = $pagas->get($n);

                // Paga pelo Cerne (despesa ligada): a despesa manda, o extrato não mexe.
                if ($linha !== null && $linha->expense_id !== null) {
                    continue;
                }

                if ($extrato !== null) {
                    $devido = Money::parse($extrato['due_amount'] ?? $extrato['paid_amount']);
                    $pago = Money::parse($extrato['paid_amount']);
                    $valores = [
                        'status' => DebtInstallmentStatus::Paid, 'due_date' => $vencimento, 'paid_on' => $extrato['paid_on'],
                        'paid_amount' => $pago, 'amount' => $devido, 'principal_amount' => $devido,
                    ];
                } elseif ($n < $ultimaPaga) {
                    $valores = ['status' => DebtInstallmentStatus::Diluted, 'due_date' => $vencimento, 'paid_on' => null, 'paid_amount' => null];
                } else {
                    // Em aberto: a parcela atual, a menos que a pessoa tenha ajustado esta ao boleto (valor diferente do padrão anterior).
                    $manteve = $linha !== null && $linha->status === DebtInstallmentStatus::Open && bccomp((string) $linha->amount, $parcelaAntes, 2) !== 0;
                    $valor = $manteve ? (string) $linha->amount : $parcela;
                    $valores = [
                        'status' => DebtInstallmentStatus::Open, 'due_date' => $vencimento, 'paid_on' => null, 'paid_amount' => null,
                        'amount' => $valor, 'principal_amount' => $valor,
                    ];
                }

                if ($linha === null) {
                    DebtInstallment::create($valores + [
                        'debt_id' => $divida->id, 'member_id' => $divida->member_id, 'is_private' => $divida->is_private,
                        'number' => $n, 'amount' => $parcela, 'principal_amount' => $parcela, 'interest_amount' => '0.00', 'fees_amount' => '0.00',
                    ]);
                } else {
                    $linha->update($valores);
                }
            }

            DebtInstallment::query()->where('debt_id', $divida->id)->where('number', '>', $prazo)->whereNull('expense_id')->delete();

            $divida->update(['paid_before' => DebtInstallment::query()->where('debt_id', $divida->id)->where('status', DebtInstallmentStatus::Paid)->whereNull('expense_id')->count()]);

            $this->linkGoal($divida, $d['goal_id'] ?? null, $d['goal_name'] ?? null);
            $this->refreshStatus($divida);

            return $divida->refresh();
        });
    }

    // ------------------------------------------------------------------ dívida em aberto

    /**
     * Cadastra uma dívida que ainda não está sendo paga (cartão atrasado, dívida a negociar). Não gera parcela nem despesa: conta
     * no total devedor, mas fora das parcelas do mês, até haver um acordo (registerAgreement).
     *
     * @param  array<string, mixed>  $d  name, creditor?, principal? (valor original), current_balance, balance_updated_on?, status? (open|negotiating),
     *                                   notes?, member_id?, is_private?
     */
    public function createOpenDebt(array $d, ?string $userId = null): Debt
    {
        $saldo = Money::parse($d['current_balance'] ?? 0);

        if (bccomp($saldo, '0', 2) <= 0) {
            throw new InvalidArgumentException('Informe o saldo atual da dívida.');
        }

        $situacao = DebtStatus::from($d['status'] ?? DebtStatus::Open->value);

        if (! $situacao->isUnscheduled()) {
            throw new InvalidArgumentException('Escolha se a dívida está em aberto ou em negociação.');
        }

        return Debt::create([
            'member_id' => $d['member_id'] ?? null,
            'is_private' => ($d['member_id'] ?? null) !== null && ! empty($d['is_private']),
            'kind' => DebtKind::Debt,
            'status' => $situacao,
            'name' => $d['name'],
            'creditor' => $d['creditor'] ?? null,
            'principal' => ! empty($d['principal']) ? Money::parse($d['principal']) : $saldo,
            'amortization' => DebtAmortization::Fixed,
            'term' => 0,
            'first_due_date' => null,
            'necessity' => Necessity::Essential,
            'current_balance' => $saldo,
            'balance_updated_on' => $d['balance_updated_on'] ?? now()->toDateString(),
            'notes' => $d['notes'] ?? null,
            'created_by_user_id' => $userId ?? auth()->id(),
        ])->refresh();
    }

    /** Atualiza o saldo de uma dívida que ainda não tem acordo (juros e multa correm) e, se quiser, a situação. */
    public function updateBalance(Debt $divida, string $saldo, ?CarbonInterface $data = null, ?DebtStatus $situacao = null): Debt
    {
        $this->requireUnscheduled($divida);

        $novo = Money::parse($saldo);

        if (bccomp($novo, '0', 2) < 0) {
            throw new InvalidArgumentException('O saldo não pode ser negativo.');
        }

        if ($situacao !== null && ! $situacao->isUnscheduled()) {
            throw new InvalidArgumentException('Escolha se a dívida está em aberto ou em negociação.');
        }

        $divida->update(['current_balance' => $novo, 'balance_updated_on' => $data ?? now(), 'status' => $situacao ?? $divida->status]);

        return $divida->refresh();
    }

    /**
     * Registra o acordo: o valor negociado em N parcelas. A dívida passa a ter cronograma (e a ter parcelas no mês), e ficam
     * guardados o valor original, o saldo na hora do acordo e o desconto obtido.
     *
     * @param  array{total: string, installments: int|string, first_due_date: string, category_id: string, subcategory_id?: ?string, bank_account_id?: ?string}  $d
     */
    public function registerAgreement(Debt $divida, array $d): Debt
    {
        $this->requireUnscheduled($divida);

        $total = Money::parse($d['total'] ?? 0);
        $n = (int) $d['installments'];

        if (bccomp($total, '0', 2) <= 0) {
            throw new InvalidArgumentException('Informe o valor negociado.');
        }

        if ($n < 1 || $n > 480) {
            throw new InvalidArgumentException('Informe de 1 a 480 parcelas.');
        }

        $this->validateClassification($d['category_id'] ?? null, $d['subcategory_id'] ?? null);

        $primeira = CarbonImmutable::parse($d['first_due_date'])->startOfDay();
        $desconto = bcsub((string) $divida->current_balance, $total, 2);

        return DB::transaction(function () use ($divida, $d, $total, $n, $primeira, $desconto): Debt {
            $base = bcdiv($total, (string) $n, 2);
            $resto = bcsub($total, bcmul($base, (string) $n, 2), 2);

            for ($i = 0; $i < $n; $i++) {
                $valor = $i === $n - 1 ? bcadd($base, $resto, 2) : $base;

                DebtInstallment::create([
                    'debt_id' => $divida->id, 'member_id' => $divida->member_id, 'is_private' => $divida->is_private,
                    'number' => $i + 1, 'due_date' => $primeira->addMonthsNoOverflow($i),
                    'principal_amount' => $valor, 'interest_amount' => '0.00', 'fees_amount' => '0.00', 'amount' => $valor,
                    'status' => DebtInstallmentStatus::Open,
                ]);
            }

            $divida->update([
                'status' => DebtStatus::Active,
                'term' => $n,
                'first_due_date' => $primeira,
                'installment_amount' => bcdiv($total, (string) $n, 2),
                'agreement_on' => now()->toDateString(),
                'agreement_total' => $total,
                'agreement_discount' => bccomp($desconto, '0', 2) > 0 ? $desconto : '0.00',
                'necessity' => Necessity::Essential,
                'category_id' => $d['category_id'],
                'subcategory_id' => $d['subcategory_id'] ?? null,
                'bank_account_id' => $d['bank_account_id'] ?? null,
            ]);

            return $divida->refresh();
        });
    }

    /** Dá a dívida (sem acordo) como quitada: pagou por fora, foi perdoada ou prescreveu. */
    public function markPaidOff(Debt $divida): Debt
    {
        $this->requireUnscheduled($divida);

        $divida->update(['status' => DebtStatus::PaidOff, 'current_balance' => '0.00', 'balance_updated_on' => now()]);

        return $divida->refresh();
    }

    // ------------------------------------------------------------------ resumo

    /**
     * Totais para a lista e o painel. Arrays de texto, sem models: o painel guarda isto em cache.
     *
     * @return array{total: string, consortium_credit: string, month_total: string, month_paid: string, month_open: string, next: list<array<string, string>>}
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
        // Consórcio ainda não contemplado não é dívida (é dinheiro juntado para a carta): as parcelas dele não entram no devedor.
        $informado = (string) $abertasAtivas()
            ->whereHas('debt', fn ($q) => $q->where('amortization', DebtAmortization::Fixed->value)
                ->where(fn ($w) => $w->where('kind', '!=', DebtKind::Consortium->value)->orWhereNotNull('contemplated_on')))
            ->sum('amount');
        // Dívida em aberto (sem acordo): conta pelo saldo atual, mesmo sem parcelas.
        $emAberto = (string) Debt::query()->whereIn('status', [DebtStatus::Open->value, DebtStatus::Negotiating->value])->sum('current_balance');
        $cartas = (string) Debt::query()->where('kind', DebtKind::Consortium->value)->where('status', DebtStatus::Active->value)->whereNull('contemplated_on')->sum('credit_amount');

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
            'total' => Money::parse(bcadd(bcadd(Money::parse($calculado), Money::parse($informado), 2), Money::parse($emAberto), 2)),
            'consortium_credit' => Money::parse($cartas),
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

    /** Parcelas de valor igual (consórcio, acordo): as `$jaPagas` primeiras nascem pagas; a numeração segue a partir de `$aPartirDe`. */
    private function generateFixedInstallments(Debt $divida, int $total, string $valor, CarbonImmutable $primeira, int $jaPagas, int $aPartirDe = 0): void
    {
        for ($n = $aPartirDe + 1; $n <= $total; $n++) {
            $vencimento = $primeira->addMonthsNoOverflow($n - $aPartirDe - 1);
            $paga = $n <= $jaPagas;

            DebtInstallment::create([
                'debt_id' => $divida->id, 'member_id' => $divida->member_id, 'is_private' => $divida->is_private,
                'number' => $n, 'due_date' => $vencimento, 'principal_amount' => $valor, 'interest_amount' => '0.00', 'fees_amount' => '0.00',
                'amount' => $valor, 'status' => $paga ? DebtInstallmentStatus::Paid : DebtInstallmentStatus::Open,
                'paid_on' => $paga ? $vencimento : null, 'paid_amount' => $paga ? $valor : null,
            ]);
        }
    }

    /** Liga o consórcio a um objetivo existente ou cria um (valor do objetivo = a carta). */
    private function linkGoal(Debt $divida, ?string $goalId, ?string $goalName): void
    {
        if ($goalId !== null && $goalId !== '') {
            Goal::query()->findOrFail($goalId)->update(['linked_debt_id' => $divida->id]);

            return;
        }

        if ($goalName !== null && trim($goalName) !== '') {
            Goal::create([
                'member_id' => $divida->member_id, 'is_private' => $divida->is_private, 'name' => trim($goalName),
                'priority' => (int) Goal::query()->max('priority') + 1, 'estimated_value' => $divida->credit_amount,
                'funding_method' => \App\Enums\FundingMethod::Installments, 'installment_amount' => $divida->installment_amount,
                'current_amount' => '0.00', 'status' => GoalStatus::Active, 'linked_debt_id' => $divida->id, 'created_by_user_id' => auth()->id(),
            ]);
        }
    }

    /** O objetivo ligado ao consórcio acompanha o reajuste: mesmo percentual no valor-alvo. */
    private function scaleLinkedGoal(Debt $divida, string $fator): void
    {
        foreach (Goal::query()->where('linked_debt_id', $divida->id)->get() as $objetivo) {
            $objetivo->update(['estimated_value' => bcadd(bcmul((string) $objetivo->estimated_value, $fator, 8), '0.005', 2)]);
        }
    }

    private function requireUnscheduled(Debt $divida): void
    {
        if ($divida->kind !== DebtKind::Debt || ! $divida->status->isUnscheduled()) {
            throw new InvalidArgumentException('Esta dívida já tem acordo ou não é uma dívida em aberto.');
        }
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
