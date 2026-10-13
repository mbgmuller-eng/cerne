<?php

namespace App\Livewire\Consultant;

use App\Enums\CommissionType;
use App\Enums\InsuranceType;
use App\Enums\LeadActivityType;
use App\Enums\LeadKind;
use App\Enums\LeadLostReason;
use App\Enums\LeadQuoteStatus;
use App\Enums\LeadSource;
use App\Enums\LeadStage;
use App\Enums\PaymentFrequency;
use App\Models\Lead;
use App\Models\LeadQuote;
use App\Services\LeadService;
use App\Support\LeadQuoteFields;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * CRM do profissional: contatos que ainda não são clientes. A etapa que falta
 * antes de ConsultantInvite/ConsultantClient (ver LeadService) — quando um
 * lead avança, ele converte pro fluxo de convite que já existe, não vira
 * um cadastro paralelo.
 *
 * Dois funis na mesma tela: consultoria (reunião, proposta) e seguros (dados, cotações, negociação, emissão). No de
 * seguros cada lead guarda as cotações feitas (valor estimado, validade, simulação em PDF).
 */
#[Layout('components.layouts.app', ['wide' => true])]
class LeadsIndex extends Component
{
    use WithFileUploads;

    /** Qual funil o quadro mostra: 'consultoria' ou 'seguros'. Corretor só tem o de seguros. */
    #[Url]
    public string $aba = '';

    #[Url]
    public string $search = '';

    #[Url]
    public bool $showClosed = false;

    // -----------------------------------------------------------------
    // Formulário — Lead
    // -----------------------------------------------------------------

    public bool $showLeadForm = false;

    /** Nulo = criando; preenchido = editando este lead. */
    public ?string $editingLeadId = null;

    public string $leadKind = 'consulting';

    public string $leadName = '';

    public string $leadEmail = '';

    public string $leadPhone = '';

    public string $leadBirthDate = '';

    public string $leadSource = '';

    public string $leadSourceDetail = '';

    /** @var list<string> tipos de seguro de interesse (valores de InsuranceType) */
    public array $leadInterests = [];

    /** @var array<string, array<string, string>> tipo => campo => valor (ver LeadQuoteFields) */
    public array $leadQuoteData = [];

    public string $leadNotes = '';

    public string $leadNextActionAt = '';

    public ?string $confirmingDeleteLeadId = null;

    // -----------------------------------------------------------------
    // Registrar contato
    // -----------------------------------------------------------------

    public ?string $loggingActivityLeadId = null;

    public string $activityType = 'call';

    public string $activityDescription = '';

    public string $activityOccurredAt = '';

    // -----------------------------------------------------------------
    // Marcar perdido
    // -----------------------------------------------------------------

    public ?string $markingLostLeadId = null;

    public string $lostReason = '';

    public string $lostCategory = '';

    // -----------------------------------------------------------------
    // Converter em cliente
    // -----------------------------------------------------------------

    public ?string $lastConvertedLeadId = null;

    public ?string $lastInviteLink = null;

    // -----------------------------------------------------------------
    // Cotações e anexos
    // -----------------------------------------------------------------

    public bool $showQuotes = false;

    public ?string $quotesLeadId = null;

    public bool $showQuoteForm = false;

    public ?string $editingQuoteId = null;

    public string $quoteType = 'vida';

    public string $quoteInsurer = '';

    public string $quoteProduct = '';

    public string $quoteReference = '';

    public string $quotePremium = '';

    public string $quoteFrequency = 'monthly';

    public string $quoteCoverageAmount = '';

    /** @var list<array{name: string, value: string, deductible: string}> */
    public array $quoteCoverages = [];

    public string $quoteCommissionType = 'percent';

    /** O número da comissão: percentual ou valor em reais, conforme quoteCommissionType. */
    public string $quoteCommission = '';

    public string $quoteValidUntil = '';

    public string $quoteStatus = 'draft';

    public string $quoteNotes = '';

    /** Cotação à qual o próximo arquivo será anexado; vazio = o contato em geral. */
    public string $attachTarget = '';

