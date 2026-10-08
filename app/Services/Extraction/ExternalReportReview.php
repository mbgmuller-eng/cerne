<?php

namespace App\Services\Extraction;

use App\Models\DocumentUpload;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Tudo o que é específico de relatório vindo de outro aplicativo (Minhas Finanças etc.): normaliza a leitura da IA,
 * confere a soma com o total que o próprio relatório declara, e dá à revisão as pistas por linha (parcela, provável
 * recorrente, pagamento de fatura, outra conta) e o resumo por categoria de origem.
 *
 * Nada aqui grava lançamento: a revisão humana decide, e o DocumentCommitService só recebe o que ela confirmou.
 */
final class ExternalReportReview
{
    /** Categoria de outro app => nome da categoria equivalente na taxonomia do Cerne, quando os nomes diferem. */
    private const SINONIMOS = [
        'moradia' => 'Habitação',
        'casa' => 'Habitação',
        'pagamentos' => 'Financeiros',
        'emprestimos' => 'Financeiros',
        'veiculo' => 'Transporte',
        'veiculos' => 'Transporte',
        'carro' => 'Transporte',
        'mercado' => 'Alimentação',
        'supermercado' => 'Alimentação',
        'restaurantes' => 'Alimentação',
        'criancas' => 'Filhos',
        'roupas' => 'Vestuário',
        'educacao' => 'Educação',
        'saude' => 'Saúde',
        'lazer' => 'Lazer',
    ];

    /**
     * Ajusta o que a IA devolveu antes de guardar no documento: categoria de origem também como `categoria_sugerida`
     * (o campo que o extrato bancário já usa) e traços "-" de coluna vazia viram texto vazio.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    public static function normalize(array $dados): array
    {
        $dados['itens'] = array_values(array_map(function (array $item): array {
            foreach (['categoria_origem', 'subcategoria_origem', 'conta_origem', 'cartao_origem'] as $campo) {
                $valor = trim((string) ($item[$campo] ?? ''));
                $item[$campo] = $valor === '-' ? '' : $valor;
            }

            $item['categoria_sugerida'] = $item['categoria_origem'] !== '' ? $item['categoria_origem'] : null;

            return $item;
        }, $dados['itens'] ?? []));

        return $dados;
    }

    /**
     * Soma dos lançamentos x total que o relatório diz ter. Relatório só de despesas (ou só de receitas) compara a
     * soma direta; relatório misto aceita também o saldo líquido. Sem total declarado, não há o que conferir.
     *
     * @return array{declarado: string, soma: string, confere: bool, diferenca: string}|null
     */
    public static function reconciliation(DocumentUpload $documento): ?array
    {
        $declarado = $documento->extraction_summary['total_declarado'] ?? null;

        if (! is_numeric($declarado)) {
            return null;
        }

        $despesas = $receitas = '0';
        foreach ($documento->extractedItems() as $item) {
            $valor = Money::parse($item['valor'] ?? 0);
            ($item['tipo'] ?? 'despesa') === 'receita'
                ? $receitas = bcadd($receitas, $valor, 2)
                : $despesas = bcadd($despesas, $valor, 2);
        }

        $declarado = bcadd((string) $declarado, '0', 2);
        $candidatos = [bcadd($despesas, $receitas, 2), $despesas, $receitas, bcsub($receitas, $despesas, 2)];
        $soma = $candidatos[0];
        $confere = false;

        foreach ($candidatos as $candidato) {
            if (bccomp($candidato, $declarado, 2) === 0) {
                $soma = $candidato;
                $confere = true;

                break;
            }
        }

        return [
            'declarado' => $declarado,
            'soma' => $soma,
            'confere' => $confere,
            'diferenca' => bcsub($soma, $declarado, 2),
        ];
    }

