<?php

namespace App\Livewire\Health;

use App\Enums\HealthConditionStatus;
use App\Livewire\Concerns\RequiresActiveProfile;
use App\Livewire\Concerns\RequiresPersonalHealth;
use App\Support\Forms\FormConsequence;
use App\Support\Health\HealthOrigins;
use Illuminate\Validation\Rule;
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
    use \App\Livewire\Health\Concerns\PicksHealthProfessional, RequiresActiveProfile, RequiresPersonalHealth;

    /** @var array<string, string> tipo sanguíneo em edição, por member_id */
    public array $bloodTypeDrafts = [];

    /** @var array<string, string> */
    public array $newAllergyText = [];

    /** O formulário de problema aberto: de quem é e, na edição, qual. */
    public ?string $conditionMemberId = null;

    public ?string $editingConditionId = null;

    public string $condText = '';

    public string $condStatus = 'active';

    public string $condSince = '';

    public string $condOrigin = 'past';

    public bool $condCare = false;

    /** Veio de uma consulta ou de um exame para registrar uma limitação: o painel da pessoa abre o formulário. */
    public ?string $limitationFor = null;

    public ?string $limitationFrom = null;

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
        $this->abortUnlessHealthParticipant();

        foreach ($this->membros() as $membro) {
            $this->bloodTypeDrafts[$membro->id] = app(HealthCardService::class)->cardFor($membro)->blood_type ?? '';
        }

        $this->openFromLink();
    }

    /**
     * "Adicionar à ficha" de uma consulta, de um exame ou de um acompanhamento: abre o formulário já com a origem. Nada é gravado sem a
     * pessoa salvar, e um exame nunca vira problema de saúde sozinho.
     */
    private function openFromLink(): void
    {
        $novo = request()->query('novo');
        $pessoa = request()->query('pessoa');
        $origem = request()->query('origem');

        if (! is_string($novo) || ! is_string($pessoa) || ! in_array($novo, ['problema', 'limitacao'], true)) {
            return;
        }

        $membro = ProfileMember::query()->visibleHere()->find($pessoa);

        if ($membro === null || ! app(ProfileContext::class)->canWriteAsCaregiver($membro->id)) {
            return;
        }

        $origem = is_string($origem) && array_key_exists($origem, HealthOrigins::options($membro->id)) ? $origem : HealthOrigins::PAST;

        if ($novo === 'limitacao') {
            [$this->limitationFor, $this->limitationFrom] = [$membro->id, $origem];

            return;
        }

        $this->newCondition($membro->id);
        $this->condOrigin = $origem;
    }

    public function newCondition(string $memberId): void
    {
        $this->resetErrorBag();
        $this->reset('editingConditionId', 'condText', 'condStatus', 'condSince', 'condOrigin', 'condCare');
        $this->conditionMemberId = $this->membroOuFalha($memberId)->id;
    }

    public function editCondition(string $conditionId): void
    {
        $c = HealthCondition::query()->findOrFail($conditionId);
        $this->newCondition($c->member_id);
        $this->editingConditionId = $c->id;
        $this->condText = (string) $c->description;
        $this->condStatus = $c->status->value;
        $this->condSince = (string) $c->since_year;
        $this->condOrigin = HealthOrigins::valueOf($c);
        $this->condCare = $c->exercise_care;
    }

    public function closeCondition(): void
    {
        $this->reset('conditionMemberId', 'editingConditionId');
        $this->resetErrorBag();
    }

    public function saveCondition(HealthCardService $service): void
    {
        $this->validate([
            'condText' => ['required', 'string', 'max:160'],
            'condStatus' => ['required', Rule::in(HealthConditionStatus::values())],
            'condSince' => ['nullable', 'integer', 'between:1900,'.now()->year],
            'condOrigin' => ['required', 'string', 'max:60'],
        ], attributes: ['condText' => 'problema', 'condStatus' => 'situação', 'condSince' => 'ano', 'condOrigin' => 'origem']);

        $membro = $this->membroOuFalha($this->conditionMemberId);
        $dados = [
            'status' => $this->condStatus,
            'since_year' => $this->condSince !== '' ? (int) $this->condSince : null,
            'exercise_care' => $this->condCare,
        ] + HealthOrigins::columns($this->condOrigin, $membro->id);

        if ($this->editingConditionId !== null) {
            $service->updateCondition(
                HealthCondition::query()->where('member_id', $membro->id)->findOrFail($this->editingConditionId),
                ['description' => trim($this->condText)] + $dados,
            );
        } else {
            $service->addCondition($membro, $this->condText, $this->autor(), $dados);
        }

        $this->closeCondition();
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

    public function removeCondition(string $conditionId, HealthCardService $service): void
    {
        $service->removeCondition(HealthCondition::query()->findOrFail($conditionId));
    }

    public function regenerateEmergencyToken(string $memberId, HealthCardService $service): void
    {
        // Trocar o QR invalida o que está impresso: é decisão do dono, não de quem cuida.
        abort_if(app(ProfileContext::class)->isCaregiver(), 403);

        $service->regenerateEmergencyToken($this->membroOuFalha($memberId), $this->autor());
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
        $this->resetProfessional($medicamento->professional_id, false);
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
            $medicamento = HealthMedication::query()->findOrFail($this->editingMedicationId);
            $dados['is_active'] = $this->medActive;
            $dados['professional_id'] = $this->resolveProfessional($medicamento->member_id, $this->medPrescriber);
            $service->updateMedication($medicamento, $dados, $this->autor());
        } else {
            $membro = $this->membroOuFalha($this->medicationMemberId);
            $dados['started_on'] = $this->medStartedOn !== '' ? $this->medStartedOn : null;
            $dados['professional_id'] = $this->resolveProfessional($membro->id, $this->medPrescriber);
            $service->addMedication($membro, $dados, $this->autor());
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
                // ensureEmergencyToken, não cardFor: o link de compartilhar
                // (abaixo, na view) precisa do token pronto na hora de
                // montar a URL — não só quando alguém pedir a imagem do QR.
                'card' => $service->ensureEmergencyToken($membro),
                'allergies' => HealthAllergy::query()->where('member_id', $membro->id)->with('createdByUser')->orderBy('created_at')->get(),
                'conditions' => HealthCondition::query()->where('member_id', $membro->id)->with('createdByUser')->orderBy('created_at')->get(),
                'medications' => HealthMedication::query()->where('member_id', $membro->id)
                    ->with(['changes.changedBy', 'changes.changedByUser'])
                    ->orderByDesc('is_active')->get()->sortBy(fn (HealthMedication $m) => mb_strtolower((string) $m->name))->sortByDesc('is_active')->values(),
            ]];
        });

        return view('livewire.health.health-card-index', [
            'campoProfissional' => $this->showMedicationForm ? $this->professionalField((string) $this->medicationMemberId, $this->medPrescriber) : null,
            'fichas' => $fichas,
            'situacoes' => HealthConditionStatus::options(),
            'origens' => $this->conditionMemberId !== null ? HealthOrigins::options($this->conditionMemberId) : [],
            'consequenciaProblema' => $this->conditionMemberId !== null ? FormConsequence::healthCondition($this->condCare) : [],
            'editingExisting' => $this->editingMedicationId !== null,
            'emPessoaAcompanhada' => app(ProfileContext::class)->isCaregiver() || app(ProfileContext::class)->profile()?->isCare(),
        ]);
    }

    /** Titular primeiro, depois cônjuge — 'primary' vem antes de 'secondary' alfabeticamente. */
    private function membros(): Collection
    {
        return ProfileMember::query()
            ->visibleHere()
            ->orderBy('role')
            ->get();
    }

    private function membroOuFalha(?string $memberId): ProfileMember
    {
        // findOrFail (não a coleção já carregada): id de outro perfil vira
        // ModelNotFoundException, igual ao resto do app — nunca edita.
        return ProfileMember::query()
            ->visibleHere()
            ->findOrFail($memberId);
    }

    private function autor(): ?ProfileMember
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
        $this->resetProfessional();
        $this->resetErrorBag();
    }

    protected function professionalPersonId(): string
    {
        return (string) $this->medicationMemberId;
    }

    protected function applyProfessional(\App\Models\HealthProfessional $profissional): void
    {
        $this->medPrescriber = $profissional->name;
    }
}
