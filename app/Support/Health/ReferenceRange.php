<?php

namespace App\Support\Health;

use Illuminate\Support\Str;

/**
 * A referência IMPRESSA no laudo, quando ela é uma faixa simples ("70 a 99", "menor que 130", "> 40"), para destacar o que ficou fora. É
 * destaque, não interpretação, e por isso a regra é conservadora: o texto INTEIRO precisa ser uma dessas formas. Referência com várias faixas
 * (por idade, sexo ou risco), texto corrido ou qualitativa não é lida, e o valor não é destacado. Destacar errado é pior que não destacar.
 */
final class ReferenceRange
{
    private const NUMBER = '(\d{1,3}(?:\.\d{3})+(?:,\d+)?|\d+(?:[.,]\d+)?)';

    /** Unidade solta no fim ("mg/dL", "%", "/mm3", "milhoes/mm3"): sem espaço no meio, para não engolir um "12 a 16 anos: ...". */
    private const UNIT = '(?:\s*(?:[a-z%\/][a-z0-9%\/\^\.]*))?';

    /** @return array{min: ?float, max: ?float, strictMin: bool, strictMax: bool}|null */
    public static function parse(?string $texto): ?array
    {
        $t = mb_strtolower(trim((string) $texto));
        $t = str_replace(['≤', '≥', '–', '—', 'µ', 'μ'], ['<=', '>=', '-', '-', 'u', 'u'], $t);
        $t = trim(Str::ascii($t), " .\t\n");
        // Rótulo que os laboratórios põem na frente da faixa.
        $t = trim((string) preg_replace('/^(?:valores? de referencia|referencia|desejavel|normal|vr)\s*:?\s*/', '', $t));

        if ($t === '') {
            return null;
        }

        if (preg_match('/^(?:de\s+|entre\s+)?'.self::NUMBER.'\s*(?:a|ate|-|e)\s+'.self::NUMBER.self::UNIT.'$/', $t, $m)
            || preg_match('/^(?:de\s+)?'.self::NUMBER.'\s*-\s*'.self::NUMBER.self::UNIT.'$/', $t, $m)) {
            $min = self::number($m[1]);
            $max = self::number($m[2]);

            return $min <= $max ? ['min' => $min, 'max' => $max, 'strictMin' => false, 'strictMax' => false] : null;
        }

        if (preg_match('/^(<=|ate|<|menor que|menor do que|inferior a|abaixo de|menor ou igual a)\s*'.self::NUMBER.self::UNIT.'$/', $t, $m)) {
            return ['min' => null, 'max' => self::number($m[2]), 'strictMin' => false, 'strictMax' => ! in_array($m[1], ['<=', 'ate', 'menor ou igual a'], true)];
        }

        if (preg_match('/^(>=|>|maior que|maior do que|superior a|acima de|maior ou igual a|a partir de)\s*'.self::NUMBER.self::UNIT.'$/', $t, $m)) {
            return ['min' => self::number($m[2]), 'max' => null, 'strictMin' => ! in_array($m[1], ['>=', 'maior ou igual a', 'a partir de'], true), 'strictMax' => false];
        }

        return null;
    }

    /** O valor, quando é só um número ("13,8", "250.000", "5.1", "62%"). "Negativo" ou "< 0,01" não são número: nem destaque nem gráfico. */
    public static function value(?string $texto): ?float
    {
        $t = trim(rtrim(trim((string) $texto), '%'));

        return preg_match('/^'.self::NUMBER.'$/', $t) === 1 ? self::number($t) : null;
    }

    /** '' dentro da faixa (ou quando não dá para saber), 'above' ou 'below'. */
    public static function flag(?string $valor, ?string $referencia): string
    {
        $v = self::value($valor);
        $faixa = self::parse($referencia);

        if ($v === null || $faixa === null) {
            return '';
        }

        if ($faixa['max'] !== null && ($faixa['strictMax'] ? $v >= $faixa['max'] : $v > $faixa['max'])) {
            return 'above';
        }

        if ($faixa['min'] !== null && ($faixa['strictMin'] ? $v <= $faixa['min'] : $v < $faixa['min'])) {
            return 'below';
        }

        return '';
    }

    public static function flagLabel(string $flag): string
    {
        return match ($flag) {
            'above' => '▲ acima da referência',
            'below' => '▼ abaixo da referência',
            default => '',
        };
    }

    /** "250.000" é milhar, "1,5" é decimal, "5.1" (ponto sem três casas) é decimal. */
    private static function number(string $texto): float
    {
        if (str_contains($texto, ',') || preg_match('/^[1-9]\d{0,2}(?:\.\d{3})+$/', $texto) === 1) {
            return (float) str_replace(['.', ','], ['', '.'], $texto);
        }

        return (float) $texto;
    }
}
