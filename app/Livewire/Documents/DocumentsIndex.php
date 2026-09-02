<?php

namespace App\Livewire\Documents;

use App\Enums\DocumentType;
use App\Enums\Necessity;
use App\Enums\ProcessingStatus;
use App\Jobs\ProcessDocumentJob;
use App\Livewire\Concerns\RequiresActiveProfile;
use App\Models\BankAccount;
use App\Models\DocumentUpload;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Models\IncomeCategory;
use App\Services\Extraction\CategorizationRuleMatcher;
use App\Services\Extraction\DocumentCommitService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
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
    use RequiresActiveProfile;

    use WithFileUploads;

    public $arquivo;

    public string $documentType = 'bank_statement';

    public string $uploadBankAccountId = '';

    /** Documento aberto para revisão. */
    public ?string $revisandoId = null;

    /** Índices dos itens marcados para importar. */
    public array $aceitos = [];

    /**
     * Pré-preenchimento por regra de categorização (ver
     * CategorizationRuleMatcher), indexado pela mesma posição de $aceitos —
     * a pessoa pode ajustar antes de confirmar.
     */
    public array $categoriaPorItem = [];

    public array $subcategoriaPorItem = [];

    public array $necessidadePorItem = [];

    /** Padrão da regra que pré-preencheu o item (null = ninguém casou, categorização é manual). */
    public array $regraAplicadaPorItem = [];

    /** Ocorrência de conta fixa/receita recorrente casada, por item — ver DocumentCommitService. */
    public array $fixedBillPaymentPorItem = [];

    public array $recurringIncomeOccurrencePorItem = [];

    /** Texto explicando o casamento com conta fixa/receita recorrente, quando houver. */
    public array $notaPorItem = [];

    public function mount(): void
    {
        $this->redirectOrAbortWithoutProfile();
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
            // Sem isso, confirmar a importação não tem como debitar/creditar
            // o saldo certo — extrato sem conta é exatamente o bug que
            // deixava o saldo do BTG parado depois de importar.
            'uploadBankAccountId' => ['required_if:documentType,bank_statement'],
        ];
    }

    public function enviar(): void
    {
        $data = $this->validate();

        $context = app(ProfileContext::class);

        // Disco privado: extrato bancário não pode ficar em pasta pública.
        $caminho = $this->arquivo->store(
            config('cerne.documents.path').'/'.$context->profileId(),
            config('cerne.documents.disk'),
        );

        $conta = $data['uploadBankAccountId'] !== ''
            ? BankAccount::query()->findOrFail($data['uploadBankAccountId'])
            : null;

        $documento = DocumentUpload::create([
            'uploaded_by_user_id' => auth()->id(),
            'member_id' => $context->memberId(),
            'bank_account_id' => $conta?->id,
            'document_type' => $this->documentType,
            'original_filename' => $this->arquivo->getClientOriginalName(),
            'storage_path' => $caminho,
            'size_bytes' => $this->arquivo->getSize(),
            'processing_status' => ProcessingStatus::Pending,
        ]);

        // Sem chave configurada, despachar agora só produziria uma falha
        // imediata — o documento fica "Na fila" de verdade e a rotina
        // agendada (routes/console.php) o pega assim que a chave existir.
        if (filled(config('cerne.ai.api_key'))) {
            ProcessDocumentJob::dispatch($documento->id);
        }

        $this->reset('arquivo', 'uploadBankAccountId');
        session()->flash('status', 'Documento enviado. A leitura acontece em segundo plano.');
    }

    public function revisar(string $id, CategorizationRuleMatcher $matcher): void
    {
        $documento = DocumentUpload::findOrFail($id);
        $itens = $documento->extractedItems();

        $this->revisandoId = $id;
        // Todos marcados por padrão: o usuário desmarca o que estiver
        // errado, que é mais rápido do que marcar dezenas de linhas certas.
        $this->aceitos = array_keys($itens);
        $this->categoriaPorItem = [];
        $this->subcategoriaPorItem = [];
        $this->necessidadePorItem = [];
        $this->regraAplicadaPorItem = [];
        $this->fixedBillPaymentPorItem = [];
        $this->recurringIncomeOccurrencePorItem = [];
        $this->notaPorItem = [];

        // Casamento com conta fixa (ver Parte B do plano) só faz sentido
        // pra extrato bancário — fatura de cartão não debita conta nenhuma
        // por este caminho hoje.
        $casaContaFixa = $documento->document_type === DocumentType::BankStatement;

        if (! in_array($documento->document_type, [DocumentType::BankStatement, DocumentType::CreditCardInvoice], true)) {
            return;
        }

        foreach ($itens as $i => $item) {
            $data = $this->parseItemDate($item['data'] ?? null);

            if ($data === null) {
                continue;
            }

            if (($item['tipo'] ?? null) === 'receita') {
                $this->preencherReceita($i, $item, $data, $matcher, $casaContaFixa);
            } else {
                $this->preencherDespesa($i, $item, $data, $matcher, $casaContaFixa);
            }
        }
    }

    private function preencherDespesa(int $i, array $item, CarbonImmutable $data, CategorizationRuleMatcher $matcher, bool $casaContaFixa): void
    {
        $match = $matcher->matchExpense($item['descricao'] ?? '', $data);

        if ($match === null) {
            return;
        }

        $this->categoriaPorItem[$i] = $match['category_id'];
        $this->subcategoriaPorItem[$i] = (string) $match['subcategory_id'];
        $this->necessidadePorItem[$i] = $match['necessity']->value;
        $this->regraAplicadaPorItem[$i] = $match['rule']->pattern;

        $pagamento = $casaContaFixa ? $match['fixed_bill_payment'] : null;

        if ($pagamento === null) {
            return;
        }

        $this->fixedBillPaymentPorItem[$i] = $pagamento->id;

        if ($pagamento->status->isOutstanding()) {
            $this->notaPorItem[$i] = "Vai dar baixa na conta fixa \"{$pagamento->fixedBill->name}\", venc. {$pagamento->due_date->format('d/m')}.";
        } else {
            $this->notaPorItem[$i] = 'Já dado baixa'.($pagamento->paid_at ? " em {$pagamento->paid_at->format('d/m')}" : '').' — não importa de novo.';
            $this->aceitos = array_values(array_diff($this->aceitos, [$i]));
        }
    }

    private function preencherReceita(int $i, array $item, CarbonImmutable $data, CategorizationRuleMatcher $matcher, bool $casaContaFixa): void
    {
        $match = $matcher->matchIncome($item['descricao'] ?? '', $data);

        if ($match === null) {
            return;
        }

        $this->categoriaPorItem[$i] = $match['category_id'];
        $this->regraAplicadaPorItem[$i] = $match['rule']->pattern;

        $ocorrencia = $casaContaFixa ? $match['recurring_income_occurrence'] : null;

        if ($ocorrencia === null) {
            return;
        }

        $this->recurringIncomeOccurrencePorItem[$i] = $ocorrencia->id;

        if ($ocorrencia->status->isOutstanding()) {
            $this->notaPorItem[$i] = "Vai dar baixa na receita recorrente \"{$ocorrencia->recurringIncome->name}\", venc. {$ocorrencia->due_date->format('d/m')}.";
        } else {
            $this->notaPorItem[$i] = 'Já dado baixa'.($ocorrencia->received_at ? " em {$ocorrencia->received_at->format('d/m')}" : '').' — não importa de novo.';
            $this->aceitos = array_values(array_diff($this->aceitos, [$i]));
        }
    }

    /** Mesma leitura tolerante de data do DocumentCommitService::parseDate() — item ilegível não derruba a revisão inteira. */
    private function parseItemDate(?string $valor): ?CarbonImmutable
    {
        if (blank($valor)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($valor);
        } catch (\Throwable) {
            return null;
        }
    }

    public function fecharRevisao(): void
    {
        $this->reset(
            'revisandoId', 'aceitos', 'categoriaPorItem', 'subcategoriaPorItem', 'necessidadePorItem',
            'regraAplicadaPorItem', 'fixedBillPaymentPorItem', 'recurringIncomeOccurrencePorItem', 'notaPorItem',
        );
    }

    /**
     * Trocar a necessidade de um item pode invalidar a categoria já
     * escolhida (ex.: categoria de Investimento com a necessidade trocada
     * pra Essencial) — mesmo raciocínio de
     * CashFlowIndex::updatedExpenseNecessity(), só que por item da tabela.
     */
    public function updated(string $name): void
    {
        if (! str_starts_with($name, 'necessidadePorItem.')) {
            return;
        }

        $i = (int) substr($name, strlen('necessidadePorItem.'));
        $necessidade = $this->necessidadePorItem[$i] ?? '';

        if ($necessidade === Necessity::Investment->value) {
            $this->subcategoriaPorItem[$i] = '';
        }

        $categoriaId = $this->categoriaPorItem[$i] ?? '';

        if ($categoriaId === '' || $this->expenseCategoriesForNecessity($necessidade)->contains('id', $categoriaId)) {
            return;
        }

        $this->categoriaPorItem[$i] = '';
        $this->subcategoriaPorItem[$i] = '';
    }

    /** @return Collection<int, ExpenseCategory> */
    private function expenseCategoriesForNecessity(string $necessity): Collection
    {
        return ExpenseCategory::available()
            ->when(
                $necessity === Necessity::Investment->value,
                fn ($query) => $query->where('necessity', Necessity::Investment->value),
                fn ($query) => $query->whereNull('necessity'),
            )
            ->get();
    }

    /**
     * Índice do item (despesa aceita) => falta necessidade, categoria ou
     * subcategoria. Sem regra que bateu, a IA não decide isso sozinha —
     * vira revisão humana obrigatória, não um lembrete que dá pra ignorar.
     *
     * @return array<int, bool>
     */
    public function getItensFaltandoCategoriaProperty(): array
    {
        if ($this->revisando === null) {
            return [];
        }

        $faltando = [];

        foreach ($this->revisando->extractedItems() as $i => $item) {
            if (($item['tipo'] ?? null) === 'receita') {
                continue;
            }

            $necessidade = $this->necessidadePorItem[$i] ?? '';
            $categoria = $this->categoriaPorItem[$i] ?? '';
            $subcategoria = $this->subcategoriaPorItem[$i] ?? '';

            $faltando[$i] = $necessidade === ''
                || $categoria === ''
                || ($necessidade !== Necessity::Investment->value && $subcategoria === '');
        }

        return $faltando;
    }

    public function confirmar(DocumentCommitService $commit): void
    {
        $faltandoNosAceitos = array_filter(
            $this->itensFaltandoCategoria,
            fn (bool $falta, int $i) => $falta && in_array($i, $this->aceitos, true),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($faltandoNosAceitos !== []) {
            $this->addError('confirmar', 'Categorize necessidade, categoria e subcategoria de todos os itens selecionados antes de importar.');

            return;
        }

        $documento = DocumentUpload::findOrFail($this->revisandoId);

        try {
            $criados = $commit->commit($documento, array_map('intval', $this->aceitos), auth()->id(), [
                'categoria' => $this->categoriaPorItem,
                'subcategoria' => $this->subcategoriaPorItem,
                'necessidade' => $this->necessidadePorItem,
                'fixedBillPayment' => $this->fixedBillPaymentPorItem,
                'recurringIncomeOccurrence' => $this->recurringIncomeOccurrencePorItem,
            ]);
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
            'bankAccounts' => BankAccount::query()->active()->orderBy('bank_name')->get(),
            'expenseCategories' => ExpenseCategory::query()->available()->get(),
            'expenseSubcategories' => ExpenseSubcategory::query()->available()->get(),
            'incomeCategories' => IncomeCategory::query()->available()->get(),
            'itensFaltandoCategoria' => $this->itensFaltandoCategoria,
        ]);
    }
}
