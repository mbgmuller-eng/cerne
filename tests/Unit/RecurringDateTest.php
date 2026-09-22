<?php

namespace Tests\Unit;

use App\Support\RecurringDate;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RecurringDateTest extends TestCase
{
    public function test_proxima_ocorrencia_ainda_nao_passou_neste_ano(): void
    {
        $original = Carbon::parse('1990-05-14');
        $hoje = Carbon::parse('2026-05-01');

        self::assertSame('2026-05-14', RecurringDate::nextOccurrence($original, $hoje)->toDateString());
    }

    public function test_proxima_ocorrencia_ja_passou_neste_ano_pula_pro_ano_seguinte(): void
    {
        $original = Carbon::parse('1990-05-14');
        $hoje = Carbon::parse('2026-06-01');

        self::assertSame('2027-05-14', RecurringDate::nextOccurrence($original, $hoje)->toDateString());
    }

    public function test_hoje_e_o_proprio_dia_conta_como_ocorrencia_deste_ano(): void
    {
        $original = Carbon::parse('1990-05-14');
        $hoje = Carbon::parse('2026-05-14');

        self::assertSame('2026-05-14', RecurringDate::nextOccurrence($original, $hoje)->toDateString());
        self::assertSame(0, RecurringDate::daysUntil($original, $hoje));
    }

    public function test_dias_ate_a_proxima_ocorrencia(): void
    {
        $original = Carbon::parse('1990-05-14');
        $hoje = Carbon::parse('2026-05-01');

        self::assertSame(13, RecurringDate::daysUntil($original, $hoje));
    }

    public function test_anos_completos_na_proxima_ocorrencia(): void
    {
        $original = Carbon::parse('2023-03-10');
        $hoje = Carbon::parse('2026-02-01');

        self::assertSame(3, RecurringDate::yearsCompletingAt($original, $hoje));
    }

    public function test_anos_completos_apos_a_data_deste_ano_ja_ter_passado(): void
    {
        $original = Carbon::parse('2023-03-10');
        $hoje = Carbon::parse('2026-04-01');

        self::assertSame(4, RecurringDate::yearsCompletingAt($original, $hoje));
    }
}
