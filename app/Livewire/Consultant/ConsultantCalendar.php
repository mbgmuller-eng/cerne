<?php

namespace App\Livewire\Consultant;

use App\Livewire\Concerns\HasCalendar;
use App\Services\ConsultantAgendaService;
use App\Support\Calendar\CalendarEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A agenda do profissional: reuniões com os clientes, prazos das tarefas e retornos de leads, em mês, semana ou dia. O
 * consultor financeiro vê as três coisas; o corretor, só os retornos de leads. Só leitura: marcar e remarcar continua nas telas
 * de cada acompanhamento e do quadro de leads.
 */
#[Layout('components.layouts.app', ['wide' => true])]
class ConsultantCalendar extends Component
{
    use HasCalendar;

    /** O que aparece na agenda; guardado na URL para valer ao recarregar. Vazio = tudo o que o profissional pode ver. */
    #[Url(as: 'ver')]
    public array $types = [];

    public function mount(ConsultantAgendaService $agenda): void
    {
        abort_unless(auth()->user()?->isConsultant() || auth()->user()?->isBroker(), 403);

        $permitidos = array_keys($agenda->typesFor(auth()->user()));
        $escolhidos = array_values(array_intersect($this->types, $permitidos));
        $this->types = $escolhidos === [] ? $permitidos : $escolhidos;
    }

    /** Liga ou desliga um tipo de compromisso; sempre fica ao menos um ligado. */
    public function toggleType(string $tipo, ConsultantAgendaService $agenda): void
    {
        if (! array_key_exists($tipo, $agenda->typesFor(auth()->user()))) {
            return;
        }

        $novos = in_array($tipo, $this->types, true) ? array_values(array_diff($this->types, [$tipo])) : [...$this->types, $tipo];

        if ($novos !== []) {
            $this->types = $novos;
            $this->closeEvent();
        }
    }

    /** @return Collection<int, CalendarEvent> */
    protected function calendarEvents(CarbonImmutable $inicio, CarbonImmutable $fim): Collection
    {
        return app(ConsultantAgendaService::class)->events(auth()->user(), $inicio, $fim, $this->types);
    }

    public function render(ConsultantAgendaService $agenda)
    {
        return view('livewire.consultant.consultant-calendar', [
            'calendario' => $this->calendarData(),
            'tipos' => $agenda->typesFor(auth()->user()),
            'cores' => ConsultantAgendaService::COLORS,
        ]);
    }
}
