<?php

namespace App\Services\Analytics;

use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\ExpenseSubcategory;
use App\Services\DashboardService;
use App\Support\Charts\ChartPalette;
use App\Support\Charts\SvgCharts;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Análises do fluxo de caixa: quatro gráficos (rosca por categoria, colunas por mês, ritmo de gasto no mês e destino de cada R$ 100 de receita)
 * montados na MESMA estrutura que a tela e o PDF leem. Toda consulta passa pelos escopos dos models (perfil e privacidade do casal) e soma em SQL,
 * então o que é privado do cônjuge não entra no total de quem não o enxerga, nem por subtração.
 *
 * @phpstan-type Secao array{id: string, titulo: string, sub: string, svg: string, legenda: list<array{0: string, 1: string, 2?: string, 3?: string}>, tabela: array{colunas: list<string>, linhas: list<list<string>>}, nota?: string}
 */
class CashFlowAnalytics
{
    private const MAX_FATIAS = 7;

    private const MESES_JANELA = 6;

    public function __construct(private DashboardService $dashboard) {}

    /** @return array<string, mixed> */
    public function report(int $ano, int $mes, ChartPalette $p): array
    {
        $ref = CarbonImmutable::create($ano, $mes, 1);
        $emAndamento = $ref->isSameMonth(CarbonImmutable::now());
        $resumo = $this->dashboard->monthSummary($ano, $mes);

        $porCategoria = $this->categoriasDoMes($ano, $mes);
        $janela = $this->janelaPorCategoria($ref);
        $cores = CategoryColors::assign(array_values(array_unique(array_merge(array_column($porCategoria, 'nome'), array_keys($janela['totais'])))), $p);

        return [
            'titulo' => 'Análises do fluxo de caixa',
            'periodo' => $ref->translatedFormat('F \d\e Y'),
            'kpis' => [
                ['Receitas', Money::format($resumo['receitas']), 'lançadas no mês'],
                ['Despesas', Money::format($resumo['despesas']), $emAndamento ? 'até hoje' : 'no mês'],
                ['Sobra', Money::format($resumo['sobra']), 'receitas menos despesas'],
                ['Taxa de poupança', number_format((float) $resumo['taxa_poupanca'], 1, ',', '.').'%', 'da receita que sobrou'],
            ],
            'secoes' => array_values(array_filter([
                $this->secaoCategorias($porCategoria, $cores, $p, $emAndamento),
                $this->secaoEvolucao($janela, $cores, $p, $emAndamento),
                $this->secaoRitmo($ref, $emAndamento, $p),
                $this->secaoDestino($resumo, $p),
            ])),
        ];
    }

    /**
     * As subcategorias de uma categoria no mês, para o "abrir" da rosca. O id vem do navegador: a categoria é buscada sob o escopo (404 se não for
     * visível a este perfil) e as somas já saem só do que a pessoa enxerga.
     *
     * @return array{categoria: string, linhas: list<array{nome: string, valor: string, pct: float}>}
     */
    public function subcategorias(int $ano, int $mes, string $categoriaId): array
    {
        $categoria = ExpenseCategory::query()->findOrFail($categoriaId);

        $linhas = ExpenseRecord::query()->forPeriod($ano, $mes)->where('category_id', $categoria->id)
            ->selectRaw('subcategory_id, SUM(amount) as total')->groupBy('subcategory_id')->orderByDesc('total')->get();

        $nomes = ExpenseSubcategory::query()->whereIn('id', $linhas->pluck('subcategory_id')->filter())->pluck('name', 'id');
        $total = Money::sum($linhas->pluck('total'));

        return [
            'categoria' => $categoria->name,
            'linhas' => $linhas->map(fn ($l) => [
                'nome' => $l->subcategory_id ? (string) ($nomes[$l->subcategory_id] ?? 'Sem subcategoria') : 'Sem subcategoria',
                'valor' => Money::parse($l->total),
                'pct' => Money::percentageOf($l->total, $total),
            ])->all(),
        ];
    }

