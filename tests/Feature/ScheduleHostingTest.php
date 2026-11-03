<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A hospedagem compartilhada desliga proc_open no PHP da linha de comando. Rotina agendada com Schedule::command() ou exec() abre um
 * subprocesso e falha todo dia com "The Process class relies on proc_open" (foi o erro de 04:10 e 08:10 no acompanhamento de erros).
 * Toda rotina precisa rodar dentro do mesmo processo: Schedule::call(), chamando o comando com Artisan::call().
 */
class ScheduleHostingTest extends TestCase
{
    use RefreshDatabase;

    public function test_nenhuma_rotina_agendada_abre_subprocesso(): void
    {
        $eventos = collect(app(Schedule::class)->events());

        self::assertNotEmpty($eventos);

        $abrem = $eventos->reject(fn ($e) => $e instanceof CallbackEvent)->map(fn ($e) => $e->description ?? $e->command)->values()->all();

        self::assertSame([], $abrem, 'Use Schedule::call(fn () => Artisan::call(...)): o PHP da hospedagem não tem proc_open.');
    }

    public function test_relatorio_de_erros_e_limpeza_de_anexos_continuam_nos_mesmos_horarios(): void
    {
        $porNome = collect(app(Schedule::class)->events())->keyBy(fn ($e) => $e->description);

        self::assertSame('10 8 * * *', $porNome['relatorio-de-erros']->expression);
        self::assertSame('10 4 * * *', $porNome['anexos-de-leads']->expression);
    }

    public function test_a_limpeza_de_anexos_roda_pela_rotina_sem_subprocesso(): void
    {
        $evento = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'anexos-de-leads');

        $evento->run($this->app);

        self::assertSame(0, $evento->exitCode ?? 0);
    }
}
