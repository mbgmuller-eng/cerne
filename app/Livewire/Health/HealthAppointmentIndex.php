<?php

namespace App\Livewire\Health;

use App\Enums\AgendaLayout;
use App\Livewire\Concerns\HasCalendar;
use App\Livewire\Concerns\RequiresActiveProfile;
use App\Livewire\Concerns\RequiresPersonalHealth;
use App\Models\HealthAppointment;
use App\Models\HealthCareItem;
use App\Models\ProfileMember;
use App\Services\HealthAppointmentService;
use App\Support\Calendar\CalendarEvent;
use App\Support\MemberPalette;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Agenda de consulta/exame do casal — mesma visibilidade da Ficha de
 * Saúde (CoupleHealthScope): os dois veem tudo, consultor não vê nada.
 *
 * A tela mostra a lista (próximas e histórico) e o calendário em mês, semana ou dia (HasCalendar); a
 * preferência da pessoa (AgendaLayout, guardada na conta) só decide qual vem primeiro. No calendário,
 * cada pessoa do perfil tem a sua cor (ProfileMember::calendarColor), editável por qualquer uma das duas.
 */
#[Layout('components.layouts.app')]
class HealthAppointmentIndex extends Component
{
    use HasCalendar, RequiresActiveProfile, RequiresPersonalHealth;

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

    public bool $showColors = false;

    public function mount(): void
    {
        $this->redirectOrAbortWithoutProfile();
        $this->abortUnlessHealthParticipant();
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

    private function parseDay(string $date): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        $dia = CarbonImmutable::createFromFormat('!Y-m-d', $date);

        return $dia !== false && $dia->toDateString() === $date ? $dia : null;
    }

    /**
     * Os compromissos do período: consulta e exame com horário, na cor de quem é, e item de cuidado como compromisso de dia
     * inteiro (vazado). Cada compromisso já traz o que a pessoa precisa ver sem abrir: o título, o horário, quem é e onde.
     *
     * @return Collection<int, CalendarEvent>
     */
    protected function calendarEvents(CarbonImmutable $inicio, CarbonImmutable $fim): Collection
    {
        $profileId = app(ProfileContext::class)->profileId();
        $membros = $this->membros();
        $ehCasal = $membros->count() > 1;
        $cores = $membros->mapWithKeys(fn (ProfileMember $m) => [$m->id => $m->calendarColor()]);
        $padrao = MemberPalette::defaultFor(null);

        $consultas = HealthAppointment::query()
            ->where('profile_id', $profileId)
            ->whereBetween('scheduled_at', [$inicio->startOfDay(), $fim->endOfDay()])
            ->with('member')
            ->orderBy('scheduled_at')
            ->get()
            ->map(function (HealthAppointment $c) use ($cores, $padrao, $ehCasal): CalendarEvent {
                $quemAtende = implode(' · ', array_filter([$c->specialty, $c->professional_name]));

                return new CalendarEvent(
                    key: 'consulta:'.$c->id,
                    title: $c->title,
                    start: CarbonImmutable::instance($c->scheduled_at),
                    color: $cores[$c->member_id] ?? $padrao,
                    tag: $c->kind->label(),
                    lines: array_values(array_filter([
                        $ehCasal ? $c->member->name : null,
                        $quemAtende !== '' && $quemAtende !== $c->title ? $quemAtende : null,
                        $c->location,
                    ])),
                    muted: $c->isPast(),
                    // O detalhe já mostra quem atende, onde e como falar (x-appointment-details): aqui só entra de quem é.
                    detailLines: $ehCasal ? [$c->member->name] : [],
                );
            });

        $cuidados = HealthCareItem::query()
            ->where('profile_id', $profileId)
            ->where('is_active', true)
            ->whereBetween('next_due_on', [$inicio->toDateString(), $fim->toDateString()])
            ->with('member')
            ->get()
            ->map(fn (HealthCareItem $i): CalendarEvent => new CalendarEvent(
                key: 'cuidado:'.$i->id,
                title: 'Trocar ou revisar: '.$i->name,
                start: CarbonImmutable::parse($i->next_due_on->toDateString())->startOfDay(),
                allDay: true,
                color: $cores[$i->member_id] ?? $padrao,
                tag: 'Item de cuidado',
                lines: array_values(array_filter([$ehCasal ? $i->member->name : null, $i->device_name, $i->frequencyLabel()])),
                hollow: true,
                shortTitle: $i->name,
                detailLines: array_values(array_filter([$ehCasal ? $i->member->name : null, $i->device_name, $i->frequencyLabel()])),
            ));

        return $consultas->concat($cuidados)->values();
    }

    /** A consulta ou o item de cuidado aberto no detalhe do calendário, para a tela mostrar o que é próprio de cada um. */
    private function compromissoAberto(): array
    {
        [$tipo, $id] = array_pad(explode(':', (string) $this->openEvent, 2), 2, null);

        return [
            'consultaAberta' => $tipo === 'consulta' && $id !== null ? HealthAppointment::query()->with('member')->find($id) : null,
            'cuidadoAberto' => $tipo === 'cuidado' && $id !== null ? HealthCareItem::query()->with('member')->find($id) : null,
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
        $this->closeEvent();
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

    /** "Eu levo": qualquer participante, inclusive o de leitura (ver HealthAppointmentService::volunteerEscort). */
    public function takeEscort(string $appointmentId, HealthAppointmentService $service): void
    {
        $service->volunteerEscort($this->consultaDeCuidado($appointmentId), auth()->user());
    }

    public function assignEscort(string $appointmentId, string $userId, HealthAppointmentService $service): void
    {
        if ($userId === '') {
            $service->releaseEscort($this->consultaDeCuidado($appointmentId), auth()->user());

            return;
        }

        $service->assignEscort($this->consultaDeCuidado($appointmentId), \App\Models\User::query()->findOrFail($userId), auth()->user());
    }

    public function releaseEscort(string $appointmentId, HealthAppointmentService $service): void
    {
        $service->releaseEscort($this->consultaDeCuidado($appointmentId), auth()->user());
    }

    /** A consulta, só se o perfil ativo é de uma pessoa acompanhada ("quem leva" só existe ali). */
    private function consultaDeCuidado(string $appointmentId): HealthAppointment
    {
        abort_unless(app(ProfileContext::class)->profile()?->isCare(), 404);

        return HealthAppointment::query()->findOrFail($appointmentId);
    }

    public function delete(string $appointmentId, HealthAppointmentService $service): void
    {
        $service->delete(HealthAppointment::query()->findOrFail($appointmentId));
        $this->closeEvent();
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
            'calendario' => $this->calendarData(),
            'upcoming' => HealthAppointment::query()->where('profile_id', $profileId)->upcoming()->with(['member', 'createdByUser', 'escort'])->get(),
            'past' => HealthAppointment::query()->where('profile_id', $profileId)->past()->with(['member', 'createdByUser'])->get(),
            'editingExisting' => $this->editingId !== null,
            'emPessoaAcompanhada' => app(ProfileContext::class)->isCaregiver() || app(ProfileContext::class)->profile()?->isCare(),
            // Quem pode ser escolhido para levar: dono, cuidadores ativos e a própria pessoa (só dentro de uma pessoa acompanhada).
            'participantes' => app(ProfileContext::class)->profile()?->isCare() ? app(\App\Services\CareParticipants::class)->forProfile(app(ProfileContext::class)->profile()) : collect(),
            'podeEscalar' => app(ProfileContext::class)->canWriteAsCaregiver(),
        ] + $this->compromissoAberto());
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

    private function autor(): ?ProfileMember
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
