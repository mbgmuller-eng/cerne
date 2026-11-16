<?php

namespace App\Support\Budget;

use Illuminate\Support\Str;

/**
 * O formato da regra de orçamento (JSON de `budget_rules.structure` e `budget_rule_templates.structure`), os modelos prontos e a validação.
 *
 * ```
 * areas:  essential, discretionary, investment  => percentual da RECEITA do mês (somam 100)
 * groups: (só dentro de investimentos) nome + percentual do balde de investimentos (somam 100)
 *   items: nome + tipo
 *     percent: parte percentual do que SOBRA no grupo depois dos compromissos fixos
 *     goals:   como percent, e é o balde que a divisão entre objetivos reparte
 *     fixed:   compromisso com valor real (seguro de vida, parcela de consórcio, conta fixa), descontado ANTES do que sobra
 *   categories: nomes das categorias de investimento que compõem o item (o "realizado" sai delas)
 * ```
 *
 * As categorias vão por NOME e não por id: o modelo do consultor serve a qualquer perfil, e as categorias padrão têm o mesmo nome em todos.
 */
final class BudgetStructure
{
    public const AREAS = ['essential' => 'Essencial', 'discretionary' => 'Supérfluo', 'investment' => 'Investimentos'];

    /** De onde vem o valor de um compromisso fixo. */
    public const SOURCES = [
        'life_insurance' => 'Apólices de seguro de vida',
        'consortium' => 'Parcelas de consórcio ainda não contemplado',
        'fixed_bills' => 'Contas fixas de investimento nas categorias do item',
    ];

    public const KINDS = ['percent' => 'Percentual do que sobra', 'goals' => 'Objetivos (divisão entre eles)', 'fixed' => 'Compromisso fixo'];

    public const MAX_GROUPS = 8;

    public const MAX_ITEMS = 10;

    /** Tolerância ao somar percentuais: três terços (33,33 + 33,33 + 33,34) e arredondamento do que a pessoa digita. */
    private const TOLERANCE = '0.05';

    /** @return array<string, array{name: string, description: string}> */
    public static function presets(): array
    {
        return [
            'cerne' => ['name' => 'Cerne 50/20/30', 'description' => '50% essencial, 20% supérfluo e 30% investimentos, em três terços: reservas, aposentadoria e objetivos.'],
            'classico' => ['name' => 'Clássico 50/30/20', 'description' => '50% essencial, 30% supérfluo e 20% investimentos, nos mesmos três terços.'],
            'personalizado' => ['name' => 'Personalizado', 'description' => 'Começa em branco: você define os percentuais e monta os grupos e itens de investimento do seu jeito (ou deixa só as três áreas).'],
        ];
    }

    /** @return array<string, mixed> */
    public static function preset(string $chave): array
    {
        // Em branco: só as três áreas (um ponto de partida comum, 50/30/20) e nenhum grupo. A pessoa monta o resto, ou fica só com as áreas.
        if ($chave === 'personalizado') {
            return ['version' => 1, 'areas' => ['essential' => '50', 'discretionary' => '30', 'investment' => '20'], 'groups' => []];
        }

        $areas = $chave === 'classico'
            ? ['essential' => '50', 'discretionary' => '30', 'investment' => '20']
            : ['essential' => '50', 'discretionary' => '20', 'investment' => '30'];

        return ['version' => 1, 'areas' => $areas, 'groups' => self::thirds()];
    }

    /** @return array<string, mixed> */
    public static function default(): array
    {
        return self::preset('cerne');
    }

