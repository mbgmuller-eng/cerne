<?php

namespace App\Support\Extraction;

/**
 * O CSV de extrato ou fatura como texto limpo para a leitura por IA.
 *
 * Banco brasileiro exporta quase sempre em ISO-8859-1 / Windows-1252 (e o Excel salva com BOM): mandado cru à API, "Nação" viraria lixo e a
 * descrição do lançamento chegaria quebrada na revisão. Aqui tudo vira UTF-8, sem BOM e com quebra de linha única.
 */
final class CsvText
{
    /** Texto limpo em UTF-8, ou nulo quando o arquivo é binário (um .csv que na verdade é outra coisa). */
    public static function normalize(string $bruto): ?string
    {
        if (str_contains($bruto, "\0")) {
            return null;
        }

        // BOM de UTF-8 (Excel) e de UTF-16 não entram: o primeiro nome de coluna não pode vir com bytes invisíveis.
        $bruto = preg_replace('/^\xEF\xBB\xBF/', '', $bruto) ?? $bruto;

        $texto = mb_check_encoding($bruto, 'UTF-8')
            ? $bruto
            : mb_convert_encoding($bruto, 'UTF-8', 'Windows-1252');

        $texto = str_replace(["\r\n", "\r"], "\n", $texto);

        return trim($texto);
    }

    /** Linhas com conteúdo, sem contar a primeira (o cabeçalho): é o que a pessoa chama de "lançamentos". */
    public static function dataRows(string $texto): int
    {
        $linhas = array_filter(explode("\n", $texto), fn (string $l) => trim($l) !== '');

        return max(0, count($linhas) - 1);
    }
}