    /** @return list<array{id: ?string, nome: string, valor: string}> */
    private function categoriasDoMes(int $ano, int $mes): array
    {
        $linhas = ExpenseRecord::query()->forPeriod($ano, $mes)->selectRaw('category_id, SUM(amount) as total')
            ->groupBy('category_id')->orderByDesc('total')->get();

        $nomes = ExpenseCategory::query()->whereIn('id', $linhas->pluck('category_id')->filter())->pluck('name', 'id');

        return $linhas->map(fn ($l) => [
            'id' => $l->category_id,
            'nome' => $l->category_id ? (string) ($nomes[$l->category_id] ?? 'Sem categoria') : 'Sem categoria',
            'valor' => Money::parse($l->total),
        ])->all();
    }

    /**
     * Despesas dos últimos meses por categoria. As cinco maiores da janela ficam separadas; o resto vira "Outras".
     *
     * @return array{rotulos: list<string>, totais: array<string, string>, series: array<string, list<string>>, meses: list<CarbonImmutable>}
     */
    private function janelaPorCategoria(CarbonImmutable $ref): array
    {
        $meses = [];
        for ($i = self::MESES_JANELA - 1; $i >= 0; $i--) {
            $meses[] = $ref->subMonths($i);
        }

        $linhas = ExpenseRecord::query()
            ->whereDate('expense_date', '>=', $meses[0]->startOfMonth()->toDateString())
            ->whereDate('expense_date', '<=', $ref->endOfMonth()->toDateString())
            ->selectRaw('year, month, category_id, SUM(amount) as total')->groupBy('year', 'month', 'category_id')->get();

        $nomes = ExpenseCategory::query()->whereIn('id', $linhas->pluck('category_id')->filter())->pluck('name', 'id');
        $nome = fn ($id): string => $id ? (string) ($nomes[$id] ?? 'Sem categoria') : 'Sem categoria';

        $totais = [];
        foreach ($linhas as $l) {
            $totais[$nome($l->category_id)] = bcadd($totais[$nome($l->category_id)] ?? '0', (string) $l->total, 2);
        }
        arsort($totais);
        $principais = array_slice(array_keys($totais), 0, 5);

        $series = [];
        foreach ($meses as $i => $m) {
            foreach ($linhas->where('year', $m->year)->where('month', $m->month) as $l) {
                $chave = in_array($nome($l->category_id), $principais, true) ? $nome($l->category_id) : 'Outras';
                $series[$chave] ??= array_fill(0, count($meses), '0.00');
                $series[$chave][$i] = bcadd($series[$chave][$i], (string) $l->total, 2);
            }
        }

        return [
            'rotulos' => array_map(fn (CarbonImmutable $m) => $m->translatedFormat('M'), $meses),
            'totais' => array_intersect_key($totais, array_flip($principais)),
            'series' => $series,
            'meses' => $meses,
        ];
    }

    /** @return array<string, mixed>|null */
    private function secaoCategorias(array $categorias, array $cores, ChartPalette $p, bool $emAndamento): ?array
    {
        if ($categorias === []) {
            return null;
        }

        $fatias = array_slice($categorias, 0, self::MAX_FATIAS);
        $resto = array_slice($categorias, self::MAX_FATIAS);
        if ($resto !== []) {
            $fatias[] = ['id' => null, 'nome' => 'Outras', 'valor' => Money::sum(array_column($resto, 'valor'))];
        }
        $total = Money::sum(array_column($fatias, 'valor'));

        $legenda = [];
        $linhas = [];
        foreach ($fatias as $f) {
            $cor = $cores[$f['nome']] ?? $p->gray;
            $pct = number_format(Money::percentageOf($f['valor'], $total), 0).'%';
            $legenda[] = [$cor, $f['nome'], Money::format($f['valor']).' · '.$pct, $f['id']];
            $linhas[] = [$f['nome'], Money::format($f['valor']), $pct];
        }

        return [
            'id' => 'categorias',
            'titulo' => 'Para onde foi o dinheiro',
            'sub' => 'Despesas do mês por categoria'.($emAndamento ? ', até hoje' : '').'. Clique em uma categoria para ver as subcategorias.',
            'svg' => SvgCharts::rosca(array_map(fn (array $f, array $l) => [$f['nome'], $f['valor'], $l[0]], $fatias, $legenda), $p, Money::format($total), 'em despesas', 200, 'Rosca com as despesas do mês por categoria'),
            'legenda' => $legenda,
            'tabela' => ['colunas' => ['Categoria', 'Valor', 'Parte'], 'linhas' => $linhas],
        ];
    }

