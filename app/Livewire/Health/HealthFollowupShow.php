<?php

namespace App\Livewire\Health;

use App\Enums\DocumentCategory;
use App\Enums\HealthFollowupStatus;
use App\Livewire\Concerns\RequiresActiveProfile;
use App\Livewire\Concerns\RequiresPersonalHealth;
use App\Models\Document;
use App\Models\HealthAppointment;
use App\Models\HealthFollowup;
use App\Models\HealthFollowupNote;
use App\Models\ProfileMember;
use App\Services\HealthFollowupService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Um acompanhamento: a linha do tempo e o que se acrescenta a ela. O programa de atividades é o componente HealthProgramPanel. */
#[Layout('components.layouts.app')]
class HealthFollowupShow extends Component
{
    use RequiresActiveProfile, RequiresPersonalHealth, WithFileUploads;

    #[Locked]
    public string $followupId = '';

    /** '' | note | document | link | edit */
    public string $panel = '';

    public string $noteDate = '';
    public string $noteText = '';
    public ?int $notePain = null;

    public $arquivo = null;
    public string $documentTitle = '';
    public string $documentAppointmentId = '';
    public string $existingDocumentId = '';

    public string $linkAppointmentId = '';

    public string $title = '';
    public string $startedOn = '';

    public bool $confirmingDelete = false;

    public function mount(string $followup): void
    {
        $this->redirectOrAbortWithoutProfile();
        $this->abortUnlessHealthParticipant();

        $this->followupId = HealthFollowup::query()->findOrFail($followup)->id;
    }

    public function open(string $panel): void
    {
        $this->resetErrorBag();
        $this->panel = $this->panel === $panel ? '' : $panel;

        if ($this->panel === 'note') {
            $this->noteDate = CarbonImmutable::today()->toDateString();
            $this->reset('noteText', 'notePain');
        }

        if ($this->panel === 'document') {
            $this->reset('arquivo', 'documentTitle', 'documentAppointmentId', 'existingDocumentId');
        }

        if ($this->panel === 'edit') {
            $a = $this->followup();
            $this->title = $a->title;
            $this->startedOn = $a->started_on->toDateString();
        }
    }

    public function changeStatus(string $status, HealthFollowupService $service): void
    {
        $service->setStatus($this->followup(), HealthFollowupStatus::from($status));
    }

