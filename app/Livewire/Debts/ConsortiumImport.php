<?php

namespace App\Livewire\Debts;

use App\Enums\AdjustmentIndex;
use App\Enums\ConsortiumType;
use App\Enums\DocumentType;
use App\Enums\ProcessingStatus;
use App\Jobs\ProcessDocumentJob;
use App\Models\DocumentUpload;
use App\Models\Goal;
use App\Models\ProfileMember;
use App\Services\Extraction\ConsortiumImportService;
use App\Support\ProfileContext;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Importar o extrato da administradora de consórcio (PDF), dentro de Dívidas. A leitura roda por IA em segundo plano e NADA é
 * gravado até a pessoa conferir os dados (regra 5 do CLAUDE.md): o extrato traz o valor realmente pago em cada parcela e quais
 * ficaram sem pagamento (diluídas nas seguintes), o que o cadastro manual só consegue aproximar.
 *
 * Cada pessoa só enxerga as próprias leituras.
 */
class ConsortiumImport extends Component
{
    use WithFileUploads;

    public bool $showUpload = false;

    public $arquivo = null;

    public bool $showReview = false;

    public ?string $reviewingId = null;

    /** @var array<string, mixed> */
    public array $row = [];

    public ?string $mensagem = null;

    #[On('open-consortium-import')]
    public function openUpload(): void
    {
        $this->reset('arquivo');
        $this->resetErrorBag();
        $this->showUpload = true;
    }

    public function enviar(): void
    {
        $this->validate([
            'arquivo' => ['required', 'file', 'mimes:pdf', 'max:'.(config('cerne.ai.max_upload_mb') * 1024)],
        ], attributes: ['arquivo' => 'arquivo']);

        if ($this->limiteDiarioAtingido()) {
            $this->addError('arquivo', 'Limite de '.config('cerne.ai.consortium_imports_per_day').' leituras por dia atingido neste perfil. Tente amanhã ou cadastre o consórcio manualmente.');

            return;
        }

        $contexto = app(ProfileContext::class);

        // Disco privado: o extrato traz CPF, endereço e telefone.
        $caminho = $this->arquivo->store(config('cerne.documents.path').'/'.$contexto->profileId(), config('cerne.documents.disk'));

        $documento = DocumentUpload::create([
            'uploaded_by_user_id' => auth()->id(),
            'member_id' => $contexto->memberId(),
            'document_type' => DocumentType::ConsortiumStatement,
            'original_filename' => $this->arquivo->getClientOriginalName(),
            'storage_path' => $caminho,
            'size_bytes' => $this->arquivo->getSize(),
            'processing_status' => ProcessingStatus::Pending,
        ]);

        // Sem chave configurada o envio fica "Na fila" e a rotina agendada o pega assim que a chave existir.
        if (filled(config('cerne.ai.api_key'))) {
            ProcessDocumentJob::dispatch($documento->id);
        }

        $this->reset('arquivo');
        $this->showUpload = false;
        $this->mensagem = 'Extrato enviado. A leitura acontece em segundo plano e você será avisado quando estiver pronta para revisar.';
    }

    public function revisar(string $id, ConsortiumImportService $servico): void
    {
        $documento = $this->minhaLeitura($id);

        if (! $documento->isAwaitingReview()) {
            return;
        }

        $linha = $servico->rowFor($documento);

        if ($linha === null) {
            $this->mensagem = 'Não foi possível ler um consórcio neste PDF. Confira se é o "Extrato do Consorciado" da administradora.';

            return;
        }

        $this->row = $linha;
        $this->reviewingId = $documento->id;
        $this->resetErrorBag();
        $this->showReview = true;
    }

    public function fecharRevisao(): void
    {
        $this->showReview = false;
        $this->reviewingId = null;
        $this->row = [];
        $this->resetErrorBag();
    }

    /** Trocar o destino traz do cadastro o que o extrato não informa (nome, índice, dono, flex). */
    public function updatedRowTarget(string $valor): void
    {
        if ($valor === 'new') {
            return;
        }

        $divida = app(ConsortiumImportService::class)->candidates()->firstWhere('id', $valor);

        if ($divida === null) {
            return;
        }

        $this->row['name'] = $divida->name;
        $this->row['adjustment_index'] = $divida->adjustment_index?->value ?? $this->row['adjustment_index'];
        $this->row['adjustment_every_months'] = (string) ($divida->adjustment_every_months ?? $this->row['adjustment_every_months']);
        $this->row['member_id'] = (string) $divida->member_id;
        $this->row['is_private'] = $divida->is_private;

        if ($divida->flex_percent !== null) {
            $this->row['flex_percent'] = (string) $divida->flex_percent;
        }
    }

