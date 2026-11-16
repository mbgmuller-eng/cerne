<?php

namespace App\Services;

use App\Enums\DebtInstallmentStatus;
use App\Enums\DebtKind;
use App\Enums\DebtStatus;
use App\Enums\InsuranceType;
use App\Enums\Necessity;
use App\Models\BankAccount;
use App\Models\BudgetRule;
use App\Models\Debt;
use App\Models\DebtInstallment;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\FixedBill;
use App\Models\IncomeRecord;
use App\Models\InsurancePolicy;
use App\Models\RecurringIncome;
use App\Models\RecurringIncomeOccurrence;
use App\Support\Budget\BudgetStructure;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * A regra de orçamento de um mês: quanto DEVERIA ir para cada área e para cada item de investimento (pela receita do mês) e quanto efetivamente foi.
 *
 * Tudo roda sob o ProfileContext de quem pergunta, então vale a privacidade do casal: a receita e os gastos são os que ESSA pessoa enxerga (o
 * consultor vinculado enxerga tudo). Para outra pessoa ou um cliente, quem chama monta o contexto dela (ver `BudgetGapNotifier`).
 *
 * Receita do mês = o que já entrou (lançamentos de receita) + o que ainda está previsto nas receitas recorrentes (ocorrência pendente ou atrasada
 * do mês). Quando a ocorrência é recebida, vira lançamento e deixa de ser "prevista": nunca conta duas vezes pelo caminho do Cerne.
 *
 * Os compromissos fixos (seguro de vida, parcela de consórcio ainda não contemplado, conta fixa de investimento) saem do balde do grupo ANTES de ele
 * ser dividido em percentuais, e o valor vem do que está CADASTRADO (apólices, dívidas, contas fixas). O que foi LANÇADO no mês confere depois
 * (`validation`). Somas em SQL (regra 3); só o que é limitado por mês (algumas apólices, contas fixas) soma em PHP.
 */
class BudgetRuleService
{
    public function ruleFor(int $year, int $month): ?BudgetRule
    {
        return BudgetRule::query()->validFor($year, $month)->first();
    }

    /**
     * Grava a regra de um perfil a partir de um mês: salvar de novo o mesmo mês atualiza a mesma linha (índice único, regra 4). Quem chama já
     * validou a estrutura (`BudgetStructure::validate`) e já conferiu que pode mexer neste perfil; aqui o `profileId` vai explícito porque o
     * consultor aplica modelo em vários clientes de uma vez, sem perfil ativo.
     *
     * @param  array<string, mixed>  $estrutura
     */
    public function save(string $profileId, int $year, int $month, array $estrutura, ?string $userId): BudgetRule
    {
        $dados = ['structure' => BudgetStructure::normalize($estrutura), 'created_by_user_id' => $userId];
        $chave = ['profile_id' => $profileId, 'effective_year' => $year, 'effective_month' => $month];

        $atualizar = function () use ($chave, $dados): ?BudgetRule {
            $existente = BudgetRule::withoutProfileScope()->where($chave)->first();

            if ($existente === null) {
                return null;
            }

            $existente->update($dados);

            return $existente;
        };

        if (($regra = $atualizar()) !== null) {
            return $regra;
        }

        try {
            return BudgetRule::withoutProfileScope()->create($chave + $dados);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Outra aba (ou o consultor) criou o mesmo mês no meio do caminho: a linha agora existe, e só falta atualizá-la.
            return $atualizar() ?? throw new \RuntimeException('Não foi possível gravar a regra de orçamento.');
        }
    }

    /** @return array{received: string, expected: string, total: string} */
    public function income(int $year, int $month): array
    {
        $recebida = Money::parse((string) IncomeRecord::query()->forPeriod($year, $month)->sum('amount'));

        // Receita recorrente ainda por entrar neste mês. Semanal tem várias ocorrências no mês, então conta quantas há de cada receita.
        $porReceita = RecurringIncomeOccurrence::query()->forPeriod($year, $month)->outstanding()
            ->selectRaw('recurring_income_id, COUNT(*) as vezes')->groupBy('recurring_income_id')->pluck('vezes', 'recurring_income_id');

        $prevista = '0.00';

        if ($porReceita->isNotEmpty()) {
            foreach (RecurringIncome::query()->where('is_active', true)->whereIn('id', $porReceita->keys())->get(['id', 'amount']) as $r) {
                $prevista = bcadd($prevista, bcmul((string) $r->amount, (string) $porReceita[$r->id], 2), 2);
            }
        }

        return ['received' => $recebida, 'expected' => $prevista, 'total' => bcadd($recebida, $prevista, 2)];
    }

