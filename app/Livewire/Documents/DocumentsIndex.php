<?php

namespace App\Livewire\Documents;

use App\Enums\DocumentType;
use App\Enums\ProcessingStatus;
use App\Jobs\ProcessDocumentJob;
use App\Models\DocumentUpload;
use App\Services\Extraction\DocumentCommitService;
use App\Support\ProfileContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Tela 8 — Importar PDF.
 *
 * O fluxo tem três passos e o do meio é o que importa: upload →
 * **revisão** → confirmação. Nenhum lançamento nasce sem alguém olhar.
 */
#[Layout('components.layouts.app')]
class DocumentsIndex extends Component
{
    use WithFileUploads;

    public $arquivo;

    public string $documentType = 'bank_statement';

    /** Documento aberto para revisão. */
    public ?string $revisandoId = null;

    /** Índices dos itens marcados para importar. */
    public array $aceitos = [];

    public function mount(): void
    {
        abort_if(app(ProfileContext::class)->profile() === null, 404);
    }

    public function rules(): array
    {
        return [
            'arquivo' => [
                'required',
                'file',
                'mimes:pdf',
                'max:'.(config('cerne.ai.max_upload_mb') * 1024),
            ],
            'documentType' => ['required', 'string'],
        ];
    }

    public function upload(): void
    {
        $this->validate();

        $context = app(ProfileContext::class);

        // Disco privado: extrato bancário não pode ficar em pasta pública.
        $caminho = $this->arquivo->store(
            config('cerne.documents.path').'/'.$context->profileId(),
            config('cerne.documents.disk'),
        );

        $documento = DocumentUpload::create([
            'uploaded_by_user_id' => auth()->id(),
            'member_id' => $context->memberId(),
            'document_type' => $this->documentType,
            'original_filename' => $this->arquivo->getClientOriginalName(),
            'storage_path' => $caminho,
            'size_bytes' => $this->arquivo->getSize(),
            'processing_status' => ProcessingStatus::Pending,
        ]);

        ProcessDocumentJob::dispatch($documento->id);

        $this->reset('arquivo');
        session()->flash('status', 'Documento enviado. A leitura acontece em segundo plano.');
    }

    public function revisar(string $id): void
    {
        $documento = DocumentUpload::findOrFail($id);

        $this->revisandoId = $id;
        // Todos marcados por padrão: o usuário desmarca o que estiver
        // errado, que é mais rápido do que marcar dezenas de linhas certas.
        $this->aceitos = array_keys($documento->extractedItems());
    }

    public function fecharRevisao(): void
    {
        $this->reset('revisandoId', 'aceitos');
    }

    public function confirmar(DocumentCommitService $commit): void
    {
        $documento = DocumentUpload::findOrFail($this->revisandoId);

        try {
            $criados = $commit->commit($documento, array_map('intval', $this->aceitos), auth()->id());
            session()->flash('status', "{$criados} lançamentos importados.");
            $this->fecharRevisao();
        } catch (\Throwable $e) {
            $this->addError('confirmar', $e->getMessage());
        }
    }

    public function descartar(string $id): void
    {
        $documento = DocumentUpload::findOrFail($id);
        $documento->deleteFile();
        $documento->delete();

        if ($this->revisandoId === $id) {
            $this->fecharRevisao();
        }

        session()->flash('status', 'Documento descartado.');
    }

    /** @return Collection<int, DocumentUpload> */
    public function getDocumentsProperty(): Collection
    {
        return DocumentUpload::query()
            ->with('uploadedBy', 'member')
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();
    }

    public function getRevisandoProperty(): ?DocumentUpload
    {
        return $this->revisandoId === null
            ? null
            : DocumentUpload::find($this->revisandoId);
    }

    public function render()
    {
        return view('livewire.documents.documents-index', [
            'documents' => $this->documents,
            'revisando' => $this->revisando,
            'tipos' => DocumentType::options(),
            'iaConfigurada' => filled(config('cerne.ai.api_key')),
        ]);
    }
}
