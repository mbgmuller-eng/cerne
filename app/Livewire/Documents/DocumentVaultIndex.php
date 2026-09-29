<?php

namespace App\Livewire\Documents;

use App\Enums\DocumentCategory;
use App\Livewire\Concerns\RequiresActiveProfile;
use App\Models\Document;
use App\Models\InsurancePolicy;
use App\Models\ProfileMember;
use App\Services\DocumentService;
use App\Support\ProfileContext;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
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

    public bool $showForm = false;
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
            'insurancePolicyId' => ['required_if:category,'.DocumentCategory::InsurancePolicy->value],
            'expiresOn' => ['nullable', 'date'],
            'arquivo' => [
                'required',
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

        $service->upload($this->arquivo, [
            'category' => $data['category'],
            'title' => $data['title'],
            'member_id' => $data['memberId'] !== '' ? $data['memberId'] : null,
            'insurance_policy_id' => $data['insurancePolicyId'] !== '' ? $data['insurancePolicyId'] : null,
            'expires_on' => $data['expiresOn'] !== '' ? $data['expiresOn'] : null,
            'visible_to_professional' => $this->visibleToProfessional,
        ], $this->autor());

        $this->showForm = false;
    }

    public function delete(string $documentId, DocumentService $service): void
    {
        $this->abortUnlessCanManage();
        $service->delete(Document::query()->findOrFail($documentId));
    }

    public function render()
    {
        return view('livewire.documents.document-vault-index', [
            'documents' => Document::query()->with('member', 'insurancePolicy')->latest()->get(),
            'membros' => $this->membros(),
            'policies' => InsurancePolicy::query()->active()->orderBy('insurer_name')->get(),
            'podeGerenciar' => ! app(ProfileContext::class)->isConsultant(),
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

    private function autor(): ProfileMember
    {
        return app(ProfileContext::class)->member();
    }

    /** Profissional pode ENXERGAR o que a categoria libera, mas nunca subir/remover documento em nome do cliente. */
    private function abortUnlessCanManage(): void
    {
        abort_if(app(ProfileContext::class)->isConsultant(), 403);
    }

    private function resetForm(): void
    {
        $this->reset('category', 'title', 'memberId', 'insurancePolicyId', 'expiresOn', 'visibleToProfessional', 'arquivo');
        $this->resetErrorBag();
    }
}
