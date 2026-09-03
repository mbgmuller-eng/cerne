<?php

namespace App\Livewire\Documents;

use App\Enums\DocumentType;
use App\Enums\Necessity;
use App\Enums\ProcessingStatus;
use App\Jobs\ProcessDocumentJob;
use App\Livewire\Concerns\RequiresActiveProfile;
use App\Models\BankAccount;
use App\Models\DocumentUpload;
use App\Models\ExpenseCategorizationRule;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\ExpenseSubcategory;
use App\Models\IncomeCategorizationRule;
use App\Models\IncomeCategory;
use App\Models\IncomeRecord;
use App\Services\Extraction\CategorizationRuleMatcher;
use App\Services\Extraction\DocumentCommitService;
use App\Support\Money;
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

    /** Nome de subcategoria nova, quando a existente não serve — mesmo caminho de CashFlowIndex::resolveSubcategoryId(). */
    public array $novaSubcategoriaPorItem = [];

    public array $necessidadePorItem = [];

    /** Padrão da regra que pré-preencheu o item (null = ninguém casou, categorização é manual). */
    public array $regraAplicadaPorItem = [];

    /** Ocorrência de conta fixa/receita recorrente casada, por item — ver DocumentCommitService. */
    public array $fixedBillPaymentPorItem = [];

    public array $recurringIncomeOccurrencePorItem = [];

    /** Texto explicando o casamento com conta fixa/receita recorrente, quando houver. */
    public array $notaPorItem = [];

    /**
     * Aviso quando já existe um lançamento com a mesma conta, data e valor
     * — sinal forte de reimportação do mesmo extrato (ou de um intervalo
     * de datas sobreposto com outro já confirmado). Indexado igual aos
     * demais arrays; item com aviso começa desmarcado.
     */
    public array $duplicataPorItem = [];

    /**
     * "Criar regra também" — quando marcado num item, além de importar o
     * lançamento, também cadastra (ou atualiza, se o padrão já existir) uma
     * ExpenseCategorizationRule/IncomeCategorizationRule com a mesma
     * categorização escolhida pra ele. Evita ter que ir até a tela de
     * Regras logo depois de revisar um extrato.
     */
    public array $criarRegraPorItem = [];

    /** Padrão da regra a criar — pré-preenchido com a descrição do item ao marcar, mas editável. */
    public array $regraPatternPorItem = [];

    /** Trava a regra pelo valor exato do próprio item (ver ExpenseCategorizationRule::$amount) — caso comum: PIX recorrente de valor fixo pra si mesmo. */
    public array $regraValorExatoPorItem = [];

    /** Índice do item com o "confirma?" de exclusão aberto — nulo = nenhum. */
    public ?int $confirmandoExclusaoItem = null;

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
        // Todo item ainda PENDENTE marcado por padrão — o usuário desmarca
        // o que estiver errado, que é mais rápido do que marcar dezenas de
        // linhas certas. Item já importado ou excluído numa rodada
        // anterior nunca volta a ser oferecido (ver
        // DocumentUpload::resolvedItemIndices()).
        $this->aceitos = $documento->pendingItemIndices();
        $this->categoriaPorItem = [];
        $this->subcategoriaPorItem = [];
        $this->novaSubcategoriaPorItem = [];
        $this->necessidadePorItem = [];
        $this->regraAplicadaPorItem = [];
        $this->fixedBillPaymentPorItem = [];
        $this->recurringIncomeOccurrencePorItem = [];
        $this->notaPorItem = [];
        $this->duplicataPorItem = [];
        $this->criarRegraPorItem = [];
        $this->regraPatternPorItem = [];
        $this->regraValorExatoPorItem = [];
        $this->confirmandoExclusaoItem = null;

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

            $ehReceita = ($item['tipo'] ?? null) === 'receita';

            if ($ehReceita) {
                $this->preencherReceita($i, $item, $data, $matcher, $casaContaFixa);
            } else {
                $this->preencherDespesa($i, $item, $data, $matcher, $casaContaFixa);
            }

            // Independe de ter casado com regra — duplicata é contra
            // TUDO que já existe na conta, venha de onde vier (extrato
            // reimportado, intervalo sobreposto, ou lançamento digitado
            // à mão).
            $this->checarDuplicata($i, $item, $data, $documento, $ehReceita);
        }
    }

    /**
     * Mesma conta + mesma data + mesmo valor já lançado é sinal forte de
     * reimportação — não é o mesmo caso de "já dado baixa"
     * (FixedBillPayment/RecurringIncomeOccurrence, que é sobre um
     * vencimento agendado): aqui é duplicata de LANÇAMENTO. Só roda pra
     * extrato bancário — fatura de cartão não tem bank_account_id pra
     * comparar. Desmarca por padrão, igual ao "já dado baixa"; a pessoa
     * pode forçar marcando de novo se não for duplicata de verdade.
     */
    private function checarDuplicata(int $i, array $item, CarbonImmutable $data, DocumentUpload $documento, bool $ehReceita): void
    {
        if ($documento->bank_account_id === null) {
            return;
        }

        $valor = Money::parse($item['valor'] ?? 0);

        $existente = $ehReceita
            ? IncomeRecord::query()
                ->where('bank_account_id', $documento->bank_account_id)
                ->where('received_date', $data->toDateString())
                ->where('amount', $valor)
                ->first()
            : ExpenseRecord::query()
                ->where('bank_account_id', $documento->bank_account_id)
                ->where('expense_date', $data->toDateString())
                ->where('amount', $valor)
                ->first();

        if ($existente === null) {
            return;
        }

        $this->duplicataPorItem[$i] = 'Possível duplicata — já existe "'.$existente->description.'" de '
            .Money::format($valor).' em '.$data->format('d/m/Y').' nesta conta.';
        $this->aceitos = array_values(array_diff($this->aceitos, [$i]));
    }

    private function preencherDespesa(int $i, array $item, CarbonImmutable $data, CategorizationRuleMatcher $matcher, bool $casaContaFixa): void
    {
        $match = $matcher->matchExpense($item['descricao'] ?? '', $data, Money::parse($item['valor'] ?? 0));

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
        $match = $matcher->matchIncome($item['descricao'] ?? '', $data, Money::parse($item['valor'] ?? 0));

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
            'revisandoId', 'aceitos', 'categoriaPorItem', 'subcategoriaPorItem', 'novaSubcategoriaPorItem',
            'necessidadePorItem', 'regraAplicadaPorItem', 'fixedBillPaymentPorItem',
            'recurringIncomeOccurrencePorItem', 'notaPorItem', 'duplicataPorItem',
            'criarRegraPorItem', 'regraPatternPorItem', 'regraValorExatoPorItem',
            'confirmandoExclusaoItem',
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
        if (str_starts_with($name, 'criarRegraPorItem.')) {
            $this->prefillRegraPattern((int) substr($name, strlen('criarRegraPorItem.')));

            return;
        }

        if (! str_starts_with($name, 'necessidadePorItem.')) {
            return;
        }

        $i = (int) substr($name, strlen('necessidadePorItem.'));
        $necessidade = $this->necessidadePorItem[$i] ?? '';

        if ($necessidade === Necessity::Investment->value) {
            $this->subcategoriaPorItem[$i] = '';
            $this->novaSubcategoriaPorItem[$i] = '';
        }

        $categoriaId = $this->categoriaPorItem[$i] ?? '';

        if ($categoriaId === '' || $this->expenseCategoriesForNecessity($necessidade)->contains('id', $categoriaId)) {
            return;
        }

        $this->categoriaPorItem[$i] = '';
        $this->subcategoriaPorItem[$i] = '';
    }

    /** Padrão sugerido = a própria descrição do item, editável — só na primeira vez que a pessoa marca "criar regra" pra esse item. */
    private function prefillRegraPattern(int $i): void
    {
        if (($this->criarRegraPorItem[$i] ?? false) !== true || trim($this->regraPatternPorItem[$i] ?? '') !== '') {
            return;
        }

        $itens = $this->revisando?->extractedItems() ?? [];
        $this->regraPatternPorItem[$i] = trim($itens[$i]['descricao'] ?? '');
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
            $novaSubcategoria = trim($this->novaSubcategoriaPorItem[$i] ?? '');

            $faltando[$i] = $necessidade === ''
                || $categoria === ''
                || ($necessidade !== Necessity::Investment->value && $subcategoria === '' && $novaSubcategoria === '');
        }

        return $faltando;
    }

    /**
     * Importação parcial de propósito: só quem já está com a
     * categorização completa entra nesta rodada — o resto (ainda em
     * revisão) fica pendente, e o documento continua "Aguardando revisão"
     * até TODO item ter um destino (importado ou excluído — ver
     * DocumentUpload::isFullyResolved()). Evita perder o trabalho já
     * feito só porque falta categorizar algumas linhas.
     */
    public function confirmar(DocumentCommitService $commit): void
    {
        if ($this->aceitos === []) {
            $this->addError('confirmar', 'Nenhum item selecionado para importar.');

            return;
        }

        $prontos = array_values(array_filter(
            $this->aceitos,
            fn (int $i) => ! ($this->itensFaltandoCategoria[$i] ?? false),
        ));

        if ($prontos === []) {
            $this->addError('confirmar', 'Nenhum item selecionado está com a categorização completa ainda — pode continuar depois, o documento fica aberto.');

            return;
        }

        $semPatternDeRegra = array_filter(
            $prontos,
            fn (int $i) => ($this->criarRegraPorItem[$i] ?? false) && trim($this->regraPatternPorItem[$i] ?? '') === '',
        );

        if ($semPatternDeRegra !== []) {
            $this->addError('confirmar', 'Preencha o padrão da regra pros itens marcados para "criar regra também".');

            return;
        }

        $this->resolverNovasSubcategorias();

        $documento = DocumentUpload::findOrFail($this->revisandoId);
        $itens = $documento->extractedItems();

        try {
            $criados = $commit->commit($documento, array_map('intval', $prontos), auth()->id(), [
                'categoria' => $this->categoriaPorItem,
                'subcategoria' => $this->subcategoriaPorItem,
                'necessidade' => $this->necessidadePorItem,
                'fixedBillPayment' => $this->fixedBillPaymentPorItem,
                'recurringIncomeOccurrence' => $this->recurringIncomeOccurrencePorItem,
            ]);

            $regrasCriadas = $this->criarRegrasMarcadas($itens, $prontos);

            $documento->refresh();
            // $this->revisando é computed property — Livewire memoiza o
            // resultado por request, então sem isso a tela renderia com o
            // documento de ANTES do commit() (é por causa disso que o
            // painel parecia não fechar / os contadores ficavam errados
            // depois de importar).
            unset($this->revisando);

            $mensagem = "{$criados} lançamentos importados.";
            if ($regrasCriadas > 0) {
                $mensagem .= " {$regrasCriadas} regra(s) de categorização criada(s)/atualizada(s).";
            }

            if ($documento->isFullyResolved()) {
                session()->flash('status', $mensagem);
                $this->fecharRevisao();

                return;
            }

            $pendentes = count($documento->pendingItemIndices());
            $mensagem .= ' '.$pendentes.' '.($pendentes === 1 ? 'item ainda falta categorizar' : 'itens ainda faltam categorizar').' — o documento continua aberto pra revisão.';
            session()->flash('status', $mensagem);
            // Só tira do "selecionado" quem acabou de ser importado — o
            // resto (ainda pendente) mantém o que a pessoa já digitou,
            // não reseta o formulário dela à toa.
            $this->aceitos = array_values(array_diff($this->aceitos, $prontos));
        } catch (\Throwable $e) {
            $this->addError('confirmar', $e->getMessage());
        }
    }

    /**
     * Marca o item pra NUNCA ser importado — diferente de só desmarcar o
     * checkbox (que dura só a sessão de revisão): fica gravado no
     * documento, então numa próxima rodada esse item não volta a ser
     * oferecido. Só faz sentido pra item ainda pendente.
     */
    public function excluirItem(int $i): void
    {
        $documento = DocumentUpload::findOrFail($this->revisandoId);

        if (! $documento->isAwaitingReview() || in_array($i, $documento->resolvedItemIndices(), true)) {
            $this->confirmandoExclusaoItem = null;

            return;
        }

        $excluidos = array_values(array_unique([...($documento->excluded_item_indices ?? []), $i]));
        $finalizado = count(array_unique(array_merge($documento->imported_item_indices ?? [], $excluidos))) >= count($documento->extractedItems());

        $documento->update([
            'excluded_item_indices' => $excluidos,
            'processing_status' => $finalizado ? ProcessingStatus::Committed : ProcessingStatus::Completed,
            'committed_at' => $finalizado ? now() : null,
        ]);
        // Mesmo raciocínio de confirmar() — sem isso a tela ainda mostraria
        // o item como pendente depois de excluído.
        unset($this->revisando);

        $this->aceitos = array_values(array_diff($this->aceitos, [$i]));
        $this->confirmandoExclusaoItem = null;

        if ($finalizado) {
            session()->flash('status', 'Item marcado pra não importar — documento totalmente resolvido e finalizado.');
            $this->fecharRevisao();

            return;
        }

        session()->flash('status', 'Item marcado pra não ser importado.');
    }

    public function confirmarExclusaoItem(int $i): void
    {
        $this->confirmandoExclusaoItem = $i;
    }

    public function cancelarExclusaoItem(): void
    {
        $this->confirmandoExclusaoItem = null;
    }

    /**
     * Roda só depois que o commit deu certo — regra não nasce de um
     * lançamento que não chegou a ser importado. `updateOrCreate` por
     * padrão: se já existir uma regra com esse texto (mesmo padrão), ela é
     * atualizada com a categorização de agora em vez de duplicar (o
     * padrão é único por perfil — ver migration de
     * expense_categorization_rules).
     *
     * @param  array<int, array<string, mixed>>  $itens
     * @param  list<int>  $indices  só os itens que acabaram de ser importados nesta rodada
     */
    private function criarRegrasMarcadas(array $itens, array $indices): int
    {
        $criadas = 0;

        foreach ($indices as $i) {
            if (($this->criarRegraPorItem[$i] ?? false) !== true) {
                continue;
            }

            $pattern = trim($this->regraPatternPorItem[$i] ?? '');

            if ($pattern === '') {
                continue;
            }

            $item = $itens[$i] ?? [];
            $ehReceita = ($item['tipo'] ?? null) === 'receita';
            $valorExato = ($this->regraValorExatoPorItem[$i] ?? false) ? Money::parse($item['valor'] ?? 0) : null;

            if ($ehReceita) {
                IncomeCategorizationRule::query()->updateOrCreate(
                    ['pattern' => $pattern],
                    [
                        'amount' => $valorExato,
                        'category_id' => $this->categoriaPorItem[$i] ?? null,
                        'is_active' => true,
                    ],
                );
            } else {
                ExpenseCategorizationRule::query()->updateOrCreate(
                    ['pattern' => $pattern],
                    [
                        'amount' => $valorExato,
                        'category_id' => $this->categoriaPorItem[$i] ?? null,
                        'subcategory_id' => ($this->subcategoriaPorItem[$i] ?? '') !== '' ? $this->subcategoriaPorItem[$i] : null,
                        'necessity' => $this->necessidadePorItem[$i] ?? null,
                        'is_active' => true,
                    ],
                );
            }

            $criadas++;
        }

        return $criadas;
    }

    /**
     * Mesmo caminho de CashFlowIndex::resolveSubcategoryId(): texto livre
     * vira subcategoria de verdade antes do commit, só pros itens que vão
     * ser importados de fato.
     */
    private function resolverNovasSubcategorias(): void
    {
        foreach ($this->aceitos as $i) {
            $novoNome = trim($this->novaSubcategoriaPorItem[$i] ?? '');
            $categoriaId = $this->categoriaPorItem[$i] ?? '';

            if ($novoNome === '' || $categoriaId === '') {
                continue;
            }

            $categoria = ExpenseCategory::query()->find($categoriaId);

            if ($categoria === null) {
                continue;
            }

            $this->subcategoriaPorItem[$i] = ExpenseSubcategory::createCustom($categoria, $novoNome)->id;
        }
    }

    /**
     * Cria a subcategoria assim que a pessoa sai do campo (wire:blur), em
     * vez de só no confirmar() — motivado por um caso real: extrato com
     * mais de um gasto pra uma subcategoria que ainda não existe. Sem
     * isso, a pessoa tinha que esperar confirmar a importação inteira pra
     * a subcategoria existir e poder escolhê-la nos outros itens; criando
     * na hora, ela já aparece no <select> de qualquer linha da mesma
     * categoria no próximo render.
     */
    public function criarSubcategoriaAgora(int $i): void
    {
        $novoNome = trim($this->novaSubcategoriaPorItem[$i] ?? '');
        $categoriaId = $this->categoriaPorItem[$i] ?? '';

        if ($novoNome === '' || $categoriaId === '') {
            return;
        }

        $categoria = ExpenseCategory::query()->find($categoriaId);

        if ($categoria === null) {
            return;
        }

        $this->subcategoriaPorItem[$i] = ExpenseSubcategory::createCustom($categoria, $novoNome)->id;
        $this->novaSubcategoriaPorItem[$i] = '';
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

    /**
     * Manda pra fila de novo — falha comum é a chave da API ficar sem
     * crédito no meio de um lote; o arquivo já está salvo, não precisa
     * reenviar. Só faz sentido pra quem falhou (Pending/Processing já
     * estão em andamento, Completed/Committed não têm erro pra corrigir).
     */
    public function reprocessar(string $id): void
    {
        $documento = DocumentUpload::findOrFail($id);

        if ($documento->processing_status !== ProcessingStatus::Failed) {
            return;
        }

        $documento->update([
            'processing_status' => ProcessingStatus::Pending,
            'error_message' => null,
        ]);

        if (filled(config('cerne.ai.api_key'))) {
            ProcessDocumentJob::dispatch($documento->id);
        }

        session()->flash('status', 'Documento na fila pra nova tentativa.');
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
