<?php

namespace App\Livewire\Concerns;

use App\Enums\CalendarView;
use App\Support\Calendar\CalendarBuilder;
use App\Support\Calendar\CalendarEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * O que um componente precisa para mostrar o calendário (`<x-calendar>`): a visão (mês, semana ou dia), a data em foco, a
 * navegação e o compromisso aberto. A visão e a data ficam na URL, então voltar e recarregar mostram o mesmo lugar; a visão
 * que a pessoa escolheu por último fica na conta e vale na próxima vez, em qualquer aparelho.
 *
 * Quem usa implementa `calendarEvents(start, end)` com os compromissos do período e passa `calendarData()` à tela.
 */
trait HasCalendar
{
    #[Url(as: 'visao')]
    public string $calView = '';

    /** O dia em foco ("2026-10-20"); o mês, a semana e o dia mostrados são os que o contêm. */
    #[Url(as: 'data')]
    public string $calDate = '';

    /** Chave do compromisso aberto no detalhe (ver CalendarEvent::$key). */
    public ?string $openEvent = null;

    public bool $eventVisible = false;

    public function mountHasCalendar(): void
    {
        $this->calView = CalendarView::tryFrom($this->calView)?->value
            ?? (auth()->user()?->calendar_view?->value ?? CalendarView::Month->value);
        $this->calDate = CalendarBuilder::anchor($this->calDate)->toDateString();
    }

    public function setCalView(string $visao): void
    {
        $escolha = CalendarView::tryFrom($visao);

        if ($escolha === null) {
            return;
        }

        $this->calView = $escolha->value;
        $this->closeEvent();

        // Fica na conta: a próxima vez abre na visão que a pessoa usa.
        auth()->user()?->update(['calendar_view' => $escolha]);
    }

    public function previousPeriod(): void
    {
        $this->calDate = CalendarBuilder::shift($this->currentView(), $this->anchor(), -1)->toDateString();
        $this->closeEvent();
    }

    public function nextPeriod(): void
    {
        $this->calDate = CalendarBuilder::shift($this->currentView(), $this->anchor(), 1)->toDateString();
        $this->closeEvent();
    }

    public function goToToday(): void
    {
        $this->calDate = CarbonImmutable::today()->toDateString();
        $this->closeEvent();
    }

    /** Tocar no número do dia (ou no "+N mais") abre aquele dia, como no Google Agenda. */
    public function goToDay(string $data): void
    {
        $dia = CalendarBuilder::anchor($data);

        $this->calDate = $dia->toDateString();
        $this->calView = CalendarView::Day->value;
        $this->closeEvent();
    }

    public function openCalendarEvent(string $chave): void
    {
        $this->openEvent = $chave;
        $this->eventVisible = true;
    }

    public function closeEvent(): void
    {
        $this->openEvent = null;
        $this->eventVisible = false;
    }

    protected function currentView(): CalendarView
    {
        return CalendarView::tryFrom($this->calView) ?? CalendarView::Month;
    }

    protected function anchor(): CarbonImmutable
    {
        return CalendarBuilder::anchor($this->calDate);
    }

    /**
     * O que a tela passa ao `<x-calendar>`: o período montado, mais o compromisso aberto (se ainda existir no período).
     *
     * @return array<string, mixed>
     */
    protected function calendarData(): array
    {
        $visao = $this->currentView();
        $ancora = $this->anchor();
        [$inicio, $fim] = CalendarBuilder::range($visao, $ancora);

        $dados = CalendarBuilder::build($visao, $ancora, $this->calendarEvents($inicio, $fim));
        $dados['open'] = $this->openEvent !== null ? ($dados['by_key'][$this->openEvent] ?? null) : null;

        return $dados;
    }

    /**
     * Os compromissos do período, já como CalendarEvent. Só leitura, e só o que a pessoa pode ver.
     *
     * @return Collection<int, CalendarEvent>
     */
    abstract protected function calendarEvents(CarbonImmutable $inicio, CarbonImmutable $fim): Collection;
}