    /**
     * O relatório do mês. Nulo quando o perfil não tem regra que valha para ele.
     *
     * @param  array<string, mixed>|null  $estrutura  para simular uma regra que ainda não foi salva (a prévia do editor)
     * @return array<string, mixed>|null
     */
    public function report(int $year, int $month, ?array $estrutura = null): ?array
    {
        $regra = $estrutura === null ? $this->ruleFor($year, $month) : null;

        if ($estrutura === null && $regra === null) {
            return null;
        }

        $s = BudgetStructure::normalize($estrutura ?? (array) $regra->structure);
        $renda = $this->income($year, $month);
        $base = $renda['total'];

        [$porNecessidade, $porCategoria] = $this->realized($year, $month);
        $categorias = $this->investmentCategoryIds();
        $cache = [];

        $areas = [];

        foreach (['essential', 'discretionary'] as $area) {
            $meta = $this->portion($base, (string) $s['areas'][$area]);
            $feito = $porNecessidade[$area] ?? '0.00';
            $areas[$area] = $this->node(BudgetStructure::AREAS[$area], $s['areas'][$area], $meta, $feito, 'max');
        }

        $metaInvest = $this->portion($base, (string) $s['areas']['investment']);
        $feitoInvest = $porNecessidade['investment'] ?? '0.00';
        $areas['investment'] = $this->node(BudgetStructure::AREAS['investment'], $s['areas']['investment'], $metaInvest, $feitoInvest, 'min');

        // Grupos: o balde de investimentos dividido pelos percentuais (a diferença de centavos fica no último).
        $metasGrupos = $this->splitWeighted($metaInvest, array_map(fn ($g) => (string) $g['percent'], $s['groups']));
        $usadas = [];
        $grupos = [];
        $totalGrupos = '0.00';

        foreach ($s['groups'] as $i => $g) {
            $grupo = $this->groupReport($g, $metasGrupos[$i], $porCategoria, $categorias, $year, $month, $cache, $usadas);
            $totalGrupos = bcadd($totalGrupos, $grupo['realized'], 2);
            $grupos[] = $grupo;
        }

        $areas['investment']['groups'] = $grupos;

        // O que foi lançado como investimento numa categoria que nenhum item da regra reivindica: aparece, para nada sumir da conta.
        $semDestino = [];

        foreach ($porCategoria as $chave => $linha) {
            if (! isset($usadas[$chave]) && bccomp($linha['total'], '0', 2) !== 0) {
                $semDestino[] = ['name' => $linha['name'], 'amount' => $linha['total']];
            }
        }

        // Sem grupos a meta vale para o investimento como um todo e não há onde "mapear": nada a listar como sem destino.
        $areas['investment']['unmapped'] = $s['groups'] === [] ? [] : $semDestino;

        return [
            'year' => $year,
            'month' => $month,
            'effective' => $regra !== null ? [$regra->effective_year, $regra->effective_month] : null,
            'income' => $renda,
            'areas' => $areas,
            'double_count' => $this->doubleCountHints($year, $month),
        ];
    }

    // ------------------------------------------------------------------ grupos e itens

