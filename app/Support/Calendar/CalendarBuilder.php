<?php

namespace App\Support\Calendar;

use App\Enums\CalendarView;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Monta o que o calendário desenha a partir dos compromissos: o mês em semanas de domingo a sábado, a semana e o dia numa grade de
 * horários com cada compromisso posicionado (e lado a lado quando se sobrepõem), mais a lista do período para o celular.
 *
 * Só calcula; não conhece Saúde nem consultor. Quem usa pede `range()` para saber o que buscar e entrega `CalendarEvent`s.
 */
final class CalendarBuilder
{
    /** Altura de uma hora na grade, em rem. */
    public const HOUR_REM = 3.0;

    /** Horário que a grade mostra sempre; compromissos fora dele esticam a grade. */
    private const DEFAULT_START_HOUR = 7;

    private const DEFAULT_END_HOUR = 20;

    /** Quantos compromissos cabem em uma célula do mês antes do "+N mais". */
    public const MONTH_VISIBLE = 3;

    /** A data âncora a partir do texto da URL; texto que não é data vira hoje. */
    public static function anchor(?string $date): CarbonImmutable
    {
        if ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            $dia = CarbonImmutable::createFromFormat('!Y-m-d', $date);

            if ($dia !== false && $dia->toDateString() === $date && $dia->year >= 2000 && $dia->year <= 2100) {
                return $dia;
            }
        }