    /** @return list<array<string, mixed>> */
    private static function thirds(): array
    {
        return [
            [
                'key' => 'reservas', 'name' => 'Reservas', 'percent' => '33.3333', 'items' => [
                    ['key' => 'seguro_vida', 'name' => 'Seguro de vida', 'kind' => 'fixed', 'percent' => '0', 'sources' => ['life_insurance'], 'categories' => ['Seguro de Vida']],
                    ['key' => 'paz', 'name' => 'Reserva de paz', 'kind' => 'percent', 'percent' => '50', 'sources' => [], 'categories' => ['Reserva de Paz']],
                    ['key' => 'oportunidade', 'name' => 'Reserva de oportunidade', 'kind' => 'percent', 'percent' => '50', 'sources' => [], 'categories' => ['Reserva de Oportunidade']],
                ],
            ],
            [
                'key' => 'aposentadoria', 'name' => 'Aposentadoria', 'percent' => '33.3333', 'items' => [
                    ['key' => 'previdencia', 'name' => 'Previdência privada', 'kind' => 'percent', 'percent' => '40', 'sources' => [], 'categories' => ['Previdência Privada']],
                    ['key' => 'renda_passiva', 'name' => 'Ações e FIIs (renda passiva)', 'kind' => 'percent', 'percent' => '60', 'sources' => [], 'categories' => ['Ações', 'FIIs']],
                ],
            ],
            [
                'key' => 'objetivos', 'name' => 'Objetivos', 'percent' => '33.3334', 'items' => [
                    ['key' => 'consorcio', 'name' => 'Consórcio', 'kind' => 'fixed', 'percent' => '0', 'sources' => ['consortium'], 'categories' => ['Consórcio']],
                    ['key' => 'objetivos', 'name' => 'Objetivos', 'kind' => 'goals', 'percent' => '100', 'sources' => [], 'categories' => ['Fundos', 'ETFs', 'Dólar', 'ETFs Exterior', 'Poupança']],
                ],
            ],
        ];
    }

    /**
     * Texto limpo e no formato esperado: números como texto com duas casas, chaves únicas, listas sem vazio nem repetido. Não valida regra
     * (isso é `validate`); serve para o que vem do formulário ou de um modelo antigo.
     *
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     */
    public static function normalize(array $entrada): array
    {
        $areas = [];
        foreach (array_keys(self::AREAS) as $area) {
            $areas[$area] = self::percent($entrada['areas'][$area] ?? '0');
        }

        $grupos = [];
        $chaves = [];

        foreach (array_values((array) ($entrada['groups'] ?? [])) as $g) {
            $itens = [];

            foreach (array_values((array) ($g['items'] ?? [])) as $it) {
                $tipo = array_key_exists((string) ($it['kind'] ?? ''), self::KINDS) ? (string) $it['kind'] : 'percent';
                $itens[] = [
                    'key' => self::uniqueKey($chaves, (string) ($it['key'] ?? ''), (string) ($it['name'] ?? '')),
                    'name' => trim((string) ($it['name'] ?? '')),
                    'kind' => $tipo,
                    'percent' => $tipo === 'fixed' ? '0' : self::percent($it['percent'] ?? '0'),
                    'sources' => $tipo === 'fixed' ? array_values(array_unique(array_filter((array) ($it['sources'] ?? []), fn ($s) => array_key_exists((string) $s, self::SOURCES)))) : [],
                    'categories' => array_values(array_unique(array_filter(array_map(fn ($c) => trim((string) $c), (array) ($it['categories'] ?? [])), fn ($c) => $c !== ''))),
                ];
            }

            $grupos[] = [
                'key' => self::uniqueKey($chaves, (string) ($g['key'] ?? ''), (string) ($g['name'] ?? '')),
                'name' => trim((string) ($g['name'] ?? '')),
                'percent' => self::percent($g['percent'] ?? '0'),
                'items' => $itens,
            ];
        }

        return ['version' => 1, 'areas' => $areas, 'groups' => $grupos];
    }

