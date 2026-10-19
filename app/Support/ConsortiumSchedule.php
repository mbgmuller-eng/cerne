<?php

namespace App\Support;

/**
 * Em quais parcelas do consórcio a comissão é paga e em que partes. Texto que o consultor digita:
 *
 *   "2, 4, 6"           partes iguais nas parcelas 2, 4 e 6
 *   "2:40, 4:30, 6:30"  a comissão dividida em 40%, 30% e 30% (as partes somam 100)
 *
 * A soma das partes é sempre exatamente o total: os centavos que sobram da divisão vão para a última parcela.
 */
final class ConsortiumSchedule
{
    public const MAX_INSTALLMENT = 480;

    /**
     * @return array{numbers: list<int>, shares: ?list<string>}|null nulo se o texto não for válido
     */
    public static function parse(string $texto): ?array
    {
        $itens = preg_split('/[\s,;]+/', trim($texto), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($itens === []) {
            return null;
        }

        $partes = [];
        $comParte = 0;

        foreach ($itens as $item) {
            if (! preg_match('/^(\d{1,3})(?::(\d+(?:[.,]\d+)?))?$/', $item, $m)) {
                return null;
            }

            $numero = (int) $m[1];

            if ($numero < 1 || $numero > self::MAX_INSTALLMENT || array_key_exists($numero, $partes)) {
                return null;
            }

            $partes[$numero] = isset($m[2]) ? str_replace(',', '.', $m[2]) : null;
            $comParte += isset($m[2]) ? 1 : 0;
        }

        if ($comParte !== 0 && $comParte !== count($partes)) {
            return null;
        }

        ksort($partes);

        if ($comParte === 0) {
            return ['numbers' => array_keys($partes), 'shares' => null];
        }

        $soma = '0';

        foreach ($partes as $parte) {
            if (bccomp($parte, '0', 4) <= 0) {
                return null;
            }

            $soma = bcadd($soma, $parte, 4);
        }

        if (bccomp(bcsub($soma, '100', 4), '0.01', 4) > 0 || bccomp(bcsub('100', $soma, 4), '0.01', 4) > 0) {
            return null;
        }

        return ['numbers' => array_keys($partes), 'shares' => array_values($partes)];
    }

    /** O texto em forma canônica ("2, 4, 6" ou "2:40, 4:30, 6:30"), ou nulo se for inválido. */
    public static function normalize(string $texto): ?string
    {
        $p = self::parse($texto);

        if ($p === null) {
            return null;
        }

        return implode(', ', array_map(
            fn (int $n, int $i) => $p['shares'] === null ? (string) $n : $n.':'.self::trimNumber($p['shares'][$i]),
            $p['numbers'],
            array_keys($p['numbers']),
        ));
    }

    /** "40.50" vira "40.5" e "40" continua "40": só tira zero depois da vírgula. */
    private static function trimNumber(string $valor): string
    {
        return str_contains($valor, '.') ? rtrim(rtrim($valor, '0'), '.') : $valor;
    }

    /**
     * Divide o total entre as parcelas.
     *
     * @return array<int, string> número da parcela => valor (2 casas); a soma é exatamente `$total`
     */
    public static function split(string $total, string $texto): array
    {
        $p = self::parse($texto);

        if ($p === null) {
            return [];
        }

        $n = count($p['numbers']);
        $valores = [];
        $acumulado = '0.00';

        foreach ($p['numbers'] as $i => $numero) {
            if ($i === $n - 1) {
                $valores[$numero] = bcsub($total, $acumulado, 2);

                continue;
            }

            $valor = $p['shares'] === null
                ? bcdiv($total, (string) $n, 2)
                : bcadd(bcdiv(bcmul($total, $p['shares'][$i], 6), '100', 6), '0.005', 2);

            $valores[$numero] = $valor;
            $acumulado = bcadd($acumulado, $valor, 2);
        }

        return $valores;
    }
}