    /**
     * @param  array<string, mixed>  $g
     * @param  array<string, array{name: string, total: string}>  $porCategoria
     * @param  array<string, list<string>>  $categorias  nome comparável => ids das categorias de investimento
     * @param  array<string, array{amount: string, detail: string}>  $cache
     * @param  array<string, true>  $usadas
     * @return array<string, mixed>
     */
    private function groupReport(array $g, string $meta, array $porCategoria, array $categorias, int $ano, int $mes, array &$cache, array &$usadas): array
    {
        $fixoTotal = '0.00';
        $itens = [];

        // 1) Compromissos fixos primeiro: saem do balde com o valor cadastrado.
        foreach ($g['items'] as $it) {
            $comprometido = null;

            if ($it['kind'] === 'fixed') {
                $comprometido = $this->committed($it, $ano, $mes, $categorias, $cache);
                $fixoTotal = bcadd($fixoTotal, $comprometido['amount'], 2);
            }

            $itens[] = ['rule' => $it, 'committed' => $comprometido];
        }

        // 2) O que sobra divide pelos percentuais dos demais itens.
        $sobra = bccomp($meta, $fixoTotal, 2) > 0 ? bcsub($meta, $fixoTotal, 2) : '0.00';
        $pesos = [];
        $indices = [];

        foreach ($itens as $i => $linha) {
            if ($linha['rule']['kind'] !== 'fixed') {
                $pesos[] = (string) $linha['rule']['percent'];
                $indices[] = $i;
            }
        }

        $partes = $this->splitWeighted($sobra, $pesos);
        $saida = [];
        $realizadoGrupo = '0.00';

        foreach ($itens as $i => $linha) {
            $it = $linha['rule'];
            $feito = '0.00';

            foreach ($it['categories'] as $nome) {
                $chave = BudgetStructure::fold($nome);
                $usadas[$chave] = true;
                $feito = bcadd($feito, $porCategoria[$chave]['total'] ?? '0.00', 2);
            }

            $alvo = $it['kind'] === 'fixed' ? $linha['committed']['amount'] : $partes[array_search($i, $indices, true)];
            $no = $this->node($it['name'], $it['kind'] === 'fixed' ? null : $it['percent'], $alvo, $feito, 'min');
            $no['key'] = $it['key'];
            $no['kind'] = $it['kind'];
            $no['categories'] = $it['categories'];

            if ($it['kind'] === 'fixed') {
                $no['committed'] = $linha['committed']['amount'];
                $no['committed_detail'] = $linha['committed']['detail'];
                $no['validation'] = $this->validation($linha['committed']['amount'], $feito);
            }

            $realizadoGrupo = bcadd($realizadoGrupo, $feito, 2);
            $saida[] = $no;
        }

        $grupo = $this->node($g['name'], $g['percent'], $meta, $realizadoGrupo, 'min');
        $grupo['key'] = $g['key'];
        $grupo['fixed_total'] = $fixoTotal;
        $grupo['remaining'] = $sobra;
        $grupo['over_commitment'] = bccomp($fixoTotal, $meta, 2) > 0 ? bcsub($fixoTotal, $meta, 2) : null;
        $grupo['items'] = $saida;

        return $grupo;
    }

    /**
     * Um nó da árvore: meta, o que foi, o que falta (investimento) ou o que passou (limite) e a situação.
     *
     * @return array<string, mixed>
     */
    private function node(string $nome, ?string $percentual, string $meta, string $feito, string $sentido): array
    {
        $falta = bccomp($meta, $feito, 2) > 0 ? bcsub($meta, $feito, 2) : '0.00';
        $passou = bccomp($feito, $meta, 2) > 0 ? bcsub($feito, $meta, 2) : '0.00';

        if ($sentido === 'max') {
            $situacao = bccomp($passou, '0', 2) > 0 ? 'over' : 'ok';
        } elseif (bccomp($meta, '0', 2) <= 0) {
            $situacao = bccomp($feito, '0', 2) > 0 ? 'reached' : 'none';
        } elseif (bccomp($feito, $meta, 2) >= 0) {
            $situacao = 'reached';
        } else {
            $situacao = bccomp($feito, '0', 2) > 0 ? 'partial' : 'none';
        }

        return [
            'name' => $nome, 'percent' => $percentual, 'target' => $meta, 'realized' => $feito, 'missing' => $falta, 'over' => $passou,
            'direction' => $sentido, 'status' => $situacao, 'progress' => min(100.0, Money::percentageOf($feito, $meta)),
        ];
    }

    /** O lançado confere com o cadastrado? paid (pago ou mais), partial, pending (nada lançado) ou unregistered (nada cadastrado nem lançado). */
    private function validation(string $cadastrado, string $lancado): string
    {
        if (bccomp($cadastrado, '0', 2) <= 0) {
            return bccomp($lancado, '0', 2) > 0 ? 'paid' : 'unregistered';
        }

        if (bccomp($lancado, $cadastrado, 2) >= 0) {
            return 'paid';
        }

        return bccomp($lancado, '0', 2) > 0 ? 'partial' : 'pending';
    }

    // ------------------------------------------------------------------ compromissos fixos (o que está cadastrado)

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, list<string>>  $categorias
     * @param  array<string, array{amount: string, detail: string}>  $cache
     * @return array{amount: string, detail: string}
     */
    private function committed(array $item, int $ano, int $mes, array $categorias, array &$cache): array
    {
        $total = '0.00';
        $detalhes = [];

        foreach ($item['sources'] as $origem) {
            $chave = $origem === 'fixed_bills' ? $origem.'|'.implode(',', array_map([BudgetStructure::class, 'fold'], $item['categories'])) : $origem;

            $cache[$chave] ??= match ($origem) {
                'life_insurance' => $this->lifeInsurance(),
                'consortium' => $this->consortium($ano, $mes),
                'fixed_bills' => $this->fixedBills($item['categories'], $ano, $mes, $categorias),
                default => ['amount' => '0.00', 'detail' => ''],
            };

            $total = bcadd($total, $cache[$chave]['amount'], 2);

            if ($cache[$chave]['detail'] !== '') {
                $detalhes[] = $cache[$chave]['detail'];
            }
        }

        return ['amount' => $total, 'detail' => implode('; ', $detalhes)];
    }

