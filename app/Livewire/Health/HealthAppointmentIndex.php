<?php

namespace App\Livewire\Health;

use App\Livewire\Concerns\RequiresActiveProfile;
use App\Livewire\Concerns\RequiresPersonalHealth;
use App\Models\HealthAppointment;
use App\Models\ProfileMember;
use App\Services\HealthAppointmentService;
use App\Support\ProfileContext;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
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
        $this->reset(
            'editingId', 'memberId', 'kind', 'title', 'professionalName', 'specialty', 'location', 'address', 'phone',
            'bookedByName', 'bookedWithName', 'scheduledDate', 'scheduledTime', 'notes',
        );
        $this->kind = 'consultation';
        $this->resetErrorBag();
    }
}
