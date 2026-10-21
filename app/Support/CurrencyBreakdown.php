<?php

namespace App\Support;

/**
 * O patrimônio investido dividido por moeda (reais, USDT, BTC, ETH), para o filtro "Em reais / Por moeda" das telas de resumo.
 * Cada linha traz a quantidade na moeda de origem e o equivalente em reais; para o real as duas coisas são o mesmo número.
 */
final class CurrencyBreakdown
{
    /** @var list<string> */
    public const ORDER = ['BRL', 'USDT', 'BTC', 'ETH'];

    /**
     * @param  iterable<array{currency: string, brl: string, native: string|null}>  $linhas  soma por moeda (uma linha por moeda)
     * @return list<array{currency: string, native: string, brl: string}> na ordem fixa; só as moedas com valor
     */
    public static function rows(iterable $linhas): array
    {
        $porMoeda = [];

        foreach ($linhas as $l) {
            $brl = Money::parse($l['brl']);
            $moeda = $l['currency'] ?: 'BRL';
            $nativo = $moeda === 'BRL' || $l['native'] === null ? $brl : (string) $l['native'];

            $porMoeda[$moeda] = ['currency' => $moeda, 'native' => $nativo, 'brl' => $brl];
        }

        $ordenadas = [];

        foreach (self::ORDER as $moeda) {
            if (isset($porMoeda[$moeda]) && (bccomp($porMoeda[$moeda]['brl'], '0', 2) !== 0 || bccomp($porMoeda[$moeda]['native'], '0', 10) !== 0)) {
                $ordenadas[] = $porMoeda[$moeda];
            }
        }

        return $ordenadas;
    }

    /** Há algo em moeda que não é o real? Só então o filtro faz sentido. */
    public static function hasForeign(array $rows): bool
    {
        return count(array_filter($rows, fn (array $r) => $r['currency'] !== 'BRL')) > 0;
    }
}
