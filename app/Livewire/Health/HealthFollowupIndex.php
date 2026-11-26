<?php

namespace App\Livewire\Health;

use App\Enums\HealthFollowupStatus;
use App\Livewire\Concerns\RequiresActiveProfile;
use App\Livewire\Concerns\RequiresPersonalHealth;
use App\Models\HealthAppointment;
use App\Models\HealthFollowup;
use App\Models\ProfileMember;
use App\Services\HealthFollowupService;
use App\Support\Forms\FormConsequence;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Acompanhamentos: os problemas de saúde em andamento e os resolvidos. Mesma visibilidade da Agenda de Saúde. */
#[Layout('components.layouts.app')]
class HealthFollowupIndex extends Component
{
    use RequiresActiveProfile, RequiresPersonalHealth;

    /** Veio de um compromisso da Agenda ("abrir acompanhamento a partir desta consulta"). */
    #[Url(as: 'consulta')]
    public string $fromAppointmentId = '';

    public bool $showForm = false;
    public string $memberId = '';
    public string $title = '';
    public string $status = 'investigating';
    public string $startedOn = '';
    public string $appointmentId = '';

    public function mount(): void
    {
        $this->redirectOrAbortWithoutProfile();
        $this->abortUnlessHealthParticipant();

        if ($this->fromAppointmentId !== '') {
            $origem = HealthAppointment::query()->find($this->fromAppointmentId);

            if ($origem !== null) {
                $this->newFollowup();
                $this->memberId = $origem->member_id;
                $this->appointmentId = $origem->id;
                $this->startedOn = min($origem->scheduled_at, now())->toDateString();
            }
        }
    }

    public function newFollowup(): void
    {
        $this->reset('title', 'status', 'appointmentId');
        $this->resetErrorBag();
        $this->memberId = (string) $this->membros()->first()?->id;
        $this->startedOn = CarbonImmutable::today()->toDateString();
        $this->showForm = true;
    }

    public function updatedMemberId(): void
    {
        $this->appointmentId = '';
    }

    public function save(HealthFollowupService $service): void
    {
        $this->validate([
            'memberId' => ['required', 'string'],
            'title' => ['required', 'string', 'max:120'],
            'status' => ['required', Rule::enum(HealthFollowupStatus::class)],
            'startedOn' => ['required', 'date', 'before_or_equal:today', 'after:1900-01-01'],
            'appointmentId' => ['nullable', 'string'],
        ], attributes: ['memberId' => 'pessoa', 'title' => 'nome', 'status' => 'situação', 'startedOn' => 'desde quando', 'appointmentId' => 'consulta de origem']);

        $membro = ProfileMember::query()->visibleHere()->findOrFail($this->memberId);
        $origem = $this->appointmentId !== '' ? HealthAppointment::query()->where('member_id', $membro->id)->findOrFail($this->appointmentId) : null;

        $criado = $service->create($membro, [
            'title' => $this->title, 'status' => $this->status, 'started_on' => $this->startedOn, 'appointment_id' => $origem?->id,
        ], app(ProfileContext::class)->member());

        session()->flash('status', 'Acompanhamento criado.');
        $this->redirect(route('health.followups.show', $criado->id), navigate: true);
    }

    public function render()
    {
        $todos = HealthFollowup::query()->with(['member', 'createdByUser'])->orderByDesc('started_on')->get();

        return view('livewire.health.health-followup-index', [
            'ativos' => $todos->reject->isResolved()->values(),
            'resolvidos' => $todos->filter->isResolved()->values(),
            'membros' => $this->membros(),
            'situacoes' => HealthFollowupStatus::cases(),
            'origens' => $this->showForm && $this->memberId !== ''
                ? HealthAppointment::query()->where('member_id', $this->memberId)->where('scheduled_at', '>=', now()->subYears(2))->orderByDesc('scheduled_at')->limit(40)->get()
                : collect(),
            'consequencia' => $this->showForm ? FormConsequence::healthFollowup($this->appointmentId !== '') : [],
        ]);
    }

    private function membros(): Collection
    {
        return ProfileMember::query()->visibleHere()->orderBy('role')->get();
    }
}