    /** @return array<string, mixed>|null */
    private function secaoEvolucao(array $janela, array $cores, ChartPalette $p, bool $emAndamento): ?array
    {
        if ($janela['series'] === []) {
            return null;
        }

        // A ordem das séries: as principais por valor e "Outras" por último.
        $nomes = array_keys($janela['totais']);
        if (isset($janela['series']['Outras'])) {
            $nomes[] = 'Outras';
        }

        $series = [];
        $legenda = [];
        $linhas = [];
        foreach ($nomes as $nome) {
            $cor = $nome === 'Outras' ? $p->gray : ($cores[$nome] ?? $p->gray);
            $valores = $janela['series'][$nome] ?? array_fill(0, count($janela['rotulos']), '0.00');
            $series[] = [$nome, $valores, $cor];
            $legenda[] = [$cor, $nome];
        }
        foreach ($janela['rotulos'] as $i => $rotulo) {
            $linha = [$rotulo.($emAndamento && $i === count($janela['rotulos']) - 1 ? ' (em andamento)' : '')];
            $soma = '0';
            foreach ($series as $s) {
                $linha[] = Money::format($s[1][$i]);
                $soma = bcadd($soma, $s[1][$i], 2);
            }
            $linha[] = Money::format($soma);
            $linhas[] = $linha;
        }

        $rotulos = $janela['rotulos'];
        if ($emAndamento) {
            $rotulos[count($rotulos) - 1] .= '*';
        }

        return [
            'id' => 'evolucao',
            'titulo' => 'Como cada categoria evoluiu',
            'sub' => 'Despesas dos últimos '.self::MESES_JANELA.' meses'.($emAndamento ? '. O mês marcado com * ainda está aberto.' : '.'),
            'svg' => SvgCharts::colunasEmpilhadas($rotulos, $series, $p, 640, 260, 'Colunas empilhadas das despesas por mês e categoria'),
            'legenda' => $legenda,
            'tabela' => ['colunas' => array_merge(['Mês'], $nomes, ['Total']), 'linhas' => $linhas],
        ];
    }

    /** @return array<string, mixed>|null */
    private function secaoRitmo(CarbonImmutable $ref, bool $emAndamento, ChartPalette $p): ?array
    {
        $anterior = $ref->subMonth();
        $acumulado = function (CarbonImmutable $m): array {
            $porDia = ExpenseRecord::query()->forPeriod($m->year, $m->month)->selectRaw('DAY(expense_date) as dia, SUM(amount) as total')
                ->groupBy('dia')->pluck('total', 'dia');
            $soma = '0.00';
            $serie = [];
            for ($d = 1; $d <= $m->daysInMonth; $d++) {
                $soma = bcadd($soma, (string) ($porDia[$d] ?? 0), 2);
                $serie[$d] = $soma;
            }

            return [$serie, $porDia->isNotEmpty()];
        };

        [$serieAtual, $temAtual] = $acumulado($ref);
        [$serieAnterior, $temAnterior] = $acumulado($anterior);
        if (! $temAtual && ! $temAnterior) {
            return null;
        }

        $hoje = CarbonImmutable::now();
        // Mês futuro não tem o que mostrar; mês corrente vai até hoje; mês passado vai até o último dia.
        $corte = $ref->startOfMonth()->gt($hoje) ? 0 : ($emAndamento ? $hoje->day : $ref->daysInMonth);
        $dias = max($ref->daysInMonth, $anterior->daysInMonth);

        $atual = [];
        $ant = [];
        for ($d = 1; $d <= $dias; $d++) {
            $atual[] = $d <= $corte ? ($serieAtual[$d] ?? null) : null;
            $ant[] = $serieAnterior[$d] ?? null;
        }

        $nomeAtual = ucfirst($ref->translatedFormat('F')).($emAndamento ? ' (até o dia '.$corte.')' : '');
        $nomeAnterior = ucfirst($anterior->translatedFormat('F')).' (mês fechado)';
        $legenda = [[$p->gray, $nomeAnterior], [$p->serie(0), $nomeAtual]];

        $noCorte = $corte > 0 ? $serieAtual[$corte] : '0.00';
        $anteriorNoCorte = $serieAnterior[min(max($corte, 1), $anterior->daysInMonth)] ?? '0.00';
        $nota = null;
        if ($corte > 0 && bccomp($anteriorNoCorte, '0', 2) > 0) {
            $dif = Money::percentageOf(bcsub($noCorte, $anteriorNoCorte, 2), $anteriorNoCorte);
            $nota = 'Até o dia '.$corte.' você gastou '.Money::format($noCorte).'; no mês anterior, até o mesmo dia, '.Money::format($anteriorNoCorte).' ('
                .($dif === 0.0 ? 'igual' : number_format(abs($dif), 0, ',', '.').'% '.($dif < 0 ? 'a menos' : 'a mais')).').';
        }

        $linhas = [];
        foreach ([5, 10, 15, 20, 25, $dias] as $d) {
            if ($d > $dias) {
                continue;
            }
            $linhas[] = ['Dia '.$d, $atual[$d - 1] === null ? '—' : Money::format($atual[$d - 1]), $ant[$d - 1] === null ? '—' : Money::format($ant[$d - 1])];
        }

        return [
            'id' => 'ritmo',
            'titulo' => 'Estou gastando mais rápido que o normal?',
            'sub' => 'Despesa acumulada dia a dia, contra o mês anterior.',
            'svg' => SvgCharts::linhas(array_map('strval', range(1, $dias)), [[$nomeAnterior, $ant, $p->gray], [$nomeAtual, $atual, $p->serie(0)]], $p, 640, 230, 'Despesa acumulada por dia no mês e no mês anterior'),
            'legenda' => $legenda,
            'tabela' => ['colunas' => ['Dia', $nomeAtual, $nomeAnterior], 'linhas' => $linhas],
            'nota' => $nota,
        ];
    }