    /** O custo mensal das apólices de vida ativas (a de pagamento anual entra em 1/12). Apólice sem prêmio informado não conta. */
    private function lifeInsurance(): array
    {
        $total = '0.00';
        $quantas = 0;

        foreach (InsurancePolicy::query()->active()->where('insurance_type', InsuranceType::Vida->value)->get() as $apolice) {
            if ($apolice->monthly_premium === null && $apolice->annual_premium === null) {
                continue;
            }

            $custo = $apolice->monthly_premium !== null
                ? $apolice->normalizedMonthlyCost()
                : Money::parse(bcdiv((string) $apolice->annual_premium, '12', 2));

            if (bccomp($custo, '0', 2) > 0) {
                $total = bcadd($total, $custo, 2);
                $quantas++;
            }
        }

        return ['amount' => $total, 'detail' => $quantas > 0 ? $quantas.' '.($quantas === 1 ? 'apólice de vida' : 'apólices de vida') : ''];
    }

    /** As parcelas do mês dos consórcios ainda não contemplados (depois da contemplação a parcela vira despesa comum e sai daqui). */
    private function consortium(int $ano, int $mes): array
    {
        $inicio = CarbonImmutable::create($ano, $mes, 1)->startOfMonth();

        $consorcios = Debt::query()->where('kind', DebtKind::Consortium->value)->where('status', DebtStatus::Active->value)->whereNull('contemplated_on')->select('id');

        $consulta = DebtInstallment::query()
            ->whereIn('debt_id', $consorcios)
            ->whereIn('status', [DebtInstallmentStatus::Open->value, DebtInstallmentStatus::Paid->value])
            ->whereBetween('due_date', [$inicio->toDateString(), $inicio->endOfMonth()->toDateString()]);

        $quantas = (clone $consulta)->count();

        return ['amount' => Money::parse((string) $consulta->sum('amount')), 'detail' => $quantas > 0 ? $quantas.' '.($quantas === 1 ? 'parcela de consórcio' : 'parcelas de consórcio') : ''];
    }

    /**
     * Contas fixas de investimento nas categorias do item, vezes quantas vezes vencem no mês.
     *
     * @param  list<string>  $nomes
     * @param  array<string, list<string>>  $categorias
     */
    private function fixedBills(array $nomes, int $ano, int $mes, array $categorias): array
    {
        $ids = [];

        foreach ($nomes as $nome) {
            $ids = array_merge($ids, $categorias[BudgetStructure::fold($nome)] ?? []);
        }

        if ($ids === []) {
            return ['amount' => '0.00', 'detail' => ''];
        }

        $total = '0.00';
        $contas = 0;

        foreach (FixedBill::query()->where('is_active', true)->where('necessity', Necessity::Investment->value)->whereIn('category_id', $ids)->get() as $conta) {
            $vezes = count($conta->occurrencesInMonth($ano, $mes));

            if ($vezes > 0) {
                $total = bcadd($total, bcmul((string) $conta->amount, (string) $vezes, 2), 2);
                $contas++;
            }
        }

        return ['amount' => $total, 'detail' => $contas > 0 ? $contas.' '.($contas === 1 ? 'conta fixa' : 'contas fixas') : ''];
    }

    // ------------------------------------------------------------------ o que foi lançado

