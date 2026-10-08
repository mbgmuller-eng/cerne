<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Padrão das proteções do seguro de vida: mesma lista, mesmos nomes e mesma ordem em toda apólice, seja qual
 * for a seguradora ou o jeito que o certificado escreve.
 *
 *   1. Morte qualquer causa        4. Doenças graves
 *   2. Invalidez por acidente      5. Internação hospitalar (valor da diária)
 *   3. Invalidez por doença        6. Serviço funeral (só o tipo: individual ou familiar, sem valor)
 *
 * Ficam de fora, sempre: "adiantamento por doença em estágio terminal" (já é a própria proteção de morte
 * qualquer causa), "seguro viagem" (adicional sem valor de proteção) e "apoio financeiro". O que não é
 * nenhuma dessas seis nem está na lista de fora segue depois, como veio, para não perder informação.
 */
final class LifeCoverage
{
    private const ORDEM = ['morte', 'invalidez_acidente', 'invalidez_doenca', 'doencas_graves', 'internacao', 'funeral'];

    private const NOMES = [
        'morte' => 'Morte qualquer causa',
        'invalidez_acidente' => 'Invalidez por acidente',
        'invalidez_doenca' => 'Invalidez por doença',
        'doencas_graves' => 'Doenças graves',
        'internacao' => 'Internação hospitalar',
        'funeral' => 'Serviço funeral',
    ];

    /**
     * Aplica o padrão a uma lista de proteções no formato gravado na apólice. Idempotente: passar de novo uma
     * lista já padronizada devolve a mesma lista.
     *
     * @param  array<int, array{name?: ?string, value?: ?string, deductible?: ?string}>|null  $lista
     * @return list<array{name: string, value: ?string, deductible: ?string}>
     */
    public static function standardize(?array $lista): array
    {
        $padrao = [];
        $outras = [];

        foreach ($lista ?? [] as $item) {
            $nome = trim((string) ($item['name'] ?? ''));
            $tipo = self::kind($nome);

            if ($nome === '' || $tipo === 'fora') {
                continue;
            }

            $entrada = [
                'name' => $nome,
                'value' => isset($item['value']) && $item['value'] !== '' ? (string) $item['value'] : null,
                'deductible' => isset($item['deductible']) && trim((string) $item['deductible']) !== '' ? trim((string) $item['deductible']) : null,
            ];

            if ($tipo === 'outra') {
                $outras[] = $entrada;

                continue;
            }

            // Duas linhas da mesma proteção: vale a primeira.
            if (isset($padrao[$tipo])) {
                continue;
            }

            $padrao[$tipo] = $tipo === 'funeral'
                ? ['name' => self::funeralName($nome), 'value' => null, 'deductible' => null]
                : ['name' => self::NOMES[$tipo]] + array_slice($entrada, 1);
        }

        $ordenadas = [];
        foreach (self::ORDEM as $tipo) {
            if (isset($padrao[$tipo])) {
                $ordenadas[] = $padrao[$tipo];
            }
        }

        return array_merge($ordenadas, $outras);
    }

    /**
     * O capital principal do seguro de vida: o de "morte qualquer causa"; sem ele na lista, o valor informado
     * na apólice (cadastro manual não tem a lista).
     *
     * @param  array<int, array{name?: ?string, value?: ?string}>|null  $lista
     */
    public static function deathCapital(?array $lista, ?string $fallback): ?string
    {
        foreach ($lista ?? [] as $item) {
            if (self::kind((string) ($item['name'] ?? '')) === 'morte' && isset($item['value']) && $item['value'] !== '') {
                return (string) $item['value'];
            }
        }

        return $fallback;
    }

    /**
     * Linhas prontas para a tela: nome, valor (nulo = não mostra) e uma observação curta.
     *
     * @param  array<int, array{name?: ?string, value?: ?string, deductible?: ?string}>|null  $lista
     * @return list<array{name: string, value: ?string, note: ?string}>
     */
    public static function present(?array $lista): array
    {
        return array_map(function (array $c): array {
            $internacao = self::kind($c['name']) === 'internacao';

            return [
                'name' => $c['name'],
                'value' => $c['value'],
                // O valor da internação é de UMA diária; a franquia (em dias) vai junto.
                'note' => implode(' · ', array_filter([
                    $internacao && $c['value'] !== null ? 'Valor da diária' : null,
                    $c['deductible'] !== null ? 'Franquia: '.$c['deductible'] : null,
                ])) ?: null,
            ];
        }, self::standardize($lista));
    }

    /** morte, invalidez_acidente... | 'fora' (nunca mostrar) | 'outra' (segue como veio) */
    private static function kind(string $nome): string
    {
        $n = Str::lower(Str::ascii($nome));

        return match (true) {
            str_contains($n, 'adiantamento'), str_contains($n, 'viagem'), str_contains($n, 'apoio financeiro') => 'fora',
            str_contains($n, 'morte') => 'morte',
            str_contains($n, 'invalidez') && str_contains($n, 'acidente') => 'invalidez_acidente',
            str_contains($n, 'invalidez') && str_contains($n, 'doenca') => 'invalidez_doenca',
            str_contains($n, 'doencas graves'), str_contains($n, 'doenca grave') => 'doencas_graves',
            str_contains($n, 'internacao') => 'internacao',
            str_contains($n, 'funeral') => 'funeral',
            default => 'outra',
        };
    }

    /** "Serviço de Assistência Funeral Familiar" => "Serviço funeral · Familiar" */
    private static function funeralName(string $nome): string
    {
        $n = Str::lower(Str::ascii($nome));

        return match (true) {
            str_contains($n, 'individual') => self::NOMES['funeral'].' · Individual',
            str_contains($n, 'familiar') => self::NOMES['funeral'].' · Familiar',
            default => self::NOMES['funeral'],
        };
    }
}
