<?php

namespace App\Livewire\Documents;

use App\Enums\DocumentCategory;
use App\Livewire\Concerns\RequiresActiveProfile;
use App\Models\Document;
use App\Models\DocumentFolder;
use App\Models\InsurancePolicy;
use App\Models\ProfileMember;
use App\Services\DocumentFolderService;
use App\Services\DocumentService;
use DomainException;
use App\Support\ProfileContext;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Área de Documentos — CNH, passaporte, certificado, apólice, exame...
 * Visível ao dono/cônjuge sempre; consultor/corretor só enxergam o que a
 * categoria libera (ver DocumentVisibilityScope) — por isso não existe
 * `RequiresPersonalHealth` aqui como na Ficha de Saúde: esta tela não é
 * bloqueada pro profissional, ela só mostra menos coisa pra ele.
 */
#[Layout('components.layouts.app')]
class DocumentVaultIndex extends Component
{
    use RequiresActiveProfile, WithFileUploads;

    /** Pasta aberta agora (vazio = a raiz). Vai na URL para o botão voltar do navegador funcionar. */
    #[Url(as: 'pasta')]
    public string $pasta = '';

    /** Lista todos os documentos de uma vez, de qualquer pasta (busca por olho). */
    public bool $verTodos = false;

    public bool $showFolderForm = false;
    public ?string $editingFolderId = null;
    public string $folderName = '';
    public string $folderParentId = '';

    public bool $showForm = false;
    public ?string $editingId = null;
    public string $folderId = '';
    public string $category = '';
    public string $title = '';
    public string $memberId = '';
    public string $insurancePolicyId = '';
    public string $expiresOn = '';
    public bool $visibleToProfessional = false;
    public $arquivo = null;

    public function mount(): void
    {
        $this->redirectOrAbortWithoutProfile();
    }

    public function newDocument(): void
    {
        $this->abortUnlessCanManage();
        $this->resetForm();
        // Já nasce na pasta que está aberta.
        $this->folderId = $this->pasta;
        $this->showForm = true;
    }

    public function editDocument(string $documentId): void
    {
        $this->abortUnlessCanManage();
        $documento = Document::query()->findOrFail($documentId);

        $this->resetForm();
        $this->editingId = $documento->id;
        $this->category = $documento->category->value;
        $this->title = $documento->title;
        $this->memberId = (string) $documento->member_id;
        $this->folderId = (string) $documento->folder_id;
        $this->insurancePolicyId = (string) $documento->insurance_policy_id;
        $this->expiresOn = $documento->expires_on?->toDateString() ?? '';
        $this->visibleToProfessional = $documento->visible_to_professional;
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
    }

    public function rules(): array
    {
        return [
            'category' => ['required', DocumentCategory::rule()],
            'title' => ['required', 'string', 'max:120'],
            'memberId' => ['nullable', 'string'],
            'folderId' => ['nullable', 'string', function (string $atributo, mixed $valor, \Closure $falhar): void {
                if ($valor !== '' && $valor !== null && ! DocumentFolder::query()->whereKey($valor)->exists()) {
                    $falhar('A pasta escolhida não existe mais.');
                }
            }],
            'insurancePolicyId' => ['required_if:category,'.DocumentCategory::InsurancePolicy->value],
            'expiresOn' => ['nullable', 'date'],
            'arquivo' => [
                // Só obrigatório ao criar — editar é só metadado, ver DocumentService::update().
                $this->editingId === null ? 'required' : 'nullable',
                'file',
                'mimes:pdf,jpg,jpeg,png',
                'max:'.config('cerne.document_vault.max_kb'),
            ],
        ];
    }

    public function save(DocumentService $service): void
    {
        $this->abortUnlessCanManage();
        $data = $this->validate();

        $dados = [
            'category' => $data['category'],
            'title' => $data['title'],
            'member_id' => $data['memberId'] !== '' ? $data['memberId'] : null,
            'folder_id' => $data['folderId'] !== '' ? $data['folderId'] : null,
            'insurance_policy_id' => $data['insurancePolicyId'] !== '' ? $data['insurancePolicyId'] : null,
            'expires_on' => $data['expiresOn'] !== '' ? $data['expiresOn'] : null,
            'visible_to_professional' => $this->visibleToProfessional,
        ];

        if ($this->editingId !== null) {
            $service->update(Document::query()->findOrFail($this->editingId), $dados);
        } else {
            $service->upload($this->arquivo, $dados, $this->autor());
        }

        $this->showForm = false;
    }

    public function delete(string $documentId, DocumentService $service): void
    {
        $this->abortUnlessCanManage();
        $service->delete(Document::query()->findOrFail($documentId));
    }

    public function abrirPasta(string $id = ''): void
    {
        $this->pasta = $id;
        $this->verTodos = false;
    }

    public function alternarVerTodos(): void
    {
        $this->verTodos = ! $this->verTodos;
    }

    public function newFolder(): void
    {
        $this->abortUnlessCanManage();
        $this->resetFolderForm();
        $this->folderParentId = $this->pasta;
        $this->showFolderForm = true;
    }

    public function editFolder(string $folderId): void
    {
        $this->abortUnlessCanManage();
        $pasta = DocumentFolder::query()->findOrFail($folderId);

        $this->resetFolderForm();
        $this->editingFolderId = $pasta->id;
        $this->folderName = $pasta->name;
        $this->folderParentId = (string) $pasta->parent_id;
        $this->showFolderForm = true;
    }

    public function closeFolderForm(): void
    {
        $this->showFolderForm = false;
    }