    /**
     * Gasto do mês por necessidade e, dentro de investimento, por categoria (nome comparável), tudo em SQL agrupado.
     *
     * @return array{0: array<string, string>, 1: array<string, array{name: string, total: string}>}
     */
    private function realized(int $ano, int $mes): array
    {
        $linhas = ExpenseRecord::query()->forPeriod($ano, $mes)
            ->selectRaw('necessity, category_id, SUM(amount) as total')->groupBy('necessity', 'category_id')->get();

        $nomes = ExpenseCategory::query()->whereIn('id', $linhas->pluck('category_id')->filter()->unique())->pluck('name', 'id');

        $porNecessidade = [];
        $porCategoria = [];

        foreach ($linhas as $linha) {
            $necessidade = $linha->necessity instanceof Necessity ? $linha->necessity->value : (string) $linha->necessity;
            $total = Money::parse((string) $linha->total);
            $porNecessidade[$necessidade] = bcadd($porNecessidade[$necessidade] ?? '0.00', $total, 2);

            if ($necessidade === Necessity::Investment->value) {
                $nome = (string) ($nomes[$linha->category_id] ?? 'Sem categoria');
                $chave = BudgetStructure::fold($nome);
                $porCategoria[$chave] = ['name' => $nome, 'total' => bcadd($porCategoria[$chave]['total'] ?? '0.00', $total, 2)];
            }
        }

        return [$porNecessidade, $porCategoria];
    }

    /**
     * Categorias de investimento por nome comparável (padrão do sistema e as do perfil com o mesmo nome valem juntas).
     *
     * @return array<string, list<string>>
     */
    private function investmentCategoryIds(): array
    {
        $mapa = [];

        foreach (ExpenseCategory::query()->where('necessity', Necessity::Investment->value)->get(['id', 'name']) as $c) {
            $mapa[BudgetStructure::fold($c->name)][] = $c->id;
        }

        return $mapa;
    }

    /**
     * O mesmo valor lançado como investimento em duas contas, com dias de diferença, sendo uma delas de investimento: quase sempre é a
     * transferência para a corretora lançada como aporte E a compra do ativo lançada de novo. Só avisa; nunca apaga nem soma por conta própria.
     *
     * @return list<array{amount: string, first: string, second: string}>
     */
    private function doubleCountHints(int $ano, int $mes): array
    {
        $lancamentos = ExpenseRecord::query()->forPeriod($ano, $mes)->where('necessity', Necessity::Investment->value)->whereNotNull('bank_account_id')
            ->orderBy('expense_date')->limit(400)->get(['id', 'description', 'amount', 'expense_date', 'bank_account_id']);

        if ($lancamentos->count() < 2) {
            return [];
        }

        $investimento = BankAccount::query()->whereIn('id', $lancamentos->pluck('bank_account_id')->unique())->where('account_type', 'investment_account')->pluck('id')->all();
        $avisos = [];

        foreach ($lancamentos->groupBy(fn ($l) => (string) $l->amount) as $valor => $grupo) {
            $grupo = $grupo->values();

            for ($i = 0; $i < $grupo->count(); $i++) {
                for ($j = $i + 1; $j < $grupo->count(); $j++) {
                    [$a, $b] = [$grupo[$i], $grupo[$j]];

                    $outraConta = $a->bank_account_id !== $b->bank_account_id;
                    $umaDeInvestimento = in_array($a->bank_account_id, $investimento, true) || in_array($b->bank_account_id, $investimento, true);
                    $proximos = abs($a->expense_date->diffInDays($b->expense_date)) <= 5;

                    if ($outraConta && $umaDeInvestimento && $proximos) {
                        $avisos[] = ['amount' => Money::parse($valor), 'first' => (string) $a->description, 'second' => (string) $b->description];
                    }
                }
            }
        }

        return array_slice($avisos, 0, 5);
    }

    // ------------------------------------------------------------------ contas de dinheiro

    /** Percentual da receita (texto com 2 casas), arredondado como dinheiro. */
    private function portion(string $total, string $percentual): string
    {
        return Money::parse(bcdiv(bcmul($total, $percentual, 6), '100', 4));
    }

    /**
     * Divide um total pelos pesos, em centavos exatos: arredonda cada parte e a diferença fica na última, então a soma fecha sempre.
     *
     * @param  list<string>  $pesos
     * @return list<string>
     */
    private function splitWeighted(string $total, array $pesos): array
    {
        if ($pesos === []) {
            return [];
        }

        $soma = '0';

        foreach ($pesos as $p) {
            $soma = bcadd($soma, $p, 4);
        }

        if (bccomp($soma, '0', 4) <= 0) {
            return array_fill(0, count($pesos), '0.00');
        }

        $partes = [];
        $dado = '0.00';
        $ultimo = count($pesos) - 1;

        foreach ($pesos as $i => $p) {
            $parte = $i === $ultimo ? bcsub($total, $dado, 2) : Money::parse(bcdiv(bcmul($total, $p, 6), $soma, 4));
            $parte = bccomp($parte, '0', 2) < 0 ? '0.00' : $parte;
            $partes[] = $parte;
            $dado = bcadd($dado, $parte, 2);
        }

        return $partes;
    }
}
