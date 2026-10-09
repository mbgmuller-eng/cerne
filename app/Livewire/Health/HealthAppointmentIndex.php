<?php

namespace App\Livewire\Health;

use App\Enums\AgendaLayout;
use App\Livewire\Concerns\RequiresActiveProfile;
use App\Livewire\Concerns\RequiresPersonalHealth;
use App\Models\HealthAppointment;
use App\Models\HealthCareItem;
use App\Models\ProfileMember;
use App\Services\HealthAppointmentService;
use App\Support\MemberPalette;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Agenda de consulta/exame do casal — mesma visibilidade da Ficha de
 * Saúde (CoupleHealthScope): os dois veem tudo, consultor não vê nada.
 *
 * A tela mostra a lista (próximas e histórico) e o calendário do mês; a preferência da pessoa
 * (AgendaLayout, guardada na conta) só decide qual vem primeiro. No calendário, cada pessoa do
 * perfil tem a sua cor (ProfileMember::calendarColor), editável por qualquer uma das duas.
 */
#[Layout('components.layouts.app')]
class HealthAppointmentIndex extends Component
{
    use RequiresActiveProfile, RequiresPersonalHealth;

    public bool $showForm = false;
    public ?string $editingId = null;
    public ?string $memberId = null;
    public string $kind = 'consultation';
    public string $title = '';
    public string $professionalName = '';
    public string $specialty = '';
    /** Nome do estabelecimento (clínica, hospital, laboratório): a coluna `location`. */
    public string $location = '';
    public string $address = '';
    public string $phone = '';
    public string $bookedByName = '';
    public string $bookedWithName = '';
    public string $scheduledDate = '';
    public string $scheduledTime = '';
    public string $notes = '';

    /** Mês mostrado no calendário ("2026-10"); na URL para o mês visto sobreviver ao recarregar. */
    #[Url(as: 'mes')]
    public string $calMonth = '';

    /** Dia tocado no calendário ("2026-10-20"), cujos compromissos aparecem logo abaixo. */
    public ?string $selectedDate = null;

    public bool $showColors = false;

    public function mount(): void
    {
        $this->redirectOrAbortWithoutProfile();
        $this->abortUnlessPersonalHealthOwner();

        $this->calMonth = $this->validMonth($this->calMonth);
    }

    public function updatedCalMonth(): void
    {
        $this->calMonth = $this->validMonth($this->calMonth);
    }

    // -----------------------------------------------------------------
    // Calendário
    // -----------------------------------------------------------------

    public function setLayout(string $layout): void
    {
        $escolha = AgendaLayout::tryFrom($layout);

        if ($escolha !== null) {
            auth()->user()->update(['agenda_layout' => $escolha]);
        }
    }

    public function previousMonth(): void
    {
        $this->calMonth = $this->monthStart()->subMonth()->format('Y-m');
        $this->selectedDate = null;
    }

    public function nextMonth(): void
    {
        $this->calMonth = $this->monthStart()->addMonth()->format('Y-m');
        $this->selectedDate = null;
    }

    public function goToday(): void
    {
        $this->calMonth = CarbonImmutable::today()->format('Y-m');
        $this->selectedDate = CarbonImmutable::today()->toDateString();
    }

    public function selectDay(string $date): void
    {
        $dia = $this->parseDay($date);

        if ($dia === null) {
            return;
        }

        $this->selectedDate = $dia->toDateString();
        // Tocar num dia do mês vizinho (as pontas da grade) leva para o mês dele.
        $this->calMonth = $dia->format('Y-m');
    }

    public function clearDay(): void
    {
        $this->selectedDate = null;
    }

    public function toggleColors(): void
    {
        $this->showColors = ! $this->showColors;
    }

    /** Qualquer pessoa do perfil escolhe a cor de qualquer uma das duas; só vale cor da paleta. */
    public function setMemberColor(string $memberId, string $hex): void
    {
        if (! MemberPalette::isAllowed($hex)) {
            return;
        }

        $this->membroOuFalha($memberId)->update(['color_hex' => strtoupper($hex)]);
    }

    public function newAppointmentOn(string $date): void
    {
        $this->newAppointment();

        $dia = $this->parseDay($date);

        if ($dia !== null) {
            $this->scheduledDate = $dia->toDateString();
        }
    }

