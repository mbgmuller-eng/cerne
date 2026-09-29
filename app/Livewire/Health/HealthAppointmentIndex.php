<?php

namespace App\Livewire\Health;

use App\Livewire\Concerns\RequiresActiveProfile;
use App\Livewire\Concerns\RequiresPersonalHealth;
use App\Models\HealthAppointment;
use App\Models\ProfileMember;
use App\Services\HealthAppointmentService;
use App\Support\ProfileContext;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Agenda de consulta/exame do casal — mesma visibilidade da Ficha de
 * Saúde (CoupleHealthScope): os dois veem tudo, consultor não vê nada.
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
    public string $location = '';
    public string $scheduledDate = '';
    public string $scheduledTime = '';
    public string $notes = '';

    public function mount(): void
    {
        $this->redirectOrAbortWithoutProfile();
        $this->abortUnlessPersonalHealthOwner();
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
        $this->location = (string) $consulta->location;
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
            'title' => ['required', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:160'],
            'scheduledDate' => ['required', 'date'],
            'scheduledTime' => ['required', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], attributes: [
            'memberId' => 'pessoa', 'kind' => 'tipo', 'title' => 'título',
            'location' => 'local', 'scheduledDate' => 'data', 'scheduledTime' => 'hora', 'notes' => 'anotações',
        ]);

        $dados = [
            'kind' => $this->kind,
            'title' => $this->title,
            'location' => $this->location,
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

        return view('livewire.health.health-appointment-index', [
            'membros' => $this->membros(),
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
        $this->reset('editingId', 'memberId', 'kind', 'title', 'location', 'scheduledDate', 'scheduledTime', 'notes');
        $this->kind = 'consultation';
        $this->resetErrorBag();
    }
}
