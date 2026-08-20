<?php

namespace App\Support;

/**
 * Formatação e parsing de valores monetários em BRL.
 *
 * Os valores trafegam como STRING (colunas DECIMAL(15,2) com cast
 * `decimal:2`), nunca como float — somar centavos em ponto flutuante
 * acumula erro. Cálculos usam bcmath; esta classe cuida só da borda
 * entre o banco e a tela.
 */
final class Money
{
    public const SCALE = 2;

    /** "1234.56" => "R$ 1.234,56" */
    public static function format(string|int|float|null $value, bool $withSymbol = true): string
    {
        $number = number_format((float) ($value ?? 0), self::SCALE, ',', '.');

        return $withSymbol ? 'R$ '.$number : $number;
    }

    /**
     * Versão curta para gráficos e cards, onde o valor cheio não cabe.
     * "1234567.89" => "R$ 1,2 mi"
     */
    public static function compact(string|int|float|null $value): string
    {
        $number = (float) ($value ?? 0);
        $abs = abs($number);

        [$divisor, $suffix] = match (true) {
            $abs >= 1_000_000_000 => [1_000_000_000, ' bi'],
            $abs >= 1_000_000 => [1_000_000, ' mi'],
            $abs >= 1_000 => [1_000, ' mil'],
            default => [1, ''],
        };

        $scaled = $number / $divisor;
        $decimals = $suffix === '' ? self::SCALE : 1;

        return 'R$ '.number_format($scaled, $decimals, ',', '.').$suffix;
    }

    /**
     * Converte o que o usuário digitou ("1.234,56", "1234,56", "R$ 1.234,56")
     * para o formato que o banco aceita ("1234.56").
     */
    public static function parse(string|int|float|null $input): string
    {
        if ($input === null || $input === '') {
            return '0.00';
        }

        if (is_int($input) || is_float($input)) {
            return number_format((float) $input, self::SCALE, '.', '');
        }

        // Remove tudo que não for dígito, vírgula, ponto ou sinal negativo
        $clean = preg_replace('/[^\d,.\-]/', '', $input) ?? '';

        // Formato brasileiro: o último separador é a vírgula decimal
        if (str_contains($clean, ',')) {
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
        }

        return number_format((float) $clean, self::SCALE, '.', '');
    }

    /** Soma exata de uma lista de valores monetários. */
    public static function sum(iterable $values): string
    {
        $total = '0.00';

        foreach ($values as $value) {
            $total = bcadd($total, self::parse($value), self::SCALE);
        }

        return $total;
    }

    /**
     * Divide um valor em N parcelas, jogando a sobra de centavos na
     * ÚLTIMA parcela — é o que fecha o total exato de uma compra
     * parcelada (ver InstallmentService).
     *
     * @return array<int, string>
     */
    public static function split(string|int|float $total, int $parts): array
    {
        if ($parts < 1) {
            throw new \InvalidArgumentException('O número de parcelas precisa ser ao menos 1.');
        }

        $total = self::parse($total);
        $base = bcdiv($total, (string) $parts, self::SCALE);

        $installments = array_fill(0, $parts - 1, $base);
        $allocated = bcmul($base, (string) ($parts - 1), self::SCALE);
        $installments[] = bcsub($total, $allocated, self::SCALE);

        return $installments;
    }

    /** Percentual de `part` sobre `whole`, protegido contra divisão por zero. */
    public static function percentageOf(string|int|float|null $part, string|int|float|null $whole): float
    {
        $whole = (float) self::parse($whole);

        if ($whole === 0.0) {
            return 0.0;
        }

        return round(((float) self::parse($part) / $whole) * 100, 2);
    }
}