    private function validMonth(string $mes): string
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes) === 1 && (int) substr($mes, 0, 4) >= 2000
            ? $mes
            : CarbonImmutable::today()->format('Y-m');
    }

    private function monthStart(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $this->validMonth($this->calMonth));
    }

    private function parseDay(string $date): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        $dia = CarbonImmutable::createFromFormat('!Y-m-d', $date);

        return $dia !== false && $dia->toDateString() === $date ? $dia : null;
    }

    /**
     * Semanas do mês (domingo a sábado) com, em cada dia, as marcas de compromisso (bolinha cheia: consulta
     * ou exame) e de item de cuidado (bolinha vazada), na cor de quem é.
     *
     * @param  Collection<int, ProfileMember>  $membros
     * @return array{semanas: list<list<array<string, mixed>>>, rotulo: string, primeiro: CarbonImmutable}
     */
    private function calendario(Collection $membros): array
    {
        $profileId = app(ProfileContext::class)->profileId();
        $primeiro = $this->monthStart();
        $inicio = $primeiro->startOfWeek(CarbonInterface::SUNDAY);
        $fim = $primeiro->endOfMonth()->endOfWeek(CarbonInterface::SATURDAY);
        $cores = $membros->mapWithKeys(fn (ProfileMember $m) => [$m->id => $m->calendarColor()]);
        $padrao = MemberPalette::defaultFor(null);

        $consultas = HealthAppointment::query()
            ->where('profile_id', $profileId)
            ->whereBetween('scheduled_at', [$inicio->startOfDay(), $fim->endOfDay()])
            ->orderBy('scheduled_at')
            ->get()
            ->groupBy(fn (HealthAppointment $c) => $c->scheduled_at->toDateString());

        $cuidados = HealthCareItem::query()
            ->where('profile_id', $profileId)
            ->where('is_active', true)
            ->whereBetween('next_due_on', [$inicio->toDateString(), $fim->toDateString()])
            ->get()
            ->groupBy(fn (HealthCareItem $i) => $i->next_due_on->toDateString());

        $hoje = CarbonImmutable::today();
        $semanas = [];
        $posicao = 0;

        for ($dia = $inicio; $dia <= $fim; $dia = $dia->addDay()) {
            $data = $dia->toDateString();
            $marcas = [];

            foreach ($consultas->get($data, collect()) as $consulta) {
                $marcas[] = ['cor' => $cores[$consulta->member_id] ?? $padrao, 'tipo' => 'consulta'];
            }
            foreach ($cuidados->get($data, collect()) as $item) {
                $marcas[] = ['cor' => $cores[$item->member_id] ?? $padrao, 'tipo' => 'cuidado'];
            }

            $semanas[intdiv($posicao++, 7)][] = [
                'data' => $data,
                'dia' => $dia->day,
                'doMes' => $dia->month === $primeiro->month,
                'hoje' => $dia->isSameDay($hoje),
                'selecionado' => $data === $this->selectedDate,
                'marcas' => $marcas,
                'rotulo' => $dia->translatedFormat('j \d\e F').(count($marcas) > 0 ? ', '.count($marcas).' '.(count($marcas) === 1 ? 'compromisso' : 'compromissos') : ''),
            ];
        }

        return [
            'semanas' => array_values($semanas),
            'rotulo' => $primeiro->translatedFormat('F \d\e Y'),
            'primeiro' => $primeiro,
        ];
    }

    /** Compromissos e itens de cuidado do dia tocado no calendário. */
    private function diaSelecionado(): ?array
    {
        $dia = $this->selectedDate !== null ? $this->parseDay($this->selectedDate) : null;

        if ($dia === null) {
            return null;
        }

        $profileId = app(ProfileContext::class)->profileId();

        return [
            'data' => $dia,
            'consultas' => HealthAppointment::query()
                ->where('profile_id', $profileId)
                ->whereBetween('scheduled_at', [$dia->startOfDay(), $dia->endOfDay()])
                ->orderBy('scheduled_at')
                ->with('member')
                ->get(),
            'cuidados' => HealthCareItem::query()
                ->where('profile_id', $profileId)
                ->where('is_active', true)
                ->whereDate('next_due_on', $dia->toDateString())
                ->with('member')
                ->get(),
        ];
    }

    public function newAppointment(): void
    {
        $this->resetForm();
        $this->memberId = $this->membros()->first()?->id;
        $this->showForm = true;
    }

    public function editAppointment(string $appointmentId): void
    {
        $consulta = HealthAppointment::query()->findOrFail($appointmentId);

        $this->resetForm();
        $this->editingId = $consulta->id;
        $this->memberId = $consulta->member_id;
        $this->kind = $consulta->kind->value;
        $this->title = $consulta->title;
        $this->professionalName = (string) $consulta->professional_name;
        $this->specialty = (string) $consulta->specialty;
        $this->location = (string) $consulta->location;
        $this->address = (string) $consulta->address;
        $this->phone = (string) $consulta->phone;
        $this->bookedByName = (string) $consulta->booked_by_name;
        $this->bookedWithName = (string) $consulta->booked_with_name;
        $this->scheduledDate = $consulta->scheduled_at->toDateString();
        $this->scheduledTime = $consulta->scheduled_at->format('H:i');
        $this->notes = (string) $consulta->notes;
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
    }

    public function save(HealthAppointmentService $service): void
    {
        $this->validate([
            'memberId' => ['required', 'string'],
            'kind' => ['required', 'in:consultation,exam'],
            // Consulta com profissional ou especialidade ganha o título sozinha; nos demais casos ele é obrigatório.
            'title' => [
                Rule::requiredIf(fn () => $this->kind === 'exam' || ($this->professionalName === '' && $this->specialty === '')),
                'nullable', 'string', 'max:120',
            ],
            'professionalName' => ['nullable', 'string', 'max:120'],
            'specialty' => ['nullable', 'string', 'max:80'],
            'location' => ['nullable', 'string', 'max:160'],
            'address' => ['nullable', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[\d\s().+\-]{8,30}$/'],
            'bookedByName' => ['nullable', 'string', 'max:120'],
            'bookedWithName' => ['nullable', 'string', 'max:120'],
            'scheduledDate' => ['required', 'date'],
            'scheduledTime' => ['required', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], attributes: [
            'memberId' => 'pessoa', 'kind' => 'tipo', 'title' => 'título', 'professionalName' => 'profissional',
            'specialty' => 'especialidade', 'location' => 'estabelecimento', 'address' => 'endereço', 'phone' => 'telefone',
            'bookedByName' => 'quem agendou', 'bookedWithName' => 'agendado com', 'scheduledDate' => 'data',
            'scheduledTime' => 'hora', 'notes' => 'observações',
        ]);

        $consulta = $this->kind === 'consultation';

        $dados = [
            'kind' => $this->kind,
            'title' => $this->title,
            // Profissional e especialidade só existem em consulta; em exame o título já diz o que é.
            'professional_name' => $consulta ? $this->professionalName : null,
            'specialty' => $consulta ? $this->specialty : null,
            'location' => $this->location,
            'address' => $this->address,
            'phone' => $this->phone,
            'booked_by_name' => $this->bookedByName,
            'booked_with_name' => $this->bookedWithName,
            'scheduled_at' => "{$this->scheduledDate} {$this->scheduledTime}",
            'notes' => $this->notes,
        ];
        if ($this->editingId !== null) {
            $service->update(HealthAppointment::query()->findOrFail($this->editingId), $dados);
        } else {
            $service->create($this->membroOuFalha($this->memberId), $dados, $this->autor());
        }

        $this->showForm = false;
    }

    public function delete(string $appointmentId, HealthAppointmentService $service): void
    {
        $service->delete(HealthAppointment::query()->findOrFail($appointmentId));
    }

    public function render()
    {
        $profileId = app(ProfileContext::class)->profileId();
        $membros = $this->membros();

        return view('livewire.health.health-appointment-index', [
            'membros' => $membros,
            'layout' => auth()->user()->agenda_layout ?? AgendaLayout::List,
            'coresDosMembros' => $membros->mapWithKeys(fn (ProfileMember $m) => [$m->id => $m->calendarColor()])->all(),
            'paleta' => MemberPalette::COLORS,
            'calendario' => $this->calendario($membros),
            'dia' => $this->diaSelecionado(),
            'upcoming' => HealthAppointment::query()->where('profile_id', $profileId)->upcoming()->with('member')->get(),
            'past' => HealthAppointment::query()->where('profile_id', $profileId)->past()->with('member')->get(),
            'editingExisting' => $this->editingId !== null,
        ]);
    }

    private function membros(): Collection
    {
        return ProfileMember::query()
            ->where('profile_id', app(ProfileContext::class)->profileId())
            ->where('is_active', true)
            ->orderBy('role')
            ->get();
    }

    private function membroOuFalha(?string $memberId): ProfileMember
    {
        return ProfileMember::query()
            ->where('profile_id', app(ProfileContext::class)->profileId())
            ->where('is_active', true)
            ->findOrFail($memberId);
    }

    private function autor(): ProfileMember
    {
        return app(ProfileContext::class)->member();
    }

    private function resetForm(): void
    {
        $this->reset(
            'editingId', 'memberId', 'kind', 'title', 'professionalName', 'specialty', 'location', 'address', 'phone',
            'bookedByName', 'bookedWithName', 'scheduledDate', 'scheduledTime', 'notes',
        );
        $this->kind = 'consultation';
        $this->resetErrorBag();
    }
}
