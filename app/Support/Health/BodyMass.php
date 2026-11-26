<?php

namespace App\Support\Health;

/**
 * O IMC e a faixa de peso de referência. É uma TRIAGEM: não separa músculo de gordura, tem outra faixa a partir dos 60 anos e não vale na
 * gravidez. A tela diz isso, e o Cerne não diagnostica. As faixas estão aqui, num lugar só, e entram na revisão das regras
 * (App\Support\Rules\RuleBook).
 */
final class BodyMass
{
    public const ADULT_MIN = 18.5;

    public const ADULT_MAX = 24.9;

    public const SENIOR_AGE = 60;

    public const SENIOR_MIN = 22.0;

    public const SENIOR_MAX = 27.0;

    public const ADULT_AGE = 18;

    public static function bmi(float $pesoKg, int $alturaCm): float
    {
        $m = $alturaCm / 100;

        return round($pesoKg / ($m * $m), 1);
    }

    public static function isSenior(?int $idade): bool
    {
        return $idade !== null && $idade >= self::SENIOR_AGE;
    }

    /** Antes dos 18 anos a leitura é outra (depende de idade e sexo): o Cerne não classifica. */
    public static function applies(?int $idade): bool
    {
        return $idade === null || $idade >= self::ADULT_AGE;
    }

    /** @return array{0: float, 1: float} os limites de IMC da faixa de referência */
    public static function limits(?int $idade): array
    {
        return self::isSenior($idade) ? [self::SENIOR_MIN, self::SENIOR_MAX] : [self::ADULT_MIN, self::ADULT_MAX];
    }

    /** @return array{0: float, 1: float} os pesos, em kg, da faixa de referência para a altura */
    public static function weightRange(int $alturaCm, ?int $idade): array
    {
        $m2 = ($alturaCm / 100) ** 2;
        [$min, $max] = self::limits($idade);

        return [round($min * $m2, 1), round($max * $m2, 1)];
    }

    public static function category(float $imc, ?int $idade): string
    {
        if (self::isSenior($idade)) {
            return $imc < self::SENIOR_MIN ? 'abaixo da faixa' : ($imc <= self::SENIOR_MAX ? 'dentro da faixa' : 'acima da faixa');
        }

        return match (true) {
            $imc < self::ADULT_MIN => 'abaixo do peso',
            $imc < 25 => 'faixa saudável',
            $imc < 30 => 'sobrepeso',
            default => 'obesidade',
        };
    }

    /** "84,5" ou "84.5" em quilos; nulo se não for um número. */
    public static function parse(string $texto): ?float
    {
        $t = str_replace(',', '.', trim($texto));

        return preg_match('/^\d{1,3}(\.\d)?$/', $t) === 1 ? (float) $t : null;
    }

    public static function format(float $v): string
    {
        return number_format($v, 1, ',', '');
    }
}