    public function confirmar(ConsortiumImportService $servico)
    {
        $documento = $this->minhaLeitura((string) $this->reviewingId);

        abort_unless($documento->isAwaitingReview(), 404);

        $perfilId = app(ProfileContext::class)->profileId();
        $destinos = array_merge(['new'], $servico->candidates()->pluck('id')->all());

        $this->validate([
            'row.target' => ['required', Rule::in($destinos)],
            'row.name' => ['nullable', 'string', 'max:120'],
            'row.creditor' => ['nullable', 'string', 'max:120'],
            'row.consortium_type' => ['required', ConsortiumType::rule()],
            'row.group' => ['nullable', 'string', 'max:40'],
            'row.contract' => ['nullable', 'string', 'max:40'],
            'row.credit_amount' => ['required', 'numeric', 'gt:0'],
            'row.installment_amount' => ['required', 'numeric', 'gt:0'],
            'row.term' => ['required', 'integer', 'min:1', 'max:480'],
            'row.flex_percent' => ['nullable', Rule::in(['10', '20', '30', '40', '50'])],
            'row.adjustment_index' => ['nullable', AdjustmentIndex::rule()],
            'row.adjustment_every_months' => [($this->row['adjustment_index'] ?? '') !== '' ? 'required' : 'nullable', 'integer', 'min:1', 'max:60'],
            'row.last_adjustment_on' => ['nullable', 'date'],
            'row.anchor_number' => ['required', 'integer', 'min:1', 'lte:row.term'],
            'row.anchor_due' => ['required', 'date'],
            'row.statement_on' => ['nullable', 'date'],
            'row.statement_balance' => ['nullable', 'numeric', 'min:0'],
            'row.statement_remaining' => ['nullable', 'integer', 'min:0'],
            'row.insurance_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'row.member_id' => ['nullable', Rule::exists('profile_members', 'id')->where('profile_id', $perfilId)],
            'row.goal_name' => [($this->row['goal_choice'] ?? '') === 'new' ? 'required' : 'nullable', 'string', 'max:120'],
        ], [], [
            'row.credit_amount' => 'valor da carta', 'row.installment_amount' => 'parcela atual', 'row.term' => 'prazo do plano',
            'row.anchor_number' => 'número da parcela de referência', 'row.anchor_due' => 'vencimento da parcela de referência',
            'row.adjustment_every_months' => 'intervalo do reajuste', 'row.goal_name' => 'nome do objetivo',
        ]);

        try {
            $divida = $servico->commit($documento, $this->row);
        } catch (InvalidArgumentException $e) {
            $this->addError('row.term', $e->getMessage());

            return;
        }

        $this->fecharRevisao();

        return $this->redirectRoute('debts.show', $divida, navigate: false);
    }

    public function descartar(string $id): void
    {
        $documento = $this->minhaLeitura($id);

        // Em andamento não se descarta: o job ainda vai ler o arquivo.
        if (! in_array($documento->processing_status, [ProcessingStatus::Completed, ProcessingStatus::Failed], true)) {
            return;
        }

        // O registro fica (como resolvido) para a leitura continuar contando no limite diário.
        $documento->deleteFile();
        $documento->update([
            'processing_status' => ProcessingStatus::Committed,
            'excluded_item_indices' => array_keys($documento->extractedItems()),
            'committed_at' => now(),
        ]);

        if ($this->reviewingId === $id) {
            $this->fecharRevisao();
        }

        $this->mensagem = 'Leitura descartada.';
    }

    /** O arquivo já está salvo: refaz a leitura sem precisar enviar de novo. */
    public function tentarDeNovo(string $id): void
    {
        $documento = $this->minhaLeitura($id);

        if ($documento->processing_status !== ProcessingStatus::Failed) {
            return;
        }

        $documento->update(['processing_status' => ProcessingStatus::Pending, 'error_message' => null]);

        if (filled(config('cerne.ai.api_key'))) {
            ProcessDocumentJob::dispatch($documento->id);
        }

        $this->mensagem = 'Leitura na fila para nova tentativa.';
    }

    /** @return Collection<int, DocumentUpload> leituras que ainda não viraram consórcio, só as do usuário logado */
    public function getLeiturasProperty(): Collection
    {
        return DocumentUpload::query()
            ->where('document_type', DocumentType::ConsortiumStatement->value)
            ->where('uploaded_by_user_id', auth()->id())
            ->where('processing_status', '!=', ProcessingStatus::Committed->value)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();
    }

    public function render()
    {
        $contexto = app(ProfileContext::class);

        return view('livewire.debts.consortium-import', [
            'leituras' => $this->leituras,
            'emAndamento' => $this->leituras->contains(fn (DocumentUpload $d) => in_array($d->processing_status, [ProcessingStatus::Pending, ProcessingStatus::Processing], true)),
            'iaConfigurada' => filled(config('cerne.ai.api_key')),
            'limiteDiario' => config('cerne.ai.consortium_imports_per_day'),
            'restantesHoje' => max(0, config('cerne.ai.consortium_imports_per_day') - $this->enviadasHoje()),
            'revisando' => $this->showReview && $this->reviewingId,
            'membros' => $this->showReview
                ? ProfileMember::query()->where('profile_id', $contexto->profileId())->where('is_active', true)->orderBy('name')->get()
                : collect(),
            'consorcios' => $this->showReview ? app(ConsortiumImportService::class)->candidates() : collect(),
            'objetivos' => $this->showReview ? Goal::query()->active()->whereNull('linked_debt_id')->orderBy('priority')->get() : collect(),
            'tiposConsorcio' => ConsortiumType::options(),
            'indices' => AdjustmentIndex::options(),
        ]);
    }

    private function minhaLeitura(string $id): DocumentUpload
    {
        return DocumentUpload::query()
            ->where('document_type', DocumentType::ConsortiumStatement->value)
            ->where('uploaded_by_user_id', auth()->id())
            ->findOrFail($id);
    }

    private function enviadasHoje(): int
    {
        return DocumentUpload::query()
            ->where('document_type', DocumentType::ConsortiumStatement->value)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();
    }

    private function limiteDiarioAtingido(): bool
    {
        return $this->enviadasHoje() >= config('cerne.ai.consortium_imports_per_day');
    }
}
