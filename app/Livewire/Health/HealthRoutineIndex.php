<?php

namespace App\Livewire\Health;

use App\Livewire\Concerns\RequiresActiveProfile;
use App\Livewire\Concerns\RequiresPersonalHealth;
use App\Models\HealthAppointment;
use App\Models\HealthRoutine;
use App\Models\ProfileMember;
use App\Services\HealthRoutineService;
use App\Support\Forms\FormConsequence;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Check-ups e rotinas preventivas: o que repetir, se está em dia e o histórico por ciclo. Mesma visibilidade da Agenda de Saúde (o casal e quem
 * acompanha a pessoa; consultor, nunca).
 */
#[Layout('components.layouts.app')]
class HealthRoutineIndex extends Component
{
    use RequiresActiveProfile, RequiresPersonalHealth;

    #[Url(as: 'rotina')]
    public string $selectedId = '';

    public bool $showForm = false;
    public ?string $editingId = null;
    public string $memberId = '';
    public string $name = '';
    public string $interval = '12';
    public string $lastDoneOn = '';

    public string $linkAppointmentId = '';
    public ?string $confirmingDeleteId = null;

    public function mount(): void
    {
        $this->redirectOrAbortWithoutProfile();
        $this->abortUnlessHealthParticipant();
    }

    public function select(string $id): void
    {
        $this->selectedId = HealthRoutine::query()->findOrFail($id)->id;
        $this->resetPanels();
    }

    public function newRoutine(): void
    {
        $this->resetPanels();
        $this->reset('editingId', 'name', 'interval', 'lastDoneOn');
        $this->memberId = (string) $this->membros()->first()?->id;
        $this->showForm = true;
    }

    public function editRoutine(string $id): void
    {
        $rotina = HealthRoutine::query()->findOrFail($id);

        $this->resetPanels();
        $this->editingId = $rotina->id;
        $this->memberId = $rotina->member_id;
        $this->name = $rotina->name;
        $this->interval = (string) $rotina->interval_months;
        $this->lastDoneOn = $rotina->last_done_on?->toDateString() ?? '';
        $this->showForm = true;
    }

    public function save(HealthRoutineService $service): void
    {
        $dados = $this->validate([
            'memberId' => ['required', 'string'],
            'name' => ['required', 'string', 'max:120'],
            'interval' => ['required', Rule::in(array_map('strval', array_keys(HealthRoutine::INTERVALS)))],
            'lastDoneOn' => ['nullable', 'date', 'before_or_equal:today', 'after:2000-01-01'],
        ], attributes: ['memberId' => 'pessoa', 'name' => 'nome', 'interval' => 'repetição', 'lastDoneOn' => 'última vez']);

        $campos = ['name' => $dados['name'], 'interval_months' => (int) $dados['interval'], 'last_done_on' => $dados['lastDoneOn'] ?: null];

        $rotina = $this->editingId !== null
            ? $service->update(HealthRoutine::query()->findOrFail($this->editingId), $campos)
            : $service->create($this->membroOuFalha($dados['memberId']), $campos, $this->autor());

        $this->selectedId = $rotina->id;
        $this->showForm = false;
        session()->flash('status', $this->editingId !== null ? 'Rotina atualizada.' : 'Rotina criada.');
    }

    public function delete(string $id, HealthRoutineService $service): void
    {
        $service->delete(HealthRoutine::query()->findOrFail($id));
        $this->selectedId = '';
        $this->confirmingDeleteId = null;
        session()->flash('status', 'Rotina excluída. As consultas e os exames continuam na Agenda.');
    }

    public function linkAppointment(HealthRoutineService $service): void
    {
        $rotina = $this->selected();

        if ($rotina === null || $this->linkAppointmentId === '') {
            $this->addError('linkAppointmentId', 'Escolha a consulta ou o exame.');

            return;
        }

        $this->resetErrorBag();
        $service->link($rotina, HealthAppointment::query()->where('member_id', $rotina->member_id)->findOrFail($this->linkAppointmentId));
        $this->linkAppointmentId = '';
    }

    public function unlinkAppointment(string $appointmentId, HealthRoutineService $service): void
    {
        $service->unlink(HealthAppointment::query()->findOrFail($appointmentId));
    }

    public function render(HealthRoutineService $service)
    {
        $rotinas = HealthRoutine::query()->with(['member', 'createdByUser'])->orderByRaw('next_due_on IS NULL DESC')->orderBy('next_due_on')->get();
        $escolhida = $rotinas->firstWhere('id', $this->selectedId) ?? $rotinas->first();

        $consultas = $escolhida !== null ? $escolhida->appointments()->orderByDesc('scheduled_at')->get() : collect();
        $pedidos = $escolhida !== null ? $escolhida->requests()->open()->with('items.appointment')->orderBy('requested_on')->get() : collect();

        return view('livewire.health.health-routine-index', [
            'rotinas' => $rotinas,
            'escolhida' => $escolhida,
            'situacoes' => $rotinas->mapWithKeys(fn (HealthRoutine $r) => [$r->id => $service->status($r)])->all(),
            'ultimaVez' => $escolhida !== null ? $service->lastDone($escolhida) : null,
            'ciclos' => $escolhida !== null ? $service->cycles($escolhida, $consultas, $pedidos) : [],
            'consultas' => $consultas,
            'pedidos' => $pedidos,
            // Os que já não esperam data (os que esperam aparecem acima, em destaque).
            'outrosPedidos' => $escolhida !== null ? $escolhida->requests()->whereNotIn('id', $pedidos->pluck('id'))->with('items')->orderByDesc('requested_on')->get() : collect(),
            'ligaveis' => $escolhida !== null
                ? HealthAppointment::query()->where('member_id', $escolhida->member_id)->whereNull('routine_id')->where('scheduled_at', '>=', now()->subYears(3))->orderByDesc('scheduled_at')->limit(60)->get()
                : collect(),
            'membros' => $this->membros(),
            'consequencia' => $this->showForm ? FormConsequence::healthRoutine((int) $this->interval, $this->parsedDate($this->lastDoneOn)) : [],
        ]);
    }

    private function selected(): ?HealthRoutine
    {
        return $this->selectedId !== ''
            ? HealthRoutine::query()->find($this->selectedId)
            : HealthRoutine::query()->orderByRaw('next_due_on IS NULL DESC')->orderBy('next_due_on')->first();
    }

    private function parsedDate(string $texto): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $texto) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function resetPanels(): void
    {
        $this->showForm = false;
        $this->confirmingDeleteId = null;
        $this->resetErrorBag();
    }

    private function membros(): Collection
    {
        return ProfileMember::query()->visibleHere()->orderBy('role')->get();
    }

    private function membroOuFalha(string $memberId): ProfileMember
    {
        return ProfileMember::query()->visibleHere()->findOrFail($memberId);
    }

    private function autor(): ?ProfileMember
    {
        return app(ProfileContext::class)->member();
    }
}