    public function saveEdit(HealthFollowupService $service): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:120'],
            'startedOn' => ['required', 'date', 'before_or_equal:today', 'after:1900-01-01'],
        ], attributes: ['title' => 'nome', 'startedOn' => 'desde quando']);

        $service->rename($this->followup(), $this->title, $this->startedOn);
        $this->panel = '';
    }

    public function delete(HealthFollowupService $service): void
    {
        $service->delete($this->followup());

        session()->flash('status', 'Acompanhamento excluído. As consultas e os documentos continuam guardados.');
        $this->redirect(route('health.followups.index'), navigate: true);
    }

    public function saveNote(HealthFollowupService $service): void
    {
        $this->validate([
            'noteDate' => ['required', 'date', 'before_or_equal:today', 'after:1900-01-01'],
            'noteText' => ['nullable', 'string', 'max:2000'],
            'notePain' => ['nullable', 'integer', 'between:0,10'],
        ], attributes: ['noteDate' => 'data', 'noteText' => 'anotação', 'notePain' => 'nota de dor']);

        try {
            $service->addNote($this->followup(), $this->noteDate, $this->noteText, $this->notePain, $this->autor());
        } catch (InvalidArgumentException $e) {
            $this->addError('noteText', $e->getMessage());

            return;
        }

        $this->panel = '';
    }

    public function deleteNote(string $noteId): void
    {
        HealthFollowupNote::query()->where('followup_id', $this->followupId)->findOrFail($noteId)->delete();
    }

    public function linkAppointment(HealthFollowupService $service): void
    {
        if ($this->linkAppointmentId === '') {
            $this->addError('linkAppointmentId', 'Escolha a consulta ou o exame.');

            return;
        }

        $acompanhamento = $this->followup();
        $service->attachAppointment($acompanhamento, HealthAppointment::query()->where('member_id', $acompanhamento->member_id)->findOrFail($this->linkAppointmentId));

        $this->linkAppointmentId = '';
        $this->panel = '';
    }

    public function detachAppointment(string $appointmentId, HealthFollowupService $service): void
    {
        $service->detachAppointment($this->followup(), $appointmentId);
    }

    public function uploadDocument(HealthFollowupService $service): void
    {
        $this->validate([
            'arquivo' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.config('cerne.document_vault.max_kb')],
            'documentTitle' => ['required', 'string', 'max:120'],
            'documentAppointmentId' => ['nullable', 'string'],
        ], attributes: ['arquivo' => 'arquivo', 'documentTitle' => 'título', 'documentAppointmentId' => 'compromisso']);

        $acompanhamento = $this->followup();
        $consulta = $this->documentAppointmentId !== '' ? $acompanhamento->appointments()->findOrFail($this->documentAppointmentId) : null;

        $service->uploadDocument($acompanhamento, $this->arquivo, $this->documentTitle, $consulta, $this->autor());

        $this->reset('arquivo', 'documentTitle', 'documentAppointmentId');
        $this->panel = '';
        session()->flash('status', 'Documento guardado nos Documentos e ligado ao acompanhamento.');
    }

    public function linkDocument(HealthFollowupService $service): void
    {
        if ($this->existingDocumentId === '') {
            $this->addError('existingDocumentId', 'Escolha o documento.');

            return;
        }

        $acompanhamento = $this->followup();
        $consulta = $this->documentAppointmentId !== '' ? $acompanhamento->appointments()->findOrFail($this->documentAppointmentId) : null;

        $service->linkDocument($acompanhamento, $this->linkableDocuments($acompanhamento)->findOrFail($this->existingDocumentId), $consulta);

        $this->reset('existingDocumentId', 'documentAppointmentId');
        $this->panel = '';
    }

    public function unlinkDocument(string $documentId, HealthFollowupService $service): void
    {
        $service->unlinkDocument(Document::query()->where('health_followup_id', $this->followupId)->findOrFail($documentId));
    }

    private function linkableDocuments(HealthFollowup $acompanhamento)
    {
        return Document::query()
            ->whereIn('category', [DocumentCategory::HealthExam->value, DocumentCategory::Other->value])
            ->where(fn ($q) => $q->whereNull('member_id')->orWhere('member_id', $acompanhamento->member_id))
            ->whereNull('health_followup_id');
    }

    public function render(HealthFollowupService $service)
    {
        $acompanhamento = $this->followup()->load(['member', 'createdByUser']);
        $consultas = $acompanhamento->appointments()->orderByDesc('scheduled_at')->get();

        return view('livewire.health.health-followup-show', [
            'acompanhamento' => $acompanhamento,
            'linhas' => $service->timeline($acompanhamento),
            'situacoes' => HealthFollowupStatus::cases(),
            // A situação é um <select> que grava na troca: para quem só lê, vira texto.
            'podeEscrever' => app(ProfileContext::class)->canWriteAsCaregiver($acompanhamento->member_id),
            'consultas' => $consultas,
            'ligaveis' => $this->panel === 'link'
                ? HealthAppointment::query()->where('member_id', $acompanhamento->member_id)->whereNotIn('id', $consultas->pluck('id'))->where('scheduled_at', '>=', now()->subYears(5))->orderByDesc('scheduled_at')->limit(80)->get()
                : collect(),
            'documentosLigaveis' => $this->panel === 'document' ? $this->linkableDocuments($acompanhamento)->orderByDesc('created_at')->limit(60)->get() : collect(),
        ]);
    }

    private function followup(): HealthFollowup
    {
        return HealthFollowup::query()->findOrFail($this->followupId);
    }

    private function autor(): ?ProfileMember
    {
        return app(ProfileContext::class)->member();
    }
}
