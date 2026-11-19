<?php

namespace App\Services\Assistant;

use App\Models\BankAccount;
use App\Models\ExpenseRecord;
use App\Models\Goal;
use App\Services\DashboardService;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * As ferramentas que o assistente pode chamar. Regras que não se quebram:
 *
 *  - SÓ LEITURA. Nenhuma ferramenta grava, apaga ou dá baixa em nada: o assistente responde, quem age é a pessoa nas telas.
 *  - Toda consulta roda sob o ProfileContext da pessoa que perguntou, pelos models com escopo. O que o cônjuge ocultou, o assistente não vê, e
 *    nenhuma ferramenta recebe id de perfil vindo do modelo (não há parâmetro para isso).
 *  - Soma em SQL (regra 3) e saída pequena: o modelo recebe totais e poucas linhas, nunca a lista inteira de lançamentos.
 *  - O resultado é TEXTO de terceiros para o modelo (descrições de lançamentos vêm de extrato importado): o prompt do sistema manda tratá-lo
 *    como dado, e como as ferramentas só leem, uma ordem escondida numa descrição não tem o que fazer.
 */
class AssistantTools
{
    public function __construct(private DashboardService $dashboard) {}

    /** @return list<array<string, mixed>> */
    public function definitions(): array
    {
        $periodo = [
            'ano' => ['type' => 'integer', 'description' => 'Ano com 4 dígitos. Omitido = ano de hoje.'],
            'mes' => ['type' => 'integer', 'description' => 'Mês de 1 a 12. Omitido = mês de hoje.'],
        ];

        return [
            [
                'name' => 'resumo_do_mes',
                'description' => 'Receitas, despesas, sobra e as maiores categorias de despesa de um mês, mais a variação contra o mês anterior.',
                'input_schema' => ['type' => 'object', 'properties' => $periodo],
            ],
            [
                'name' => 'gastos_por_assunto',
                'description' => 'Quanto foi gasto em um mês com um assunto: procura o texto na descrição, na categoria e na subcategoria da despesa (ex.: "delivery", "mercado", "uber"). Devolve o total e a quantidade de lançamentos.',
                'input_schema' => ['type' => 'object', 'properties' => $periodo + ['assunto' => ['type' => 'string', 'description' => 'Texto a procurar, em português.']], 'required' => ['assunto']],
            ],
            [
                'name' => 'saldos_das_contas',
                'description' => 'Saldo de hoje de cada conta bancária ativa e o total.',
                'input_schema' => ['type' => 'object', 'properties' => new \stdClass],
            ],
            [
                'name' => 'proximos_vencimentos',
                'description' => 'Contas fixas e faturas de cartão que vencem nos próximos dias, com data e valor.',
                'input_schema' => ['type' => 'object', 'properties' => new \stdClass],
            ],
            [
                'name' => 'objetivos',
                'description' => 'Objetivos ativos: quanto já foi juntado, a meta e o percentual de cada um.',
                'input_schema' => ['type' => 'object', 'properties' => new \stdClass],
            ],
        ];
    }

    /**
     * Executa uma ferramenta. Devolve sempre um array serializável; nome desconhecido ou entrada ruim vira um erro em texto (o modelo
     * explica à pessoa), nunca uma exceção que derrube a conversa.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function run(string $nome, array $input): array
    {
        return match ($nome) {
            'resumo_do_mes' => $this->resumoDoMes($input),
            'gastos_por_assunto' => $this->gastosPorAssunto($input),
            'saldos_das_contas' => $this->saldosDasContas(),
            'proximos_vencimentos' => $this->proximosVencimentos(),
            'objetivos' => $this->objetivos(),
            default => ['erro' => "Ferramenta desconhecida: {$nome}."],
        };
    }

    /** @return array{0: int, 1: int} */
    private function periodo(array $input): array
    {
        $hoje = CarbonImmutable::now();
        $ano = (int) ($input['ano'] ?? $hoje->year);
        $mes = (int) ($input['mes'] ?? $hoje->month);

        return [max(2000, min(2100, $ano)), max(1, min(12, $mes))];
    }

    private function resumoDoMes(array $input): array
    {
        [$ano, $mes] = $this->periodo($input);
        $resumo = $this->dashboard->monthSummary($ano, $mes);
        $variacao = $this->dashboard->variation($ano, $mes);

        return [
            'periodo' => sprintf('%02d/%d', $mes, $ano),
            'receitas' => $resumo['receitas'],
            'despesas' => $resumo['despesas'],
            'sobra' => $resumo['sobra'],
            'maiores_categorias' => array_map(fn (array $c) => ['categoria' => $c['nome'], 'valor' => $c['valor'], 'percentual_das_despesas' => $c['pct']], $this->dashboard->categoryRanking($ano, $mes)),
            'variacao_contra_mes_anterior' => $variacao,
            'moeda' => 'BRL',
        ];
    }

    private function gastosPorAssunto(array $input): array
    {
        [$ano, $mes] = $this->periodo($input);
        $assunto = trim((string) ($input['assunto'] ?? ''));

        if (mb_strlen($assunto) < 2) {
            return ['erro' => 'Informe o assunto com pelo menos 2 letras.'];
        }

        $like = '%'.addcslashes($assunto, '%_\\').'%';
        $consulta = ExpenseRecord::query()->forPeriod($ano, $mes)->where(function ($q) use ($like): void {
            $q->where('description', 'like', $like)
                ->orWhereHas('category', fn ($c) => $c->where('name', 'like', $like))
                ->orWhereHas('subcategory', fn ($c) => $c->where('name', 'like', $like));
        });

        return [
            'periodo' => sprintf('%02d/%d', $mes, $ano),
            'assunto' => $assunto,
            'total' => Money::parse((clone $consulta)->sum('amount')),
            'lancamentos' => (clone $consulta)->count(),
            'moeda' => 'BRL',
        ];
    }

    private function saldosDasContas(): array
    {
        $contas = BankAccount::query()->where('is_active', true)->orderBy('bank_name')->get();

        return [
            'contas' => $contas->map(fn (BankAccount $c) => ['conta' => $c->displayName(), 'saldo' => Money::parse($c->current_balance)])->all(),
            'total' => Money::sum($contas->pluck('current_balance')),
            'moeda' => 'BRL',
        ];
    }

    private function proximosVencimentos(): array
    {
        $dados = $this->dashboard->upcomingBills();

        return [
            'dias_a_frente' => $dados['dias'],
            'total' => $dados['total'],
            'itens' => $dados['itens']->map(fn (array $i) => ['nome' => $i['nome'], 'tipo' => $i['tipo'], 'vencimento' => $i['vencimento'], 'valor' => Money::parse($i['valor'])])->values()->all(),
            'moeda' => 'BRL',
        ];
    }

    private function objetivos(): array
    {
        $lista = Goal::query()->active()->byPriority()->withContributionTotal()->withInvestmentCount()->with('investments', 'linkedDebt')->limit(20)->get();

        return [
            'objetivos' => $lista->map(fn (Goal $g) => ['nome' => $g->name, 'meta' => Money::parse($g->estimated_value), 'acumulado' => $g->accumulated(), 'percentual' => $g->progressPercentage()])->all(),
            'moeda' => 'BRL',
        ];
    }
}