    public function saveFolder(DocumentFolderService $service): void
    {
        $this->abortUnlessCanManage();

        $this->validate([
            'folderName' => ['required', 'string', 'max:80'],
            'folderParentId' => ['nullable', 'string'],
        ], attributes: ['folderName' => 'nome da pasta', 'folderParentId' => 'pasta de cima']);

        $pai = $this->folderParentId !== '' ? DocumentFolder::query()->find($this->folderParentId) : null;

        if ($this->folderParentId !== '' && $pai === null) {
            $this->addError('folderParentId', 'A pasta de cima não existe mais.');

            return;
        }

        try {
            if ($this->editingFolderId !== null) {
                $service->update(DocumentFolder::query()->findOrFail($this->editingFolderId), $this->folderName, $pai);
            } else {
                $service->create($this->folderName, $pai);
            }
        } catch (DomainException $e) {
            $this->addError('folderName', $e->getMessage());

            return;
        }

        $this->showFolderForm = false;
    }

    /** O conteúdo da pasta (documentos e subpastas) sobe para a pasta de cima: nada é apagado junto. */
    public function deleteFolder(string $folderId, DocumentFolderService $service): void
    {
        $this->abortUnlessCanManage();
        $pasta = DocumentFolder::query()->findOrFail($folderId);

        // Se a pasta aberta está dentro da que sai, volta para o nível de cima dela.
        if ($this->pasta === $pasta->id || $service->descendantIds($pasta)->contains($this->pasta)) {
            $this->pasta = (string) $pasta->parent_id;
        }

        $service->delete($pasta);
        $this->showFolderForm = false;
    }

    public function criarPastasSugeridas(DocumentFolderService $service): void
    {
        $this->abortUnlessCanManage();
        $service->createSuggested();
    }

    public function render(DocumentFolderService $pastas)
    {
        $profissional = app(ProfileContext::class)->isConsultant();

        $todas = DocumentFolder::query()->orderBy('name')->get();
        // Documentos por pasta: o escopo de visibilidade já corta o que o profissional não pode ver.
        $docsPorPasta = Document::query()->whereNotNull('folder_id')
            ->selectRaw('folder_id, count(*) as total')->groupBy('folder_id')->pluck('total', 'folder_id');

        // Profissional só vê pasta que tenha, dentro dela ou abaixo, algum documento que ele pode ver:
        // o NOME de uma pasta cheia de documentos privados não vaza.
        $permitidas = $profissional ? $this->pastasVisiveis($todas, $docsPorPasta->keys()->all()) : null;
        $visiveis = $permitidas === null ? $todas : $todas->whereIn('id', $permitidas)->values();

        $atual = $this->pasta !== '' ? $visiveis->firstWhere('id', $this->pasta) : null;
        $caminho = $atual !== null ? $pastas->ancestors($atual) : collect();

        $subpastas = $visiveis->where('parent_id', $atual?->id)->values()->map(fn (DocumentFolder $p) => [
            'pasta' => $p,
            'documentos' => (int) ($docsPorPasta[$p->id] ?? 0),
            'subpastas' => $visiveis->where('parent_id', $p->id)->count(),
        ]);

        $documentos = Document::query()->with('member', 'insurancePolicy', 'folder')
            ->when(! $this->verTodos, fn ($q) => $q->where('folder_id', $atual?->id))
            ->latest()->get();

        $arvore = $profissional ? collect() : $pastas->flatTree();

        return view('livewire.documents.document-vault-index', [
            'documents' => $documentos,
            'membros' => $this->membros(),
            'policies' => InsurancePolicy::query()->active()->orderBy('insurer_name')->get(),
            'podeGerenciar' => ! $profissional,
            'editingExisting' => $this->editingId !== null,
            'pastaAtual' => $atual,
            'caminho' => $caminho,
            'subpastas' => $subpastas,
            'arvore' => $arvore,
            'arvoreParaMover' => $this->editingFolderId !== null ? $pastas->flatTree($this->editingFolderId) : $arvore,
            'caminhoPorPasta' => $arvore->pluck('caminho', 'pasta.id'),
            'temPastas' => $todas->isNotEmpty(),
            'editingFolder' => $this->editingFolderId !== null,
        ]);
    }

    /**
     * Ids das pastas que o profissional pode ver: as que têm documento visível a ele e todas as pastas
     * de cima delas (o caminho até lá).
     *
     * @param  Collection<int, DocumentFolder>  $todas
     * @param  array<int, string>  $comDocumento
     * @return array<int, string>
     */
    private function pastasVisiveis(Collection $todas, array $comDocumento): array
    {
        $paiDe = $todas->pluck('parent_id', 'id');
        $ids = [];

        foreach ($comDocumento as $id) {
            while ($id !== null && ! in_array($id, $ids, true) && $paiDe->has($id)) {
                $ids[] = $id;
                $id = $paiDe[$id];
            }
        }

        return $ids;
    }
    private function membros(): Collection
    {
        return ProfileMember::query()
            ->where('profile_id', app(ProfileContext::class)->profileId())
            ->where('is_active', true)
            ->orderBy('role')
            ->get();
    }

    private function autor(): ProfileMember
    {
        return app(ProfileContext::class)->member();
    }

    /** Profissional pode ENXERGAR o que a categoria libera, mas nunca subir/remover documento em nome do cliente. */
    private function abortUnlessCanManage(): void
    {
        abort_if(app(ProfileContext::class)->isConsultant(), 403);
    }

    private function resetFolderForm(): void
    {
        $this->reset('editingFolderId', 'folderName', 'folderParentId');
        $this->resetErrorBag();
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'category', 'title', 'memberId', 'folderId', 'insurancePolicyId', 'expiresOn', 'visibleToProfessional', 'arquivo');
        $this->resetErrorBag();
    }
}
