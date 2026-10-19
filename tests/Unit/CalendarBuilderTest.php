<?php

namespace Tests\Unit;

use App\Enums\CalendarView;
use App\Support\Calendar\CalendarBuilder;
use App\Support\Calendar\CalendarEvent;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * O que o calendário desenha: faixas de cada visão, navegação, mês com "+N mais" e a grade de horários com os compromissos
 * posicionados e lado a lado quando se sobrepõem.
 */
class CalendarBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-07 09:00:00'));
        // O ambiente de teste fala inglês; as telas, português.
        CarbonImmutable::setLocale('pt_BR');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        CarbonImmutable::setLocale('en');
        parent::tearDown();
    }

    private function evento(string $quando, string $titulo = 'Evento', array $extra = []): CalendarEvent
    {
        return new CalendarEvent(...($extra + ['key' => 'e:'.$titulo.$quando, 'title' => $titulo, 'start' => CarbonImmutable::parse($quando)]));
    }

    public function test_ancora_so_aceita_data_valida(): void
    {
        self::assertSame('2026-12-15', CalendarBuilder::anchor('2026-12-15')->toDateString());

        foreach ([null, '', 'lixo', '2026-02-31', '2026-13-01', '1999-01-01', '2101-01-01', '2026-1-1'] as $ruim) {
            self::assertSame('2026-10-07', CalendarBuilder::anchor($ruim)->toDateString(), var_export($ruim, true));
        }
    }

    public function test_faixa_de_cada_visao(): void
    {
        $ancora = CarbonImmutable::parse('2026-10-07');

        [$ini, $fim] = CalendarBuilder::range(CalendarView::Month, $ancora);
        self::assertSame(['2026-09-27', '2026-10-31'], [$ini->toDateString(), $fim->toDateString()]);

        [$ini, $fim] = CalendarBuilder::range(CalendarView::Week, $ancora);
        self::assertSame(['2026-10-04', '2026-10-10'], [$ini->toDateString(), $fim->toDateString()]);

        [$ini, $fim] = CalendarBuilder::range(CalendarView::Day, $ancora);
        self::assertSame(['2026-10-07', '2026-10-07'], [$ini->toDateString(), $fim->toDateString()]);
    }

    public function test_navegacao_anda_uma_pagina_e_o_mes_nao_pula(): void
    {
        $dia31 = CarbonImmutable::parse('2026-01-31');

        self::assertSame('2026-02-01', CalendarBuilder::shift(CalendarView::Month, $dia31, 1)->toDateString());
        self::assertSame('2025-12-01', CalendarBuilder::shift(CalendarView::Month, $dia31, -1)->toDateString());
        self::assertSame('2026-02-07', CalendarBuilder::shift(CalendarView::Week, $dia31, 1)->toDateString());
        self::assertSame('2026-02-01', CalendarBuilder::shift(CalendarView::Day, $dia31, 1)->toDateString());
    }

    public function test_titulo_de_cada_visao(): void
    {
        $ancora = CarbonImmutable::parse('2026-10-07');

        self::assertSame('outubro de 2026', mb_strtolower(CalendarBuilder::title(CalendarView::Month, $ancora)));
        self::assertStringContainsString('4 a 10', CalendarBuilder::title(CalendarView::Week, $ancora));
        self::assertStringContainsString('7 de', CalendarBuilder::title(CalendarView::Day, $ancora));
    }

    public function test_mes_agrupa_por_dia_e_corta_em_tres_com_mais_n(): void
    {
        $eventos = collect([
            $this->evento('2026-10-20 08:00'), $this->evento('2026-10-20 09:00', 'B'), $this->evento('2026-10-20 10:00', 'C'),
            $this->evento('2026-10-20 11:00', 'D'), $this->evento('2026-10-20 12:00', 'E'), $this->evento('2026-10-21 08:00', 'F'),
        ]);

        $dados = CalendarBuilder::build(CalendarView::Month, CarbonImmutable::parse('2026-10-07'), $eventos);
        $dias = collect($dados['weeks'])->flatten(1)->keyBy('date');

        self::assertCount(5, $dados['weeks']);
        self::assertSame(5, $dias['2026-10-20']['count']);
        self::assertCount(3, $dias['2026-10-20']['visible']);
        self::assertSame(2, $dias['2026-10-20']['more']);
        self::assertSame(0, $dias['2026-10-21']['more']);
        self::assertSame(6, $dados['total']);
    }

    public function test_compromisso_fora_do_periodo_nao_entra(): void
    {
        $dados = CalendarBuilder::build(CalendarView::Month, CarbonImmutable::parse('2026-10-07'), collect([$this->evento('2026-12-01 08:00')]));

        self::assertSame(0, $dados['total']);
    }

    public function test_dia_inteiro_vem_antes_dos_com_horario_no_mes(): void
    {
        $eventos = collect([
            $this->evento('2026-10-20 08:00', 'Cedo'),
            $this->evento('2026-10-20 00:00', 'Dia todo', ['allDay' => true]),
        ]);

        $dia = collect(CalendarBuilder::build(CalendarView::Month, CarbonImmutable::parse('2026-10-07'), $eventos)['weeks'])->flatten(1)->firstWhere('date', '2026-10-20');

        self::assertSame(['Dia todo', 'Cedo'], array_map(fn ($e) => $e->title, $dia['events']));
    }

    public function test_grade_comeca_as_7_e_vai_ate_as_20_sem_compromissos_fora(): void
    {
        $dados = CalendarBuilder::build(CalendarView::Week, CarbonImmutable::parse('2026-10-07'), collect());

        self::assertSame(7, $dados['hours']['start']);
        self::assertSame(20, $dados['hours']['end']);
        self::assertSame(39.0, $dados['hours']['height']);
        self::assertCount(7, $dados['days']);
    }

    public function test_grade_estica_para_caber_compromisso_cedo_ou_tarde(): void
    {
        $eventos = collect([$this->evento('2026-10-07 05:30', 'Cedo'), $this->evento('2026-10-07 22:15', 'Tarde')]);

        $horas = CalendarBuilder::build(CalendarView::Day, CarbonImmutable::parse('2026-10-07'), $eventos)['hours'];

        self::assertSame(5, $horas['start']);
        self::assertSame(24, $horas['end']);
    }

    public function test_posicao_e_altura_pela_hora_e_duracao(): void
    {
        $eventos = collect([$this->evento('2026-10-07 14:30', 'Consulta', ['end' => CarbonImmutable::parse('2026-10-07 16:00')])]);

        $bloco = CalendarBuilder::build(CalendarView::Day, CarbonImmutable::parse('2026-10-07'), $eventos)['days'][0]['timed'][0];

        self::assertSame(22.5, $bloco['top'], '14h30 numa grade que começa às 7h: 7,5 horas a 3rem');
        self::assertSame(4.5, $bloco['height'], '1h30');
        self::assertSame(100.0, $bloco['width']);
    }

    public function test_sem_fim_conhecido_dura_uma_hora_e_nunca_menos_que_meia(): void
    {
        $semFim = $this->evento('2026-10-07 10:00');
        $curto = $this->evento('2026-10-07 11:00', 'Curto', ['end' => CarbonImmutable::parse('2026-10-07 11:10')]);

        $blocos = CalendarBuilder::build(CalendarView::Day, CarbonImmutable::parse('2026-10-07'), collect([$semFim, $curto]))['days'][0]['timed'];

        self::assertSame(3.0, $blocos[0]['height']);
        self::assertSame(1.5, $blocos[1]['height'], 'no mínimo meia hora, para dar para clicar');
    }

    public function test_sobrepostos_dividem_a_largura_e_os_seguintes_voltam_a_inteira(): void
    {
        $eventos = collect([
            $this->evento('2026-10-07 10:00', 'A', ['end' => CarbonImmutable::parse('2026-10-07 11:30')]),
            $this->evento('2026-10-07 10:30', 'B', ['end' => CarbonImmutable::parse('2026-10-07 11:00')]),
            $this->evento('2026-10-07 11:00', 'C', ['end' => CarbonImmutable::parse('2026-10-07 12:00')]),
            $this->evento('2026-10-07 15:00', 'D'),
        ]);

        $b = collect(CalendarBuilder::build(CalendarView::Day, CarbonImmutable::parse('2026-10-07'), $eventos)['days'][0]['timed'])->keyBy(fn ($x) => $x['event']->title);

        self::assertSame([0.0, 50.0], [$b['A']['left'], $b['A']['width']]);
        self::assertSame([50.0, 50.0], [$b['B']['left'], $b['B']['width']]);
        // C começa quando B acaba (11:00), mas A ainda ocupa a primeira faixa: C vai para a segunda, no mesmo grupo.
        self::assertSame([50.0, 50.0], [$b['C']['left'], $b['C']['width']]);
        self::assertSame([0.0, 100.0], [$b['D']['left'], $b['D']['width']], 'grupo novo: largura inteira');
    }

    public function test_dia_inteiro_fica_fora_da_grade_e_marca_a_faixa_propria(): void
    {
        $eventos = collect([$this->evento('2026-10-07 00:00', 'Prazo', ['allDay' => true]), $this->evento('2026-10-07 09:00', 'Reunião')]);

        $dados = CalendarBuilder::build(CalendarView::Day, CarbonImmutable::parse('2026-10-07'), $eventos);

        self::assertTrue($dados['has_all_day']);
        self::assertCount(1, $dados['days'][0]['all_day']);
        self::assertCount(1, $dados['days'][0]['timed']);
    }

    public function test_hoje_e_o_periodo_atual_sao_marcados(): void
    {
        $dados = CalendarBuilder::build(CalendarView::Week, CarbonImmutable::parse('2026-10-07'), collect());

        self::assertTrue($dados['is_current']);
        self::assertSame(['2026-10-07'], array_column(array_filter($dados['days'], fn ($d) => $d['today']), 'date'));

        $outra = CalendarBuilder::build(CalendarView::Week, CarbonImmutable::parse('2026-12-07'), collect());
        self::assertFalse($outra['is_current']);
    }

    public function test_evento_so_aceita_cor_no_formato_hex(): void
    {
        self::assertSame('#16A34A', $this->evento('2026-10-07 10:00', 'x', ['color' => '#16a34a'])->color);
        self::assertSame('#64748B', $this->evento('2026-10-07 10:00', 'y', ['color' => 'red; background:url(x)'])->color);
    }

    public function test_rotulo_de_horario_e_titulo_curto(): void
    {
        $com = $this->evento('2026-10-07 10:00', 'Com fim', ['end' => CarbonImmutable::parse('2026-10-07 11:15'), 'shortTitle' => 'Curto']);

        self::assertSame('10:00 – 11:15', $com->timeLabel());
        self::assertSame('10:00', $this->evento('2026-10-07 10:00')->timeLabel());
        self::assertSame('', $this->evento('2026-10-07 00:00', 'Dia', ['allDay' => true])->timeLabel());
        self::assertSame('Curto', $com->chipTitle());
        self::assertSame('Evento', $this->evento('2026-10-07 10:00')->chipTitle());
    }
}