    /**
     * Pistas por linha, pela mesma posição do item.
     *
     * - parcela: "14/48", quando o relatório indica parcelamento;
     * - recorrente: provável conta fixa ou recorrente (cadastrada em mês anterior ao do gasto, sem ser parcela);
     * - fatura: parece pagamento de fatura de cartão (contaria em dobro com as compras da fatura);
     * - cartao: compra feita no cartão de crédito X (o Cerne registra essas compras pela fatura, não como saída da conta);
     * - outra_conta: o relatório mistura contas e esta linha é de uma diferente da principal.
     *
     * @return array<int, array{parcela: ?string, recorrente: bool, fatura: bool, cartao: ?string, outra_conta: ?string}>
     */
    public static function hints(DocumentUpload $documento): array
    {
        $itens = $documento->extractedItems();
        $principal = self::principalAccount($itens);
        $dicas = [];

        foreach ($itens as $i => $item) {
            $atual = $item['parcela_atual'] ?? null;
            $total = $item['parcela_total'] ?? null;
            $cadastro = (string) ($item['data_cadastro'] ?? '');
            $data = (string) ($item['data'] ?? '');
            $conta = trim((string) ($item['conta_origem'] ?? ''));

            $dicas[$i] = [
                'parcela' => $atual !== null && $total !== null ? "{$atual}/{$total}" : null,
                'recorrente' => $atual === null && $cadastro !== '' && $data !== '' && substr($cadastro, 0, 7) < substr($data, 0, 7),
                'fatura' => self::looksLikeCardPayment((string) ($item['descricao'] ?? '')),
                'cartao' => trim((string) ($item['cartao_origem'] ?? '')) !== '' ? trim((string) $item['cartao_origem']) : null,
                'outra_conta' => $principal !== null && $conta !== '' && Str::lower($conta) !== Str::lower($principal) ? $conta : null,
            ];
        }

        return $dicas;
    }

    /**
     * Uma linha por categoria de origem, com quantos lançamentos e quanto, para a revisão decidir uma vez só o que fazer
     * com cada grupo em vez de 100 vezes.
     *
     * @return Collection<int, array{origem: string, rotulo: string, indices: list<int>, quantidade: int, total: string}>
     */
    public static function groups(DocumentUpload $documento): Collection
    {
        $pendentes = $documento->pendingItemIndices();

        return collect($documento->extractedItems())
            ->filter(fn (array $item, int $i) => in_array($i, $pendentes, true) && ($item['tipo'] ?? 'despesa') !== 'receita')
            ->groupBy(fn (array $item) => trim((string) ($item['categoria_origem'] ?? '')), preserveKeys: true)
            ->map(fn (Collection $itens, string $origem) => [
                'origem' => $origem,
                'rotulo' => $origem !== '' ? $origem : 'Sem categoria',
                'indices' => $itens->keys()->map(fn ($k) => (int) $k)->all(),
                'quantidade' => $itens->count(),
                'total' => Money::sum($itens->map(fn (array $item) => $item['valor'] ?? 0)),
            ])
            ->sortBy(fn (array $g) => Str::lower(Str::ascii($g['rotulo'])))
            ->values();
    }

    /**
     * A categoria do Cerne que corresponde à de origem, pelo mesmo nome ou por um sinônimo conhecido. Só considera
     * categorias comuns (as de investimento têm outra necessidade).
     *
     * @param  Collection<int, \App\Models\ExpenseCategory>  $categorias
     */
    public static function suggestCategoryId(string $origem, Collection $categorias): ?string
    {
        $chave = self::key($origem);

        if ($chave === '') {
            return null;
        }

        $alvo = self::key(self::SINONIMOS[$chave] ?? $origem);

        return $categorias->first(fn ($c) => self::key($c->name) === $alvo)?->id;
    }

    /** "Cartão crédito Nubank", "Cartao credito santander", "Fatura do cartão". */
    public static function looksLikeCardPayment(string $descricao): bool
    {
        return (bool) preg_match('/cartao\s+(de\s+)?credito|fatura\s+(do\s+)?cartao|pagamento\s+(da\s+)?fatura/u', Str::lower(Str::ascii($descricao)));
    }

    /** A conta que aparece mais vezes, só quando o relatório mistura mais de uma (senão não há "outra"). */
    private static function principalAccount(array $itens): ?string
    {
        $contas = array_count_values(array_filter(array_map(fn (array $i) => trim((string) ($i['conta_origem'] ?? '')), $itens)));

        if (count($contas) < 2) {
            return null;
        }

        arsort($contas);

        return (string) array_key_first($contas);
    }

    private static function key(string $texto): string
    {
        return Str::lower(Str::ascii(trim($texto)));
    }
}