    /**
     * O que está errado na regra, em português, para a tela mostrar. Vazio = pode salvar.
     *
     * @param  array<string, mixed>  $s  já normalizada
     * @return list<string>
     */
    public static function validate(array $s): array
    {
        $erros = [];
        $areas = $s['areas'] ?? [];
        $somaAreas = '0';

        foreach (self::AREAS as $chave => $rotulo) {
            $v = (string) ($areas[$chave] ?? '0');

            if (bccomp($v, '0', 2) < 0 || bccomp($v, '100', 2) > 0) {
                $erros[] = "O percentual de {$rotulo} precisa estar entre 0 e 100.";
            }

            $somaAreas = bcadd($somaAreas, $v, 4);
        }

        if (! self::near($somaAreas, '100')) {
            $erros[] = 'Essencial, supérfluo e investimentos somam '.self::fmt($somaAreas).'%. Precisam somar 100%.';
        }

        $grupos = $s['groups'] ?? [];

        if (count($grupos) > self::MAX_GROUPS) {
            $erros[] = 'No máximo '.self::MAX_GROUPS.' grupos dentro de investimentos.';
        }

        $somaGrupos = '0';
        $categoriasUsadas = [];
        $objetivos = 0;

        foreach ($grupos as $g) {
            $nome = (string) ($g['name'] ?? '');

            if ($nome === '' || mb_strlen($nome) > 60) {
                $erros[] = 'Todo grupo precisa de um nome de até 60 caracteres.';
            }

            if (bccomp((string) $g['percent'], '0', 2) < 0 || bccomp((string) $g['percent'], '100', 2) > 0) {
                $erros[] = "O percentual do grupo \"{$nome}\" precisa estar entre 0 e 100.";
            }

            $somaGrupos = bcadd($somaGrupos, (string) $g['percent'], 4);
            $itens = $g['items'] ?? [];

            if (count($itens) > self::MAX_ITEMS) {
                $erros[] = "O grupo \"{$nome}\" tem itens demais (máximo ".self::MAX_ITEMS.').';
            }

            $somaItens = '0';
            $temPercentual = false;

            foreach ($itens as $it) {
                $itemNome = (string) ($it['name'] ?? '');

                if ($itemNome === '' || mb_strlen($itemNome) > 60) {
                    $erros[] = "Todo item do grupo \"{$nome}\" precisa de um nome de até 60 caracteres.";
                }

                if (($it['kind'] ?? '') === 'fixed') {
                    if (($it['sources'] ?? []) === []) {
                        $erros[] = "O compromisso fixo \"{$itemNome}\" precisa de uma origem do valor.";
                    }
                } else {
                    $temPercentual = true;
                    $somaItens = bcadd($somaItens, (string) $it['percent'], 4);

                    if (bccomp((string) $it['percent'], '0', 2) < 0 || bccomp((string) $it['percent'], '100', 2) > 0) {
                        $erros[] = "O percentual de \"{$itemNome}\" precisa estar entre 0 e 100.";
                    }
                }

                if (($it['kind'] ?? '') === 'goals') {
                    $objetivos++;
                }

                foreach ($it['categories'] ?? [] as $categoria) {
                    $chave = self::fold($categoria);

                    if (isset($categoriasUsadas[$chave])) {
                        $erros[] = "A categoria \"{$categoria}\" está em mais de um item: o realizado contaria duas vezes.";
                    }

                    $categoriasUsadas[$chave] = true;
                }
            }

            if ($temPercentual && ! self::near($somaItens, '100')) {
                $erros[] = "Os itens com percentual do grupo \"{$nome}\" somam ".self::fmt($somaItens).'%. Precisam somar 100%.';
            }
        }

        if ($grupos !== [] && ! self::near($somaGrupos, '100')) {
            $erros[] = 'Os grupos de investimentos somam '.self::fmt($somaGrupos).'%. Precisam somar 100%.';
        }

        if ($objetivos > 1) {
            $erros[] = 'Só um item pode ser o de objetivos (o que a divisão entre objetivos reparte).';
        }

        return array_values(array_unique($erros));
    }

    /** O nome de categoria comparável: sem acento, sem maiúscula, sem espaço sobrando. */
    public static function fold(string $nome): string
    {
        return Str::lower(Str::ascii(trim($nome)));
    }

    /** Percentual como texto (2 a 4 casas; vírgula vira ponto). O limite de 0 a 100 é conferido em `validate`. */
    public static function percent(mixed $valor): string
    {
        $limpo = str_replace([' ', '%'], '', (string) $valor);
        $limpo = str_contains($limpo, ',') ? str_replace(',', '.', str_replace('.', '', $limpo)) : $limpo;

        if (! is_numeric($limpo)) {
            return '0.00';
        }

        // Até 4 casas: três terços (33,3333 cada) fecham a divisão exata; o que a pessoa digita com 2 casas continua com 2.
        $texto = rtrim(number_format((float) $limpo, 4, '.', ''), '0');

        return strlen(substr($texto, (int) strpos($texto, '.') + 1)) < 2 ? number_format((float) $limpo, 2, '.', '') : $texto;
    }

    private static function near(string $soma, string $alvo): bool
    {
        $dif = bcsub($soma, $alvo, 4);

        return bccomp($dif, self::TOLERANCE, 4) <= 0 && bccomp($dif, '-'.self::TOLERANCE, 4) >= 0;
    }

    private static function fmt(string $v): string
    {
        return rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',');
    }

    /** @param  array<string, true>  $usadas */
    private static function uniqueKey(array &$usadas, string $chave, string $nome): string
    {
        $base = Str::slug($chave !== '' ? $chave : ($nome !== '' ? $nome : 'item'), '_') ?: 'item';
        $final = $base;
        $n = 2;

        while (isset($usadas[$final])) {
            $final = $base.'_'.$n++;
        }

        $usadas[$final] = true;

        return $final;
    }
}
