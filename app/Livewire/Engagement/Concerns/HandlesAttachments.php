<?php

namespace App\Livewire\Engagement\Concerns;

use App\Models\EngagementAttachment;
use App\Models\FinancialProfile;
use App\Services\EngagementAccess;
use App\Services\EngagementService;
use App\Support\Engagement\EngagementDocuments;
use App\Support\Engagement\EngagementViewer;
use Illuminate\Support\Collection;

/**
 * Anexos da área de Documentos nas telas do acompanhamento (reunião e tarefa). O componente que usa a trait inclui o modal
 * `livewire.engagement.partials.attach-modal` e chama `attachmentsFor()` para listar os anexos.
 */
trait HandlesAttachments
{
    public bool $showAttach = false;

    public string $attachType = '';

    public string $attachSubjectId = '';

    public string $attachDocumentId = '';

    public function openAttach(string $tipo, string $assuntoId): void
    {
        app(EngagementAccess::class)->viewer() ?? abort(404);

        $this->resetValidation();
        $this->attachType = $tipo;
        $this->attachSubjectId = $assuntoId;
        $this->attachDocumentId = '';
        $this->showAttach = true;
    }

    public function attachDocument(): void
    {
        $visitante = app(EngagementAccess::class)->viewer() ?? abort(404);

        $this->validate(['attachDocumentId' => ['required', 'string']], [], ['attachDocumentId' => 'documento']);

        app(EngagementService::class)->attachDocument($visitante, $this->attachType, $this->attachSubjectId, $this->attachDocumentId);

        $this->showAttach = false;
    }

    public function detachDocument(string $anexoId): void
    {
        $visitante = app(EngagementAccess::class)->viewer() ?? abort(404);

        app(EngagementService::class)->detachDocument($visitante, EngagementAttachment::query()->findOrFail($anexoId));
    }

    /**
     * Anexos de um tipo de assunto, por id do assunto. Só volta o anexo cujo documento o visitante pode abrir.
     *
     * @param  list<string>  $ids
     * @return Collection<string, Collection<int, EngagementAttachment>>
     */
    protected function attachmentsFor(string $tipo, array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return EngagementAttachment::query()
            ->where('subject_type', $tipo)->whereIn('subject_id', $ids)
            ->with('document')->oldest()->get()
            ->filter(fn (EngagementAttachment $a) => $a->document !== null)
            ->groupBy('subject_id');
    }

    /** @return Collection<int, \App\Models\Document> documentos que podem ser anexados (só calculado com o modal aberto) */
    protected function shareableDocuments(EngagementViewer $visitante): Collection
    {
        return $this->showAttach
            ? EngagementDocuments::shareable(FinancialProfile::query()->findOrFail($visitante->profileId))
            : collect();
    }
}
