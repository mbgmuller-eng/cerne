<?php

namespace App\Livewire\Insurance;

use App\Enums\DocumentType;
use App\Enums\InsuranceType;
use App\Enums\PaymentFrequency;
use App\Enums\ProcessingStatus;
use App\Jobs\ProcessDocumentJob;
use App\Models\DocumentUpload;
use App\Models\InsurancePolicy;
use App\Models\ProfileMember;
use App\Services\InsuranceImportService;
use App\Support\ProfileContext;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Importar apólice por PDF, dentro de Seguros: o arquivo é lido por IA em segundo plano e NADA é gravado
 * até a pessoa conferir cada campo na revisão (regra 5 do CLAUDE.md). Cada apólice lida pode criar uma
 * apólice nova ou atualizar uma existente (renovação), e o PDF é guardado em Documentos ligado a ela.
 *
 * Cada pessoa só enxerga as próprias leituras: um corretor não vê o que o cliente enviou, nem o contrário.
 */
class InsuranceImport extends Component
{
    use WithFileUploads;

    public bool $showUpload = false;

    public $arquivo = null;

    public bool $showReview = false;

    public ?string $reviewingId = null;

    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    public ?string $mensagem = null;

    #[On('open-insurance-import')]
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
            $this->addError('arquivo', 'Limite de '.config('cerne.ai.insurance_imports_per_day').' leituras por dia atingido neste perfil. Tente amanhã ou cadastre a apólice manualmente.');

