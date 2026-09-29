<?php

namespace App\Livewire\Health;

use App\Livewire\Concerns\RequiresActiveProfile;
use App\Livewire\Concerns\RequiresPersonalHealth;
use App\Models\HealthAllergy;
use App\Models\HealthCondition;
use App\Models\HealthMedication;
use App\Models\ProfileMember;
use App\Services\HealthCardService;
use App\Support\ProfileContext;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Ficha de saúde do casal — tipo sanguíneo, alergia, doença e remédio
 * (com linha do tempo de alteração), visível aos dois membros do perfil.
 *
 * `RequiresPersonalHealth` aqui bloqueia só quem NÃO é um membro de
 * verdade (consultor, corretor, contexto sem membro) — o próprio nome da
 * trait é da Academia, mas a checagem ("só um membro passa") é a mesma
 * que esta tela precisa; ver o trait pra detalhe.
 */
#[Layout('components.layouts.app')]
class HealthCardIndex extends Component
{
    use RequiresActiveProfile, RequiresPersonalHealth;

    /** @var array<string, string> tipo sanguíneo em edição, por member_id */
    public array $bloodTypeDrafts = [];

    /** @var array<string, string> */
    public array $newAllergyText = [];

    /** @var array<string, string> */
    public array $newConditionText = [];

    public bool $showMedicationForm = false;
    public ?string $medicationMemberId = null;
    public ?string $editingMedicationId = null;
    public string $medName = '';
    public string $medDose = '';
    public string $medSchedule = '';
    public string $medReason = '';
    public string $medPrescriber = '';
    public string $medStartedOn = '';
    public bool $medActive = true;

    /** @var array<string, string> nota nova por medication_id */
    public array $medNoteText = [];

    public function mount(): void
    {
        $this->redirectOrAbortWithoutProfile();
        $this->abortUnlessPersonalHealthOwner();

        foreach ($this->membros() as $membro) {
            $this->bloodTypeDrafts[$membro->id] = app(HealthCardService::class)->cardFor($membro)->blood_type ?? '';
        }
    }

    public function updatedBloodTypeDrafts(string $valor, string $memberId): void
    {
        $membro = $this->membroOuFalha($memberId);
        app(HealthCardService::class)->setBloodType($membro, $valor, $this->autor());
    }

    public function addAllergy(string $memberId, HealthCardService $service): void
    {
        $texto = trim($this->newAllergyText[$memberId] ?? '');
        if ($texto === '') {
            return;
        }

        $service->addAllergy($this->membroOuFalha($memberId), $texto, $this->autor());
        $this->newAllergyText[$memberId] = '';
    }

    public function removeAllergy(string $allergyId, HealthCardService $service): void
    {
        $service->removeAllergy(HealthAllergy::query()->findOrFail($allergyId));
    }

    public function addCondition(string $memberId, HealthCardService $service): void
    {
        $texto = trim($this->newConditionText[$memberId] ?? '');
        if ($texto === '') {
            return;
        }

        $service->addCondition($this->membroOuFalha($memberId), $texto, $this->autor());
        $this->newConditionText[$memberId] = '';
    }

    public function removeCondition(string $conditionId, HealthCardService $service): void
    {
        $service->removeCondition(HealthCondition::query()->findOrFail($conditionId));
    }

    public function newMedication(string $memberId): void
    {
        $this->resetMedicationForm();
        $this->medicationMemberId = $this->membroOuFalha($memberId)->id;
        $this->showMedicationForm = true;
    }

    public function editMedication(string $medicationId): void
    {
        $medicamento = HealthMedication::query()->findOrFail($medicationId);

        $this->resetMedicationForm();
        $this->editingMedicationId = $medicamento->id;
        $this->medicationMemberId = $medicamento->member_id;
        $this->medName = $medicamento->name;
        $this->medDose = (string) $medicamento->dose;
        $this->medSchedule = (string) $medicamento->schedule;
        $this->medReason = (string) $medicamento->reason;
        $this->medPrescriber = (string) $medicamento->prescriber;
        $this->medStartedOn = $medicamento->started_on?->toDateString() ?? '';
        $this->medActive = $medicamento->is_active;
        $this->showMedicationForm = true;
    }