    public $anexo = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isLinkedProfessional(), 403);

        // Corretor trabalha só com seguros; o consultor abre na consultoria.
        if (auth()->user()->isBroker() || ! in_array($this->aba, ['consultoria', 'seguros'], true)) {
            $this->aba = auth()->user()->isBroker() ? 'seguros' : 'consultoria';
        }
    }

    public function setAba(string $aba): void
    {
        if (auth()->user()->isBroker()) {
            return;
        }

        $this->aba = $aba === 'seguros' ? 'seguros' : 'consultoria';
    }

    private function kind(): LeadKind
    {
        return $this->aba === 'seguros' ? LeadKind::Insurance : LeadKind::Consulting;
    }

    private function leadDoUsuario(string $id): Lead
    {
        return Lead::query()->where('consultant_id', auth()->id())->findOrFail($id);
    }

    // -----------------------------------------------------------------
    // Formulário
    // -----------------------------------------------------------------

    public function toggleLeadForm(): void
    {
        $this->showLeadForm = ! $this->showLeadForm;

        if ($this->showLeadForm) {
            $this->resetLeadForm();
            // O contato novo nasce no funil que está aberto.
            $this->leadKind = $this->kind()->value;
        }
    }

    public function editLead(string $id): void
    {
        $lead = $this->leadDoUsuario($id);

        $this->resetLeadForm();
        $this->editingLeadId = $lead->id;
        $this->leadKind = $lead->kind->value;
        $this->leadName = $lead->name;
        $this->leadEmail = $lead->email ?? '';
        $this->leadPhone = $lead->phone ?? '';
        $this->leadBirthDate = $lead->birth_date?->toDateString() ?? '';
        $this->leadSource = $lead->source?->value ?? '';
        $this->leadSourceDetail = $lead->source_detail ?? '';
        $this->leadInterests = array_values($lead->interests ?? []);
        $this->leadQuoteData = $lead->quote_data ?? [];
        $this->leadNotes = $lead->notes ?? '';
        $this->leadNextActionAt = $lead->next_action_at?->format('Y-m-d\TH:i') ?? '';
        $this->showLeadForm = true;
    }

    public function updatedLeadKind(): void
    {
        // Trocar para consultoria descarta o que era só de seguro.
        if ($this->leadKind !== LeadKind::Insurance->value) {
            $this->leadInterests = [];
            $this->leadQuoteData = [];
        }
    }

    public function saveLead(LeadService $service): void
    {
        $corretor = auth()->user()->isBroker();

        $data = $this->validate([
            'leadKind' => ['required', Rule::enum(LeadKind::class), $corretor ? Rule::in([LeadKind::Insurance->value]) : 'string'],
            'leadName' => ['required', 'string', 'max:255'],
            'leadEmail' => ['nullable', 'email', 'max:255'],
            'leadPhone' => ['nullable', 'string', 'max:30'],
            'leadBirthDate' => ['nullable', 'date', 'before:today'],
            'leadSource' => ['nullable', Rule::enum(LeadSource::class)],
            'leadSourceDetail' => ['nullable', 'string', 'max:255'],
            'leadInterests' => ['array'],
            'leadInterests.*' => [Rule::enum(InsuranceType::class)],
            'leadNotes' => ['nullable', 'string'],
            'leadNextActionAt' => ['nullable', 'date'],
        ], attributes: [
            'leadKind' => 'funil',
            'leadName' => 'nome',
            'leadEmail' => 'e-mail',
            'leadPhone' => 'telefone',
            'leadBirthDate' => 'data de nascimento',
            'leadSource' => 'origem',
            'leadSourceDetail' => 'quem indicou',
            'leadNextActionAt' => 'próximo contato',
        ]);

        $seguros = $data['leadKind'] === LeadKind::Insurance->value;
        $interesses = $seguros ? array_values(array_unique($data['leadInterests'])) : [];
        $dadosDeCotacao = LeadQuoteFields::clean($interesses, $this->leadQuoteData);

        $dados = [
            'consultant_id' => auth()->id(),
            'kind' => $data['leadKind'],
            'name' => $data['leadName'],
            'email' => $data['leadEmail'] !== '' ? $data['leadEmail'] : null,
            'phone' => $data['leadPhone'] !== '' ? $data['leadPhone'] : null,
            'birth_date' => $data['leadBirthDate'] !== '' ? $data['leadBirthDate'] : null,
            'source' => $data['leadSource'] !== '' ? $data['leadSource'] : null,
            'source_detail' => $data['leadSourceDetail'] !== '' ? $data['leadSourceDetail'] : null,
            'interests' => $interesses === [] ? null : $interesses,
            'quote_data' => $dadosDeCotacao === [] ? null : $dadosDeCotacao,
            'notes' => $data['leadNotes'] !== '' ? $data['leadNotes'] : null,
            'next_action_at' => $data['leadNextActionAt'] !== '' ? CarbonImmutable::parse($data['leadNextActionAt']) : null,
        ];

        if ($this->editingLeadId !== null) {
            // consultant_id não muda numa edição — só na criação.
            unset($dados['consultant_id']);
            $lead = $this->leadDoUsuario($this->editingLeadId);

            // Mudar de funil leva o lead para a primeira etapa do funil novo (as etapas são outras).
            if ($lead->kind->value !== $data['leadKind']) {
                $dados['stage'] = LeadStage::NewContact->value;
            }

            $service->update($lead, $dados);
            session()->flash('status', 'Contato atualizado.');
        } else {
            $service->create($dados);
            session()->flash('status', 'Contato cadastrado.');
        }

        $this->resetLeadForm();
        $this->showLeadForm = false;
    }

    private function resetLeadForm(): void
    {
        $this->reset(
            'leadName', 'leadEmail', 'leadPhone', 'leadBirthDate', 'leadSource', 'leadSourceDetail', 'leadInterests',
            'leadQuoteData', 'leadNotes', 'leadNextActionAt', 'editingLeadId',
        );
        $this->leadKind = $this->kind()->value;
        $this->resetErrorBag();
    }

    public function confirmDeleteLead(string $id): void
    {
        $this->confirmingDeleteLeadId = $id;
    }

    public function cancelDeleteLead(): void
    {
        $this->confirmingDeleteLeadId = null;
    }

    public function deleteLead(string $id, LeadService $service): void
    {
        // Hard delete de verdade: lead que nunca virou cliente não carrega
        // dado financeiro nenhum atrás — diferente de FixedBill/RecurringIncome,
        // não há histórico pra preservar (atividades e cotações caem junto via cascade; os arquivos
        // são apagados do disco pelo serviço).
        $service->delete($this->leadDoUsuario($id));

        if ($this->quotesLeadId === $id) {
            $this->closeQuotes();
        }

        $this->confirmingDeleteLeadId = null;
        session()->flash('status', 'Contato excluído.');
    }

    // -----------------------------------------------------------------
    // Registrar contato
    // -----------------------------------------------------------------

    public function toggleLogActivity(?string $id = null): void
    {
        $this->loggingActivityLeadId = $this->loggingActivityLeadId === $id ? null : $id;
        $this->reset('activityDescription');
        $this->activityType = 'call';
        $this->activityOccurredAt = CarbonImmutable::now()->format('Y-m-d\TH:i');
        $this->resetErrorBag();
    }

    public function logActivity(LeadService $service): void
    {
        $lead = $this->leadDoUsuario((string) $this->loggingActivityLeadId);

        $data = $this->validate([
            'activityType' => ['required', Rule::enum(LeadActivityType::class)],
            'activityDescription' => ['nullable', 'string'],
            'activityOccurredAt' => ['required', 'date'],
        ], attributes: [
            'activityType' => 'tipo',
            'activityOccurredAt' => 'data',
        ]);

        $service->logActivity(
            $lead,
            LeadActivityType::from($data['activityType']),
            $data['activityDescription'] !== '' ? $data['activityDescription'] : null,
            CarbonImmutable::parse($data['activityOccurredAt']),
            auth()->id(),
        );

        $this->loggingActivityLeadId = null;
        session()->flash('status', 'Contato registrado.');
    }

    // -----------------------------------------------------------------
    // Marcar perdido
    // -----------------------------------------------------------------

    public function toggleMarkLost(?string $id = null): void
    {
        $this->markingLostLeadId = $this->markingLostLeadId === $id ? null : $id;
        $this->reset('lostReason', 'lostCategory');
        $this->resetErrorBag();
    }

    public function markLost(LeadService $service): void
    {
        $lead = $this->leadDoUsuario((string) $this->markingLostLeadId);
        $seguros = $lead->kind === LeadKind::Insurance;

        // Seguros: a categoria é obrigatória (é o que se conta depois) e a anotação é opcional. Consultoria segue pedindo
        // o motivo escrito, como sempre.
        $data = $this->validate([
            'lostCategory' => [$seguros ? 'required' : 'nullable', Rule::enum(LeadLostReason::class)],
            'lostReason' => [$seguros ? 'nullable' : 'required', 'string', 'max:255'],
        ], attributes: ['lostCategory' => 'motivo', 'lostReason' => 'motivo']);

        $service->markLost(
            $lead,
            $data['lostReason'] !== '' ? $data['lostReason'] : null,
            $data['lostCategory'] !== '' ? LeadLostReason::from($data['lostCategory']) : null,
        );

        $this->markingLostLeadId = null;
        session()->flash('status', 'Contato marcado como perdido.');
    }

    // -----------------------------------------------------------------
    // Converter em cliente
    // -----------------------------------------------------------------

    public function convertLead(string $id, LeadService $service): void
    {
        $lead = $this->leadDoUsuario($id);

        if (blank($lead->email)) {
            $this->addError('convert', 'Este contato não tem e-mail cadastrado — edite o contato antes de converter.');

            return;
        }

        $this->lastConvertedLeadId = $lead->id;
        $this->lastInviteLink = $service->convert($lead, auth()->user());
        session()->flash('status', 'Convite enviado — o lead virou cliente em potencial.');
    }

    // -----------------------------------------------------------------
    // Mover de coluna
    // -----------------------------------------------------------------

    /** Sem arrastar-e-soltar de propósito — funciona igual no celular. */
    public function advanceStage(string $id, LeadService $service): void
    {
        $service->moveStage($this->leadDoUsuario($id), 1);
    }

    public function regressStage(string $id, LeadService $service): void
    {
        $service->moveStage($this->leadDoUsuario($id), -1);
    }

    // -----------------------------------------------------------------
    // Cotações
    // -----------------------------------------------------------------

    public function openQuotes(string $leadId): void
    {
        $this->quotesLeadId = $this->leadDoUsuario($leadId)->id;
        $this->showQuotes = true;
        $this->showQuoteForm = false;
        $this->attachTarget = '';
        $this->reset('anexo');
        $this->resetErrorBag();
    }

    public function closeQuotes(): void
    {
        $this->showQuotes = false;
        $this->showQuoteForm = false;
        $this->quotesLeadId = null;
    }

    public function newQuote(): void
    {
        $lead = $this->leadDoUsuario((string) $this->quotesLeadId);

        $this->resetQuoteForm();
        // O tipo mais provável: o primeiro dos interesses do lead.
        $this->quoteType = $lead->interests[0] ?? InsuranceType::Vida->value;
        $this->showQuoteForm = true;
    }

    public function editQuote(string $quoteId): void
    {
        $quote = $this->minhaCotacao($quoteId);

        $this->resetQuoteForm();
        $this->editingQuoteId = $quote->id;
        $this->quoteType = $quote->insurance_type->value;
        $this->quoteInsurer = $quote->insurer_name;
        $this->quoteProduct = $quote->product ?? '';
        $this->quoteReference = $quote->reference ?? '';
        $this->quotePremium = (string) ($quote->premium ?? '');
        $this->quoteFrequency = $quote->payment_frequency->value;
        $this->quoteCoverageAmount = (string) ($quote->coverage_amount ?? '');
        $this->quoteCoverages = array_map(fn (array $c) => [
            'name' => (string) ($c['name'] ?? ''),
            'value' => (string) ($c['value'] ?? ''),
            'deductible' => (string) ($c['deductible'] ?? ''),
        ], $quote->coverages ?? []);
        $this->quoteCommissionType = $quote->commission_type?->value ?? CommissionType::Percent->value;
        $this->quoteCommission = (string) ($this->quoteCommissionType === 'amount' ? $quote->commission_amount : $quote->commission_percent) ?: '';
        $this->quoteValidUntil = $quote->valid_until?->toDateString() ?? '';
        $this->quoteStatus = $quote->status->value;
        $this->quoteNotes = $quote->notes ?? '';
        $this->showQuoteForm = true;
    }

    public function cancelQuoteForm(): void
    {
        $this->showQuoteForm = false;
        $this->resetQuoteForm();
    }

    public function addQuoteCoverage(): void
    {
        $this->quoteCoverages[] = ['name' => '', 'value' => '', 'deductible' => ''];
    }

    public function removeQuoteCoverage(int $i): void
    {
        unset($this->quoteCoverages[$i]);
        $this->quoteCoverages = array_values($this->quoteCoverages);
    }

    public function saveQuote(LeadService $service): void
    {
        $lead = $this->leadDoUsuario((string) $this->quotesLeadId);

        $this->validate([
            'quoteType' => ['required', Rule::enum(InsuranceType::class)],
            'quoteInsurer' => ['required', 'string', 'max:255'],
            'quoteProduct' => ['nullable', 'string', 'max:255'],
            'quoteReference' => ['nullable', 'string', 'max:100'],
            'quotePremium' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'quoteFrequency' => ['required', Rule::enum(PaymentFrequency::class)],
            'quoteCoverageAmount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'quoteCoverages.*.name' => ['nullable', 'string', 'max:255'],
            'quoteCoverages.*.value' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'quoteCoverages.*.deductible' => ['nullable', 'string', 'max:255'],
            'quoteCommissionType' => ['required', Rule::enum(CommissionType::class)],
            'quoteCommission' => ['nullable', 'numeric', 'min:0', $this->quoteCommissionType === 'percent' ? 'max:100' : 'max:9999999999'],
            'quoteValidUntil' => ['nullable', 'date'],
            'quoteStatus' => ['required', Rule::enum(LeadQuoteStatus::class)],
            'quoteNotes' => ['nullable', 'string', 'max:2000'],
        ], attributes: [
            'quoteType' => 'tipo de seguro',
            'quoteInsurer' => 'seguradora',
            'quotePremium' => 'prêmio',
            'quoteFrequency' => 'periodicidade',
            'quoteCoverageAmount' => 'capital',
            'quoteCommission' => 'comissão',
            'quoteValidUntil' => 'validade',
        ]);

        $quote = $this->editingQuoteId !== null ? $this->minhaCotacao($this->editingQuoteId) : null;

        $service->saveQuote($lead, [
            'insurance_type' => $this->quoteType,
            'insurer_name' => $this->quoteInsurer,
            'product' => $this->quoteProduct,
            'reference' => $this->quoteReference,
            'premium' => $this->quotePremium,
            'payment_frequency' => $this->quoteFrequency,
            'coverage_amount' => $this->quoteCoverageAmount,
            'coverages' => $this->quoteCoverages,
            'commission_type' => $this->quoteCommissionType,
            'commission_percent' => $this->quoteCommission,
            'commission_amount' => $this->quoteCommission,
            'valid_until' => $this->quoteValidUntil,
            'status' => $this->quoteStatus,
            'notes' => $this->quoteNotes,
        ], $quote, auth()->id());

        $this->showQuoteForm = false;
        $this->resetQuoteForm();
        session()->flash('status', $quote === null ? 'Cotação cadastrada.' : 'Cotação atualizada.');
    }

    public function setQuoteStatus(string $quoteId, string $status, LeadService $service): void
    {
        $situacao = LeadQuoteStatus::tryFrom($status);

        if ($situacao !== null) {
            $service->setQuoteStatus($this->minhaCotacao($quoteId), $situacao);
        }
    }

    public function deleteQuote(string $quoteId, LeadService $service): void
    {
        $service->deleteQuote($this->minhaCotacao($quoteId));
        session()->flash('status', 'Cotação excluída.');
    }

    private function resetQuoteForm(): void
    {
        $this->reset(
            'editingQuoteId', 'quoteInsurer', 'quoteProduct', 'quoteReference', 'quotePremium', 'quoteCoverageAmount',
            'quoteCoverages', 'quoteCommission', 'quoteValidUntil', 'quoteNotes',
        );
        $this->quoteCommissionType = CommissionType::Percent->value;
        $this->quoteType = InsuranceType::Vida->value;
        $this->quoteFrequency = PaymentFrequency::Monthly->value;
        $this->quoteStatus = LeadQuoteStatus::Draft->value;
        $this->resetErrorBag();
    }

    /** Só cotação do lead aberto na janela e de um lead do próprio profissional. */
    private function minhaCotacao(string $id): LeadQuote
    {
        $lead = $this->leadDoUsuario((string) $this->quotesLeadId);

        return $lead->quotes()->findOrFail($id);
    }

    // -----------------------------------------------------------------
    // Anexos
    // -----------------------------------------------------------------

    public function chooseAttachTarget(string $target): void
    {
        $this->attachTarget = $target;
        $this->reset('anexo');
        $this->resetErrorBag('anexo');
    }

    /** O arquivo é guardado assim que a pessoa o escolhe (Livewire chama este gancho depois do upload). */
    public function updatedAnexo(LeadService $service): void
    {
        $lead = $this->leadDoUsuario((string) $this->quotesLeadId);

        $this->validate([
            'anexo' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.config('cerne.lead_files.max_kb')],
        ], attributes: ['anexo' => 'arquivo']);

        // 'geral' = arquivo do contato; senão é o id da cotação a que a simulação pertence.
        $quote = ! in_array($this->attachTarget, ['', 'geral'], true) ? $this->minhaCotacao($this->attachTarget) : null;

        try {
            $service->attachFile($lead, $this->anexo, $quote, auth()->id());
        } catch (InvalidArgumentException $e) {
            $this->addError('anexo', $e->getMessage());
            $this->reset('anexo');

            return;
        }

        $this->reset('anexo');
        $this->attachTarget = '';
        session()->flash('status', 'Arquivo anexado.');
    }

    public function deleteAttachment(string $id, LeadService $service): void
    {
        // Só anexo do lead aberto na janela, e esse lead tem de ser do próprio profissional.
        $anexo = $this->leadDoUsuario((string) $this->quotesLeadId)->attachments()->findOrFail($id);

        $service->deleteAttachment($anexo);
        session()->flash('status', 'Arquivo removido.');
    }

    // -----------------------------------------------------------------
    // Dados
    // -----------------------------------------------------------------

    private function baseQuery(): Builder
    {
        $query = Lead::query()
            ->where('consultant_id', auth()->id())
            ->where('kind', $this->kind()->value)
            ->with([
                'activities' => fn ($q) => $q->latest('occurred_at')->limit(3),
                'quotes',
            ])
            ->withCount('attachments');

        if (trim($this->search) !== '') {
            $busca = mb_strtolower(trim($this->search));
            $query->where(fn ($q) => $q
                ->whereRaw('LOWER(name) LIKE ?', ["%{$busca}%"])
                ->orWhereRaw('LOWER(email) LIKE ?', ["%{$busca}%"]));
        }

        return $query;
    }

    /** @return Collection<string, Collection<int, Lead>> estágio (value) => leads, só o pipeline aberto do funil escolhido */
    public function getLeadsByStageProperty(): Collection
    {
        $leads = $this->baseQuery()
            ->whereIn('stage', array_map(fn (LeadStage $s) => $s->value, LeadStage::openFor($this->kind())))
            ->orderByRaw('next_action_at IS NULL, next_action_at')
            ->get();

        return $leads->groupBy(fn (Lead $lead) => $lead->stage->value);
    }

    /** @return Collection<int, Lead> */
    public function getClosedLeadsProperty(): Collection
    {
        if (! $this->showClosed) {
            return collect();
        }

        return $this->baseQuery()
            ->whereIn('stage', [LeadStage::Converted->value, LeadStage::Lost->value])
            ->latest('updated_at')
            ->get();
    }

    /** @return array{consultoria: int, seguros: int} leads em andamento em cada funil, para os números das abas */
    public function getAbaCountsProperty(): array
    {
        $porKind = Lead::query()
            ->where('consultant_id', auth()->id())
            ->open()
            ->selectRaw('kind, COUNT(*) AS total')
            ->groupBy('kind')
            ->pluck('total', 'kind');

        return [
            'consultoria' => (int) ($porKind[LeadKind::Consulting->value] ?? 0),
            'seguros' => (int) ($porKind[LeadKind::Insurance->value] ?? 0),
        ];
    }

    /** @return ?array{premio_anual: string, comissao: string, com_cotacao: int, sem_cotacao: int} só no funil de seguros */
    public function getFunnelValueProperty(): ?array
    {
        if ($this->kind() !== LeadKind::Insurance) {
            return null;
        }

        return app(LeadService::class)->funnelValue($this->leadsByStage->flatten(1));
    }

    /** O lead aberto na janela de cotações, com tudo que ela mostra. */
    public function getQuotesLeadProperty(): ?Lead
    {
        if ($this->quotesLeadId === null) {
            return null;
        }

        return Lead::query()
            ->where('consultant_id', auth()->id())
            ->with(['quotes.attachments', 'attachments' => fn ($q) => $q->whereNull('lead_quote_id')])
            ->find($this->quotesLeadId);
    }

    public function render()
    {
        return view('livewire.consultant.leads-index', [
            'kind' => $this->kind(),
            'openStages' => LeadStage::openFor($this->kind()),
            'leadsByStage' => $this->leadsByStage,
            'closedLeads' => $this->closedLeads,
            'activityTypes' => LeadActivityType::options(),
            'abaCounts' => $this->abaCounts,
            'funnelValue' => $this->funnelValue,
            'quotesLead' => $this->quotesLead,
            'kinds' => LeadKind::options(),
            'sources' => LeadSource::options(),
            'lostReasons' => LeadLostReason::options(),
            'insuranceTypes' => InsuranceType::options(),
            'quoteStatuses' => LeadQuoteStatus::options(),
            'frequencies' => PaymentFrequency::options(),
            'quoteFields' => LeadQuoteFields::all(),
            'ehCorretor' => auth()->user()->isBroker(),
        ]);
    }
}