            return;
        }

        $contexto = app(ProfileContext::class);

        // Disco privado: apólice tem dado pessoal e não pode ficar em pasta pública.
        $caminho = $this->arquivo->store(config('cerne.documents.path').'/'.$contexto->profileId(), config('cerne.documents.disk'));

        $documento = DocumentUpload::create([
            'uploaded_by_user_id' => auth()->id(),
            'member_id' => $contexto->memberId(),
            'document_type' => DocumentType::InsurancePolicy,
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
        $this->mensagem = 'PDF enviado. A leitura acontece em segundo plano e você será avisado quando estiver pronta para revisar.';
    }

    public function revisar(string $id, InsuranceImportService $servico): void
    {
        $documento = $this->minhaLeitura($id);

        if (! $documento->isAwaitingReview()) {
            return;
        }

        $this->rows = $servico->rowsFor($documento);
        $this->reviewingId = $documento->id;
        $this->resetErrorBag();
        $this->showReview = true;
    }

    public function fecharRevisao(): void
    {
        $this->showReview = false;
        $this->reviewingId = null;
        $this->rows = [];
        $this->resetErrorBag();
    }

    public function addCobertura(int $i): void
    {
        $this->rows[$i]['coberturas'][] = ['nome' => '', 'valor' => '', 'franquia' => ''];
    }

    public function removeCobertura(int $i, int $j): void
    {
        unset($this->rows[$i]['coberturas'][$j]);
        $this->rows[$i]['coberturas'] = array_values($this->rows[$i]['coberturas']);
    }

    public function addBeneficiario(int $i): void
    {
        $this->rows[$i]['beneficiarios'][] = ['nome' => '', 'percentual' => '', 'parentesco' => ''];
    }

    public function removeBeneficiario(int $i, int $j): void
    {
        unset($this->rows[$i]['beneficiarios'][$j]);
        $this->rows[$i]['beneficiarios'] = array_values($this->rows[$i]['beneficiarios']);
    }

    public function confirmar(InsuranceImportService $servico): void
    {
        $documento = $this->minhaLeitura((string) $this->reviewingId);

        abort_unless($documento->isAwaitingReview(), 404);

        $marcadas = array_filter($this->rows, fn (array $linha) => (bool) ($linha['import'] ?? false));

        if ($marcadas === []) {
            $this->addError('rows', 'Marque ao menos uma apólice para importar, ou descarte a leitura.');

            return;
        }

        $this->validate($this->rulesFor($marcadas), attributes: $this->attributesFor($marcadas));

        foreach ($marcadas as $i => $linha) {
            $soma = collect($linha['beneficiarios'] ?? [])
                ->filter(fn (array $b) => trim((string) $b['nome']) !== '')
                ->sum(fn (array $b) => (float) $b['percentual']);

            if ($soma > 0 && abs($soma - 100) >= 0.01) {
                $this->addError("rows.$i.beneficiarios", 'Os percentuais dos beneficiários precisam somar 100% (hoje somam '.rtrim(rtrim(number_format($soma, 2, ',', ''), '0'), ',').'%).');

                return;
            }
        }

        $resultado = $servico->commit($documento, $this->rows, app(ProfileContext::class)->member());

        $partes = array_filter([
            $resultado['criadas'] ? $resultado['criadas'].($resultado['criadas'] === 1 ? ' apólice cadastrada' : ' apólices cadastradas') : null,
            $resultado['atualizadas'] ? $resultado['atualizadas'].($resultado['atualizadas'] === 1 ? ' apólice atualizada' : ' apólices atualizadas') : null,
            $resultado['historicas'] ? $resultado['historicas'].($resultado['historicas'] === 1 ? ' versão antiga guardada no histórico' : ' versões antigas guardadas no histórico') : null,
        ]);
        $this->mensagem = ($partes === [] ? 'Nenhuma apólice importada' : ucfirst(implode(' e ', $partes))).'. O PDF ficou guardado em Documentos.';

        $this->fecharRevisao();
        $this->dispatch('policies-imported');
    }

    public function descartar(string $id): void
    {
        $documento = $this->minhaLeitura($id);

        // Em andamento não se descarta: o job ainda vai ler o arquivo.
        if (! in_array($documento->processing_status, [ProcessingStatus::Completed, ProcessingStatus::Failed], true)) {
            return;
        }

        // O registro fica (como resolvido) para a leitura continuar contando no limite diário: apagar a
        // linha deixaria descartar e reenviar à vontade, e cada leitura já custou uma chamada à API.
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

    /** O arquivo já está salvo: refaz a leitura sem precisar enviar de novo (ex.: a API ficou sem crédito). */
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

    /** @return Collection<int, DocumentUpload> leituras que ainda não viraram apólice, só as do usuário logado */
    public function getLeiturasProperty(): Collection
    {
        return DocumentUpload::query()
            ->where('document_type', DocumentType::InsurancePolicy->value)
            ->where('uploaded_by_user_id', auth()->id())
            ->where('processing_status', '!=', ProcessingStatus::Committed->value)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();
    }

    public function render()
    {
        $contexto = app(ProfileContext::class);

        return view('livewire.insurance.insurance-import', [
            'leituras' => $this->leituras,
            // Só enquanto a leitura ainda roda: esperando revisão não há o que atualizar sozinho.
            'emAndamento' => $this->leituras->contains(fn (DocumentUpload $d) => in_array($d->processing_status, [ProcessingStatus::Pending, ProcessingStatus::Processing], true)),
            'iaConfigurada' => filled(config('cerne.ai.api_key')),
            'limiteDiario' => config('cerne.ai.insurance_imports_per_day'),
            'restantesHoje' => max(0, config('cerne.ai.insurance_imports_per_day') - $this->enviadasHoje()),
            'members' => $this->showReview
                ? ProfileMember::query()->where('profile_id', $contexto->profileId())->where('is_active', true)->orderBy('name')->get()
                : collect(),
            'apolicesExistentes' => $this->showReview
                ? InsurancePolicy::query()->active()->orderBy('insurer_name')->get()
                : collect(),
            'revisando' => $this->showReview && $this->reviewingId ? DocumentUpload::find($this->reviewingId) : null,
        ]);
    }

    private function minhaLeitura(string $id): DocumentUpload
    {
        return DocumentUpload::query()
            ->where('document_type', DocumentType::InsurancePolicy->value)
            ->where('uploaded_by_user_id', auth()->id())
            ->findOrFail($id);
    }

    private function enviadasHoje(): int
    {
        return DocumentUpload::query()
            ->where('document_type', DocumentType::InsurancePolicy->value)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();
    }

    private function limiteDiarioAtingido(): bool
    {
        return $this->enviadasHoje() >= config('cerne.ai.insurance_imports_per_day');
    }

    /**
     * @param  array<int, array<string, mixed>>  $marcadas
     * @return array<string, mixed>
     */
    private function rulesFor(array $marcadas): array
    {
        $perfilId = app(ProfileContext::class)->profileId();
        $destinos = array_merge(['new'], InsurancePolicy::query()->pluck('id')->all());
        $regras = [];

        foreach (array_keys($marcadas) as $i) {
            $regras += [
                "rows.$i.target" => ['required', Rule::in($destinos)],
                "rows.$i.tipo" => ['required', Rule::enum(InsuranceType::class)],
                "rows.$i.seguradora" => ['required', 'string', 'max:255'],
                "rows.$i.numero" => ['nullable', 'string', 'max:100'],
                "rows.$i.member_id" => ['nullable', Rule::exists('profile_members', 'id')->where('profile_id', $perfilId)],
                "rows.$i.pessoa" => ['nullable', 'string', 'max:255'],
                "rows.$i.objeto" => ['nullable', 'string', 'max:255'],
                "rows.$i.valor_segurado" => ['nullable', 'numeric', 'min:0'],
                "rows.$i.premio" => ['required', 'numeric', 'min:0'],
                "rows.$i.periodicidade" => ['required', Rule::enum(PaymentFrequency::class)],
                "rows.$i.premio_anual" => ['nullable', 'numeric', 'min:0'],
                "rows.$i.inicio" => ['required', 'date'],
                "rows.$i.fim" => ['nullable', 'date', "after_or_equal:rows.$i.inicio"],
                // Versão antiga só entra na linha do tempo se souber de quando é; as demais usam o início da vigência.
                "rows.$i.vigente_desde" => [($marcadas[$i]['target'] ?? 'new') !== 'new' && ($marcadas[$i]['somente_historico'] ?? false) ? 'required' : 'nullable', 'date'],
                "rows.$i.somente_historico" => ['boolean'],
                "rows.$i.notas" => ['nullable', 'string', 'max:2000'],
                "rows.$i.coberturas.*.nome" => ['nullable', 'string', 'max:255'],
                "rows.$i.coberturas.*.valor" => ['nullable', 'numeric', 'min:0'],
                "rows.$i.coberturas.*.franquia" => ['nullable', 'string', 'max:255'],
                "rows.$i.beneficiarios.*.nome" => ['nullable', 'string', 'max:255'],
                "rows.$i.beneficiarios.*.percentual" => ['nullable', 'numeric', 'min:0', 'max:100'],
                "rows.$i.beneficiarios.*.parentesco" => ['nullable', 'string', 'max:100'],
            ];
        }

        return $regras;
    }

    /**
     * @param  array<int, array<string, mixed>>  $marcadas
     * @return array<string, string>
     */
    private function attributesFor(array $marcadas): array
    {
        $nomes = [];
        $n = 0;

        foreach (array_keys($this->rows) as $i) {
            $n++;
            if (! isset($marcadas[$i])) {
                continue;
            }
            $sufixo = count($this->rows) > 1 ? " (apólice $n)" : '';

            $nomes += [
                "rows.$i.target" => 'destino'.$sufixo,
                "rows.$i.tipo" => 'tipo de seguro'.$sufixo,
                "rows.$i.seguradora" => 'seguradora'.$sufixo,
                "rows.$i.numero" => 'número da apólice'.$sufixo,
                "rows.$i.member_id" => 'membro'.$sufixo,
                "rows.$i.valor_segurado" => 'valor segurado'.$sufixo,
                "rows.$i.premio" => 'custo'.$sufixo,
                "rows.$i.periodicidade" => 'periodicidade'.$sufixo,
                "rows.$i.premio_anual" => 'prêmio anual'.$sufixo,
                "rows.$i.inicio" => 'início de vigência'.$sufixo,
                "rows.$i.fim" => 'vencimento'.$sufixo,
                "rows.$i.vigente_desde" => 'vigente desde'.$sufixo,
                "rows.$i.coberturas.*.valor" => 'valor da proteção'.$sufixo,
                "rows.$i.coberturas.*.nome" => 'nome da proteção'.$sufixo,
                "rows.$i.beneficiarios.*.percentual" => 'percentual do beneficiário'.$sufixo,
            ];
        }

        return $nomes;
    }
}
