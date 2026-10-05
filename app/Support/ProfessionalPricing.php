<?php

namespace App\Support;

/**
 * Preço mensal da assinatura do profissional a partir do teto de clientes
 * (config/billing.php, 'professional'). Valores como string, cálculo em
 * bcmath — nunca float.
 */
final class ProfessionalPricing
{
    /** @return list<int> tetos que o profissional pode contratar sozinho: 10, 20, 30... */
    public static function validCaps(): array
    {
        $cfg = config('billing.professional');
        $caps = [$cfg['first_tier_clients'], $cfg['second_tier_clients']];

        for ($cap = $cfg['second_tier_clients'] + $cfg['extra_block_clients']; $cap <= $cfg['max_self_service_clients']; $cap += $cfg['extra_block_clients']) {
            $caps[] = $cap;
        }

        return array_values(array_filter($caps, fn (int $cap) => $cap <= $cfg['max_self_service_clients']));
    }

    public static function isValidCap(int $cap): bool
    {
        return in_array($cap, self::validCaps(), true);
    }

    /** "329.60" para um teto de 50 clientes. */
    public static function priceFor(int $cap): string
    {
        $cfg = config('billing.professional');

        if ($cap <= $cfg['first_tier_clients']) {
            return $cfg['first_tier_price'];
        }

        if ($cap <= $cfg['second_tier_clients']) {
            return $cfg['second_tier_price'];
        }

        $blocos = intdiv($cap - $cfg['second_tier_clients'] + $cfg['extra_block_clients'] - 1, $cfg['extra_block_clients']);

        return bcadd($cfg['second_tier_price'], bcmul($cfg['extra_block_price'], (string) $blocos, 2), 2);
    }
}