    public function closeMedicationForm(): void
    {
        $this->showMedicationForm = false;
    }

    public function saveMedication(HealthCardService $service): void
    {
        $this->validate([
            'medName' => ['required', 'string', 'max:120'],
            'medDose' => ['nullable', 'string', 'max:80'],
            'medSchedule' => ['nullable', 'string', 'max:120'],
            'medReason' => ['nullable', 'string', 'max:160'],
            'medPrescriber' => ['nullable', 'string', 'max:120'],
            'medStartedOn' => ['nullable', 'date'],
        ], attributes: [
            'medName' => 'nome', 'medDose' => 'dose', 'medSchedule' => 'horário',
            'medReason' => 'motivo', 'medPrescriber' => 'médico', 'medStartedOn' => 'início',
        ]);

        $dados = [
            'name' => $this->medName,
            'dose' => $this->medDose,
            'schedule' => $this->medSchedule,
            'reason' => $this->medReason,
            'prescriber' => $this->medPrescriber,
        ];

        if ($this->editingMedicationId !== null) {
            $dados['is_active'] = $this->medActive;
            $service->updateMedication(HealthMedication::query()->findOrFail($this->editingMedicationId), $dados, $this->autor());
        } else {
            $dados['started_on'] = $this->medStartedOn !== '' ? $this->medStartedOn : null;
            $service->addMedication($this->membroOuFalha($this->medicationMemberId), $dados, $this->autor());
        }

        $this->showMedicationForm = false;
    }

    public function addMedicationNote(string $medicationId, HealthCardService $service): void
    {
        $texto = trim($this->medNoteText[$medicationId] ?? '');
        if ($texto === '') {
            return;
        }

        $service->addMedicationNote(HealthMedication::query()->findOrFail($medicationId), $texto, $this->autor());
        $this->medNoteText[$medicationId] = '';
    }

    public function render(HealthCardService $service)
    {
        $membros = $this->membros();

        $fichas = $membros->mapWithKeys(function (ProfileMember $membro) use ($service) {
            return [$membro->id => [
                'membro' => $membro,
                'card' => $service->cardFor($membro),
                'allergies' => HealthAllergy::query()->where('member_id', $membro->id)->orderBy('created_at')->get(),
                'conditions' => HealthCondition::query()->where('member_id', $membro->id)->orderBy('created_at')->get(),
                'medications' => HealthMedication::query()->where('member_id', $membro->id)
                    ->with('changes.changedBy')
                    ->orderByDesc('is_active')->orderBy('name')->get(),
            ]];
        });

        return view('livewire.health.health-card-index', [
            'fichas' => $fichas,
            'editingExisting' => $this->editingMedicationId !== null,
        ]);
    }

    /** Titular primeiro, depois cônjuge — 'primary' vem antes de 'secondary' alfabeticamente. */
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
        // findOrFail (não a coleção já carregada): id de outro perfil vira
        // ModelNotFoundException, igual ao resto do app — nunca edita.
        return ProfileMember::query()
            ->where('profile_id', app(ProfileContext::class)->profileId())
            ->where('is_active', true)
            ->findOrFail($memberId);
    }

    private function autor(): ProfileMember
    {
        return app(ProfileContext::class)->member();
    }

    private function resetMedicationForm(): void
    {
        $this->reset(
            'editingMedicationId', 'medicationMemberId', 'medName', 'medDose',
            'medSchedule', 'medReason', 'medPrescriber', 'medStartedOn', 'medActive',
        );
        $this->medActive = true;
        $this->resetErrorBag();
    }
}