        return CarbonImmutable::today();
    }

    /**
     * O período que a visão cobre. No mês, a grade inteira (com os dias dos meses vizinhos que completam as semanas).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function range(CalendarView $visao, CarbonImmutable $ancora): array
    {
        return match ($visao) {
            CalendarView::Month => [
                $ancora->startOfMonth()->startOfWeek(CarbonInterface::SUNDAY),
                $ancora->endOfMonth()->endOfWeek(CarbonInterface::SATURDAY),
            ],
            CalendarView::Week => [$ancora->startOfWeek(CarbonInterface::SUNDAY), $ancora->endOfWeek(CarbonInterface::SATURDAY)],
            CalendarView::Day => [$ancora->startOfDay(), $ancora->endOfDay()],
        };
    }

    /** Anda uma página para a frente (1) ou para trás (-1) na visão. */
    public static function shift(CalendarView $visao, CarbonImmutable $ancora, int $sentido): CarbonImmutable
    {
        $sentido = $sentido >= 0 ? 1 : -1;

        return match ($visao) {
            // O dia 31 cairia em fevereiro: o mês anda a partir do dia 1.
            CalendarView::Month => $ancora->startOfMonth()->addMonths($sentido),
            CalendarView::Week => $ancora->addWeeks($sentido),
            CalendarView::Day => $ancora->addDays($sentido),
        };
    }

    /** O título do período: "outubro de 2026", "20 a 26 de out. de 2026" ou "terça-feira, 20 de outubro de 2026". */
    public static function title(CalendarView $visao, CarbonImmutable $ancora): string
    {
        [$inicio, $fim] = self::range($visao, $ancora);

        return match ($visao) {
            CalendarView::Month => $ancora->translatedFormat('F \d\e Y'),
            CalendarView::Week => $inicio->month === $fim->month
                ? $inicio->day.' a '.$fim->translatedFormat('j \d\e F \d\e Y')
                : $inicio->translatedFormat('j \d\e M').' a '.$fim->translatedFormat('j \d\e M \d\e Y'),
            CalendarView::Day => $ancora->translatedFormat('l, j \d\e F \d\e Y'),
        };
    }

    /**
     * @param  Collection<int, CalendarEvent>  $eventos  só os do período (ver range())
     * @return array<string, mixed>
     */
    public static function build(CalendarView $visao, CarbonImmutable $ancora, Collection $eventos): array
    {
        [$inicio, $fim] = self::range($visao, $ancora);
        $hoje = CarbonImmutable::today();
        $noPeriodo = $eventos->filter(fn (CalendarEvent $e) => $e->start->betweenIncluded($inicio->startOfDay(), $fim->endOfDay()))
            ->sortBy(fn (CalendarEvent $e) => [$e->allDay ? 0 : 1, $e->start->getTimestamp(), $e->title])
            ->values();
        $porDia = $noPeriodo->groupBy(fn (CalendarEvent $e) => $e->date());

        $dias = [];

        for ($d = $inicio->startOfDay(); $d <= $fim; $d = $d->addDay()) {
            $data = $d->toDateString();
            $doDia = $porDia->get($data, collect());

            $dias[] = [
                'date' => $data,
                'day' => $d->day,
                'weekday' => $d->translatedFormat('D'),
                'label' => $d->translatedFormat('l, j \d\e F'),
                'in_month' => $d->month === $ancora->month,
                'today' => $d->isSameDay($hoje),
                'events' => $doDia->values()->all(),
                'count' => $doDia->count(),
            ];
        }

        $base = [
            'view' => $visao->value,
            'anchor' => $ancora->toDateString(),
            'title' => self::title($visao, $ancora),
            'range' => [$inicio->toDateString(), $fim->toDateString()],
            'is_current' => $hoje->betweenIncluded($inicio->startOfDay(), $fim->endOfDay()),
            'by_key' => $eventos->keyBy('key')->all(),
            'total' => $noPeriodo->count(),
        ];

        if ($visao === CalendarView::Month) {
            $semanas = array_chunk($dias, 7);

            return $base + [
                'weeks' => array_map(fn (array $semana) => array_map(function (array $dia): array {
                    $dia['visible'] = array_slice($dia['events'], 0, self::MONTH_VISIBLE);
                    $dia['more'] = max(0, $dia['count'] - self::MONTH_VISIBLE);

                    return $dia;
                }, $semana), $semanas),
            ];
        }

        $horas = self::hours($noPeriodo);

        foreach ($dias as $i => $dia) {
            $doDia = collect($dia['events']);
            $dias[$i]['all_day'] = $doDia->filter(fn (CalendarEvent $e) => $e->allDay)->values()->all();
            $dias[$i]['timed'] = self::position($doDia->reject(fn (CalendarEvent $e) => $e->allDay)->values(), $horas['start']);
        }

        return $base + [
            'days' => $dias,
            'hours' => $horas,
            'has_all_day' => collect($dias)->contains(fn (array $d) => $d['all_day'] !== []),
            'now' => self::nowMarker($horas),
        ];
    }

    /**
     * As horas que a grade mostra: das 7 às 20 sempre, esticada para caber o compromisso mais cedo e o mais tarde.
     *
     * @param  Collection<int, CalendarEvent>  $eventos
     * @return array{start: int, end: int, rows: list<int>, height: float}
     */
    private static function hours(Collection $eventos): array
    {
        $comHorario = $eventos->reject(fn (CalendarEvent $e) => $e->allDay);
        $inicio = self::DEFAULT_START_HOUR;
        $fim = self::DEFAULT_END_HOUR;

        foreach ($comHorario as $e) {
            $inicio = min($inicio, $e->start->hour);
            $fim = max($fim, (int) ceil(($e->endsAt()->hour * 60 + $e->endsAt()->minute) / 60));
        }

        $inicio = max(0, $inicio);
        $fim = min(24, max($fim, $inicio + 1));

        return ['start' => $inicio, 'end' => $fim, 'rows' => range($inicio, $fim - 1), 'height' => ($fim - $inicio) * self::HOUR_REM];
    }

    /**
     * Posição de cada compromisso do dia na grade: distância do topo e altura em rem, e a faixa (coluna) dentro do dia, para os
     * que se sobrepõem ficarem lado a lado.
     *
     * @param  Collection<int, CalendarEvent>  $doDia  só os com horário
     * @return list<array{event: CalendarEvent, top: float, height: float, left: float, width: float}>
     */
    private static function position(Collection $doDia, int $primeiraHora): array
    {
        $itens = $doDia->sortBy(fn (CalendarEvent $e) => $e->start->getTimestamp())->values()->map(function (CalendarEvent $e) use ($primeiraHora): array {
            $minutoInicio = $e->start->hour * 60 + $e->start->minute;
            $minutoFim = $e->endsAt()->hour * 60 + $e->endsAt()->minute;
            // O fim à meia-noite (23:59:59) conta como 24:00.
            $minutoFim = $e->endsAt()->isSameDay($e->start) && $e->endsAt()->format('H:i:s') === '23:59:59' ? 24 * 60 : $minutoFim;

            return [
                'event' => $e, 'from' => $minutoInicio, 'to' => max($minutoFim, $minutoInicio + 30),
                'top' => ($minutoInicio - $primeiraHora * 60) / 60 * self::HOUR_REM,
                'height' => max($minutoFim - $minutoInicio, 30) / 60 * self::HOUR_REM,
                'lane' => 0, 'lanes' => 1,
            ];
        })->all();

        // Grupos de compromissos que se encostam: dentro do grupo, cada um ocupa a primeira faixa livre.
        $grupo = [];
        $fimDoGrupo = -1;
        $grupos = [];

        foreach ($itens as $i => $item) {
            if ($grupo !== [] && $item['from'] >= $fimDoGrupo) {
                $grupos[] = $grupo;
                $grupo = [];
            }

            $grupo[] = $i;
            $fimDoGrupo = max($fimDoGrupo, $item['to']);
        }

        if ($grupo !== []) {
            $grupos[] = $grupo;
        }

        foreach ($grupos as $indices) {
            $fimDasFaixas = [];

            foreach ($indices as $i) {
                $faixa = 0;

                while (isset($fimDasFaixas[$faixa]) && $fimDasFaixas[$faixa] > $itens[$i]['from']) {
                    $faixa++;
                }

                $fimDasFaixas[$faixa] = $itens[$i]['to'];
                $itens[$i]['lane'] = $faixa;
            }

            foreach ($indices as $i) {
                $itens[$i]['lanes'] = count($fimDasFaixas);
            }
        }

        return array_map(fn (array $item) => [
            'event' => $item['event'],
            'top' => round($item['top'], 3),
            'height' => round($item['height'], 3),
            'left' => round($item['lane'] / $item['lanes'] * 100, 3),
            'width' => round(100 / $item['lanes'], 3),
        ], $itens);
    }

    /** A linha de "agora" na coluna de hoje: distância do topo em rem, ou nulo quando agora fica fora da grade. */
    private static function nowMarker(array $horas): ?float
    {
        $agora = CarbonImmutable::now();
        $minutos = $agora->hour * 60 + $agora->minute - $horas['start'] * 60;

        return $minutos >= 0 && $minutos <= ($horas['end'] - $horas['start']) * 60 ? round($minutos / 60 * self::HOUR_REM, 3) : null;
    }
}