    /**
     * Para onde vai cada R$ 100 de receita. Sem receita lançada não há base para dividir, e a seção some. Despesa maior que a receita não tem
     * "sobra": as partes passam a ser a divisão das despesas e a nota avisa quanto passou.
     *
     * @param  array<string, mixed>  $resumo
     * @return array<string, mixed>|null
     */
    private function secaoDestino(array $resumo, ChartPalette $p): ?array
    {
        if (bccomp($resumo['receitas'], '0', 2) <= 0) {
            return null;
        }

        $essencial = $resumo['composicao']['essential'];
        $superfluo = $resumo['composicao']['discretionary'];
        $invest = $resumo['composicao']['investment'];
        $sobra = $resumo['sobra'];
        $nota = null;

        if (bccomp($sobra, '0', 2) < 0) {
            $base = $resumo['despesas'];
            $sobra = '0.00';
            $nota = 'As despesas passaram da receita em '.Money::format(bcsub($resumo['despesas'], $resumo['receitas'], 2)).': a barra divide as despesas, sem sobra.';
        } else {
            $base = $resumo['receitas'];
        }

        $pct = fn (string $v): float => (float) Money::percentageOf($v, $base);
        $partes = [
            ['Essencial', $pct($essencial), $p->serie(0)],
            ['Supérfluo', $pct($superfluo), $p->serie(1)],
            ['Investimentos', $pct($invest), $p->serie(2)],
            ['Sobra', $pct($sobra), $p->gray],
        ];

        $legenda = array_map(fn (array $x) => [$x[2], $x[0], 'R$ '.number_format($x[1], 2, ',', '.')], $partes);
        $linhas = array_map(fn (array $x, string $v) => [$x[0], Money::format($v), 'R$ '.number_format($x[1], 2, ',', '.')], $partes, [$essencial, $superfluo, $invest, $sobra]);

        return [
            'id' => 'destino',
            'titulo' => 'Para onde vai cada R$ 100 de receita',
            'sub' => 'Receita de '.Money::format($resumo['receitas']).' no mês.',
            'svg' => SvgCharts::barraUnica($partes, $p, 640, 'Barra única com o destino de cada R$ 100 de receita'),
            'legenda' => $legenda,
            'tabela' => ['colunas' => ['Destino', 'Valor', 'De cada R$ 100'], 'linhas' => $linhas],
            'nota' => $nota,
        ];
    }
}
