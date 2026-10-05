<?php

namespace Tests\Unit;

use App\Support\ProfessionalPricing;
use Tests\TestCase;

class ProfessionalPricingTest extends TestCase
{
    /** @return array<string, array{0: int, 1: string}> */
    public static function precosPorTeto(): array
    {
        return [
            'até 10 clientes' => [10, '79.90'],
            'até 20 clientes' => [20, '149.90'],
            'primeiro bloco extra (30)' => [30, '209.80'],
            'três blocos extras (50), exemplo do Marcelo' => [50, '329.60'],
            'cem clientes' => [100, '629.10'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('precosPorTeto')]
    public function test_preco_por_teto(int $teto, string $esperado): void
    {
        self::assertSame($esperado, ProfessionalPricing::priceFor($teto));
    }

    public function test_tetos_validos_sao_10_20_e_depois_de_10_em_10_ate_o_maximo(): void
    {
        self::assertSame([10, 20, 30, 40, 50, 60, 70, 80, 90, 100], ProfessionalPricing::validCaps());
    }

    public function test_teto_fora_da_escala_e_invalido(): void
    {
        self::assertFalse(ProfessionalPricing::isValidCap(15));
        self::assertFalse(ProfessionalPricing::isValidCap(0));
        self::assertFalse(ProfessionalPricing::isValidCap(110));
        self::assertTrue(ProfessionalPricing::isValidCap(20));
    }
}
