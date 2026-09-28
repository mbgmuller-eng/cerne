<?php

namespace Tests\Unit;

use App\Enums\GymLoadMode;
use Tests\TestCase;

class GymLoadModeTest extends TestCase
{
    public function test_total_e_o_proprio_valor(): void
    {
        self::assertSame('35.00', GymLoadMode::Total->totalKg('35'));
    }

    public function test_cada_lado_dobra_sem_contar_a_barra(): void
    {
        // Agachamento: 15 kg de cada lado = 30 kg.
        self::assertSame('30.00', GymLoadMode::PerSide->totalKg('15'));
    }

    public function test_cada_halter_dobra(): void
    {
        self::assertSame('28.00', GymLoadMode::PerDumbbell->totalKg('14'));
    }

    public function test_peso_do_corpo_e_zero_de_carga_externa(): void
    {
        self::assertSame('0.00', GymLoadMode::Bodyweight->totalKg(null));
    }

    public function test_peso_do_corpo_mais_carga_usa_so_a_carga_adicionada(): void
    {
        self::assertSame('6.00', GymLoadMode::BodyweightPlus->totalKg('6'));
    }

    public function test_posicao_da_maquina_nao_tem_equivalente_em_kg(): void
    {
        self::assertNull(GymLoadMode::StackPosition->totalKg('6'));
    }

    public function test_sem_valor_nao_ha_total(): void
    {
        self::assertNull(GymLoadMode::PerSide->totalKg(null));
        self::assertNull(GymLoadMode::Total->totalKg(null));
    }

    public function test_casas_decimais_sao_preservadas_sem_float(): void
    {
        self::assertSame('5.00', GymLoadMode::PerSide->totalKg('2.5'));
        self::assertSame('0.30', GymLoadMode::PerSide->totalKg('0.15'));
    }
}
