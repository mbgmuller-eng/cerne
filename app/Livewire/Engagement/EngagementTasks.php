<?php

namespace App\Livewire\Engagement;

use App\Enums\EngagementMeetingStatus;
use App\Enums\EngagementTaskOwner;
use App\Enums\EngagementTaskStatus;
use App\Models\EngagementMeeting;
use App\Models\EngagementTask;
use App\Services\EngagementAccess;
use App\Services\EngagementService;
use App\Support\Engagement\EngagementViewer;
use Carbon\CarbonImmutable;
use Livewire\Component;

/**
 * Tarefas do acompanhamento: os dois lados criam, cada tarefa tem dono (cliente ou consultor), prazo e situação.
 */
class EngagementTasks extends Component
{
    public bool $showForm = false;

    public ?string $editingId = null;

    public string $title = '';

    public string $description = '';

    public string $owner = 'client';

    public string $dueDate = '';

    public string $meetingId = '';

    /** 'todas' | 'cliente' | 'consultor' */
    public string $filtro = 'todas';

    private function visitante(): EngagementViewer
    {
        return app(EngagementAccess::class)->viewer() ?? abort(404);
    }

    public function openForm(?string $id = null): void
    {
        $this->visitante();
        $this->resetValidation();
        $this->editingId = $id;

        if ($id !== null) {
            $tarefa = EngagementTask::query()->findOrFail($id);
            $this->title = $tarefa->title;
            $this->description = (string) $tarefa->description;
            $this->owner = $tarefa->owner->value;
            $this->dueDate = $tarefa->due_date?->format('Y-m-d') ?? '';
            $this->meetingId = (string) $tarefa->meeting_id;
        } else {
            $this->reset('title', 'description', 'dueDate', 'meetingId');
            $this->owner = 'client';
        }

        $this->showForm = true;
    }

    public function save(): void
    {
        $visitante = $this->visitante();

        $dados = $this->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'owner' => ['required', EngagementTaskOwner::rule()],
            'dueDate' => ['nullable', 'date_format:Y-m-d'],
            'meetingId' => ['nullable', 'string'],
        ], [], ['title' => 'título', 'description' => 'descrição', 'owner' => 'responsável', 'dueDate' => 'prazo']);

        $tarefa = $this->editingId !== null ? EngagementTask::query()->findOrFail($this->editingId) : null;

        app(EngagementService::class)->saveTask($visitante, $tarefa, [
            'title' => $dados['title'],
            'description' => $dados['description'] ?: null,
            'owner' => $dados['owner'],
            'due_date' => $dados['dueDate'] ? CarbonImmutable::createFromFormat('Y-m-d', $dados['dueDate'])->startOfDay() : null,
            'meeting_id' => $dados['meetingId'] ?: null,
        ]);

        $this->showForm = false;
        $this->editingId = null;
    }

    public function setStatus(string $id, string $situacao): void
    {
        $visitante = $this->visitante();

        app(EngagementService::class)->setTaskStatus($visitante, EngagementTask::query()->findOrFail($id), EngagementTaskStatus::from($situacao));
    }

    public function delete(string $id): void
    {
        $visitante = $this->visitante();

        app(EngagementService::class)->deleteTask($visitante, EngagementTask::query()->findOrFail($id));
    }

    public function render()
    {
        $visitante = $this->visitante();

        $todas = EngagementTask::query()->orderByRaw('due_date is null')->orderBy('due_date')->orderBy('created_at')->get();
        $abertas = $todas->filter(fn (EngagementTask $t) => $t->isOpen());

        $abertas = match ($this->filtro) {
            'cliente' => $abertas->where('owner', EngagementTaskOwner::Client),
            'consultor' => $abertas->where('owner', EngagementTaskOwner::Consultant),
            default => $abertas,
        };

        return view('livewire.engagement.engagement-tasks', [
            'visitante' => $visitante,
            'abertas' => $abertas->values(),
            'concluidas' => $todas->filter(fn (EngagementTask $t) => ! $t->isOpen())->sortByDesc('completed_at')->take(8)->values(),
            'situacoes' => EngagementTaskStatus::options(),
            'donos' => EngagementTaskOwner::options(),
            'reunioes' => EngagementMeeting::query()->where('status', EngagementMeetingStatus::Scheduled)->orderBy('scheduled_at')->get(),
        ]);
    }
}
