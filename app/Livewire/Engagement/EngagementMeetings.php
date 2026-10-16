<?php

namespace App\Livewire\Engagement;

use App\Enums\EngagementMeetingFormat;
use App\Enums\EngagementMeetingStatus;
use App\Models\EngagementMeeting;
use App\Services\EngagementAccess;
use App\Services\EngagementService;
use App\Support\Engagement\EngagementViewer;
use Carbon\CarbonImmutable;
use Livewire\Component;

/**
 * Reuniões do acompanhamento: a próxima (com pauta e o que levar), as que aguardam ata e o histórico.
 * O consultor marca, remarca, cancela e escreve a ata; os dois lados editam a pauta.
 */
class EngagementMeetings extends Component
{
    // Marcar / remarcar (só o consultor)
    public bool $showForm = false;

    public ?string $editingId = null;

    public string $date = '';

    public string $time = '19:00';

    public int $duration = 45;

    public string $format = 'video';

    public string $location = '';

    public string $agenda = '';

    public string $prep = '';

    // Pauta e "o que levar" (os dois lados)
    public bool $showAgenda = false;

    public ?string $agendaId = null;

    public string $agendaText = '';

    public string $prepText = '';

    // Ata (só o consultor)
    public bool $showSummary = false;

    public ?string $summaryId = null;

    public string $summaryText = '';

    private function visitante(): EngagementViewer
    {
        return app(EngagementAccess::class)->viewer() ?? abort(404);
    }

    public function openForm(?string $id = null): void
    {
        abort_unless($this->visitante()->isConsultant(), 403);

        $this->resetValidation();
        $this->editingId = $id;

        if ($id !== null) {
            $reuniao = EngagementMeeting::query()->findOrFail($id);
            $this->date = $reuniao->scheduled_at->format('Y-m-d');
            $this->time = $reuniao->scheduled_at->format('H:i');
            $this->duration = $reuniao->duration_minutes;
            $this->format = $reuniao->format->value;
            $this->location = (string) $reuniao->location;
            $this->agenda = (string) $reuniao->agenda;
            $this->prep = (string) $reuniao->prep_notes;
        } else {
            $this->reset('date', 'location', 'agenda', 'prep');
            $this->time = '19:00';
            $this->duration = 45;
            $this->format = 'video';
        }

        $this->showForm = true;
    }

    public function save(): void
    {
        $visitante = $this->visitante();
        abort_unless($visitante->isConsultant(), 403);

        $dados = $this->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'duration' => ['required', 'integer', 'min:10', 'max:480'],
            'format' => ['required', EngagementMeetingFormat::rule()],
            'location' => ['nullable', 'string', 'max:255'],
            'agenda' => ['nullable', 'string', 'max:2000'],
            'prep' => ['nullable', 'string', 'max:1000'],
        ], [], ['date' => 'data', 'time' => 'horário', 'duration' => 'duração', 'format' => 'formato', 'location' => 'local ou link', 'agenda' => 'pauta', 'prep' => 'o que levar']);

        $reuniao = $this->editingId !== null ? EngagementMeeting::query()->findOrFail($this->editingId) : null;

        app(EngagementService::class)->saveMeeting($visitante, $reuniao, [
            'scheduled_at' => CarbonImmutable::createFromFormat('Y-m-d H:i', $dados['date'].' '.$dados['time']),
            'duration_minutes' => $dados['duration'],
            'format' => $dados['format'],
            'location' => $dados['location'] ?: null,
            'agenda' => $dados['agenda'] ?: null,
            'prep_notes' => $dados['prep'] ?: null,
        ]);

        $this->showForm = false;
        $this->editingId = null;
    }

    public function openAgenda(string $id): void
    {
        $this->visitante();
        $reuniao = EngagementMeeting::query()->findOrFail($id);

        $this->agendaId = $id;
        $this->agendaText = (string) $reuniao->agenda;
        $this->prepText = (string) $reuniao->prep_notes;
        $this->resetValidation();
        $this->showAgenda = true;
    }

    public function saveAgenda(): void
    {
        $visitante = $this->visitante();

        $dados = $this->validate([
            'agendaText' => ['nullable', 'string', 'max:2000'],
            'prepText' => ['nullable', 'string', 'max:1000'],
        ], [], ['agendaText' => 'pauta', 'prepText' => 'o que levar']);

        app(EngagementService::class)->updateAgenda($visitante, EngagementMeeting::query()->findOrFail($this->agendaId), $dados['agendaText'] ?: null, $dados['prepText'] ?: null);

        $this->showAgenda = false;
    }

    public function openSummary(string $id): void
    {
        abort_unless($this->visitante()->isConsultant(), 403);

        $reuniao = EngagementMeeting::query()->findOrFail($id);

        $this->summaryId = $id;
        $this->summaryText = (string) $reuniao->summary;
        $this->resetValidation();
        $this->showSummary = true;
    }

    public function saveSummary(): void
    {
        $visitante = $this->visitante();

        $dados = $this->validate(['summaryText' => ['nullable', 'string', 'max:5000']], [], ['summaryText' => 'ata']);

        app(EngagementService::class)->completeMeeting($visitante, EngagementMeeting::query()->findOrFail($this->summaryId), $dados['summaryText'] ?: null);

        $this->showSummary = false;
    }

    public function cancelMeeting(string $id): void
    {
        $visitante = $this->visitante();

        app(EngagementService::class)->cancelMeeting($visitante, EngagementMeeting::query()->findOrFail($id));
    }

    public function render()
    {
        $visitante = $this->visitante();
        $inicioDoDia = now()->startOfDay();

        $agendadas = EngagementMeeting::query()->where('status', EngagementMeetingStatus::Scheduled)->orderBy('scheduled_at')->get();

        return view('livewire.engagement.engagement-meetings', [
            'ehConsultor' => $visitante->isConsultant(),
            'proxima' => $agendadas->first(fn (EngagementMeeting $m) => $m->scheduled_at->gte($inicioDoDia)),
            'outrasAgendadas' => $agendadas->filter(fn (EngagementMeeting $m) => $m->scheduled_at->gte($inicioDoDia))->slice(1)->values(),
            // Já passou do dia e ninguém registrou a ata nem cancelou.
            'aguardandoAta' => $agendadas->filter(fn (EngagementMeeting $m) => $m->scheduled_at->lt($inicioDoDia))->values(),
            'historico' => EngagementMeeting::query()->whereIn('status', [EngagementMeetingStatus::Done, EngagementMeetingStatus::Cancelled])->orderByDesc('scheduled_at')->limit(10)->get(),
            'formatos' => EngagementMeetingFormat::options(),
        ]);
    }
}
