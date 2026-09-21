<?php

namespace App\Livewire\Consultant;

use App\Enums\LeadActivityType;
use App\Enums\LeadStage;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Services\LeadService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * CRM do consultor: contatos que ainda não são clientes. A etapa que falta
 * antes de ConsultantInvite/ConsultantClient (ver LeadService) — quando um
 * lead avança, ele converte pro fluxo de convite que já existe, não vira
 * um cadastro paralelo.
 */
#[Layout('components.layouts.app')]
class LeadsIndex extends Component
{
    /**
     * Ordem das colunas do quadro — Convertido/Perdido ficam de fora (são
     * "caso encerrado", não pipeline ativo) e viram uma lista à parte, ver
     * $showClosed.
     *
     * @var list<LeadStage>
     */
    private const OPEN_STAGES = [
        LeadStage::NewContact,
        LeadStage::MeetingScheduled,
        LeadStage::ProposalSent,
    ];

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

    public string $leadName = '';

    public string $leadEmail = '';

    public string $leadPhone = '';

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

    // -----------------------------------------------------------------
    // Converter em cliente
    // -----------------------------------------------------------------

    public ?string $lastConvertedLeadId = null;

    public ?string $lastInviteLink = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isLinkedProfessional(), 403);
    }

    // -----------------------------------------------------------------
    // Formulário
    // -----------------------------------------------------------------

    public function toggleLeadForm(): void
    {
        $this->showLeadForm = ! $this->showLeadForm;

        if ($this->showLeadForm) {
            $this->resetLeadForm();
        }
    }

    public function editLead(string $id): void
    {
        $lead = Lead::query()->where('consultant_id', auth()->id())->findOrFail($id);

        $this->editingLeadId = $lead->id;
        $this->leadName = $lead->name;
        $this->leadEmail = $lead->email ?? '';
        $this->leadPhone = $lead->phone ?? '';
        $this->leadNotes = $lead->notes ?? '';
        $this->leadNextActionAt = $lead->next_action_at?->format('Y-m-d\TH:i') ?? '';
        $this->showLeadForm = true;
    }

    public function saveLead(LeadService $service): void
    {
        $data = $this->validate([
            'leadName' => ['required', 'string', 'max:255'],
            'leadEmail' => ['nullable', 'email', 'max:255'],
            'leadPhone' => ['nullable', 'string', 'max:30'],
            'leadNotes' => ['nullable', 'string'],
            'leadNextActionAt' => ['nullable', 'date'],
        ], attributes: [
            'leadName' => 'nome',
            'leadEmail' => 'e-mail',
            'leadPhone' => 'telefone',
            'leadNextActionAt' => 'próximo contato',
        ]);

        $dados = [
            'consultant_id' => auth()->id(),
            'name' => $data['leadName'],
            'email' => $data['leadEmail'] !== '' ? $data['leadEmail'] : null,
            'phone' => $data['leadPhone'] !== '' ? $data['leadPhone'] : null,
            'notes' => $data['leadNotes'] !== '' ? $data['leadNotes'] : null,
            'next_action_at' => $data['leadNextActionAt'] !== '' ? CarbonImmutable::parse($data['leadNextActionAt']) : null,
        ];

        if ($this->editingLeadId !== null) {
            // consultant_id não muda numa edição — só na criação.
            unset($dados['consultant_id']);
            $service->update(Lead::query()->where('consultant_id', auth()->id())->findOrFail($this->editingLeadId), $dados);
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
        $this->reset('leadName', 'leadEmail', 'leadPhone', 'leadNotes', 'leadNextActionAt', 'editingLeadId');
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

    public function deleteLead(string $id): void
    {
        // Hard delete de verdade: lead que nunca virou cliente não carrega
        // dado financeiro nenhum atrás — diferente de FixedBill/RecurringIncome,
        // não há histórico pra preservar (lead_activities cai junto via cascade).
        Lead::query()->where('consultant_id', auth()->id())->findOrFail($id)->delete();

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
        $lead = Lead::query()->where('consultant_id', auth()->id())->findOrFail($this->loggingActivityLeadId);

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
        $this->reset('lostReason');
        $this->resetErrorBag();
    }

    public function markLost(LeadService $service): void
    {
        $lead = Lead::query()->where('consultant_id', auth()->id())->findOrFail($this->markingLostLeadId);

        $data = $this->validate([
            'lostReason' => ['required', 'string', 'max:255'],
        ], attributes: ['lostReason' => 'motivo']);

        $service->markLost($lead, $data['lostReason']);

        $this->markingLostLeadId = null;
        session()->flash('status', 'Contato marcado como perdido.');
    }

    // -----------------------------------------------------------------
    // Converter em cliente
    // -----------------------------------------------------------------

    public function convertLead(string $id, LeadService $service): void
    {
        $lead = Lead::query()->where('consultant_id', auth()->id())->findOrFail($id);

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
        $lead = Lead::query()->where('consultant_id', auth()->id())->findOrFail($id);
        $indice = array_search($lead->stage, self::OPEN_STAGES, true);

        if ($indice === false || $indice >= count(self::OPEN_STAGES) - 1) {
            return;
        }

        $service->update($lead, ['stage' => self::OPEN_STAGES[$indice + 1]->value]);
    }

    public function regressStage(string $id, LeadService $service): void
    {
        $lead = Lead::query()->where('consultant_id', auth()->id())->findOrFail($id);
        $indice = array_search($lead->stage, self::OPEN_STAGES, true);

        if ($indice === false || $indice <= 0) {
            return;
        }

        $service->update($lead, ['stage' => self::OPEN_STAGES[$indice - 1]->value]);
    }

    // -----------------------------------------------------------------
    // Dados
    // -----------------------------------------------------------------

    private function baseQuery(): Builder
    {
        $query = Lead::query()
            ->where('consultant_id', auth()->id())
            ->with(['activities' => fn ($q) => $q->latest('occurred_at')->limit(3)]);

        if (trim($this->search) !== '') {
            $busca = mb_strtolower(trim($this->search));
            $query->where(fn ($q) => $q
                ->whereRaw('LOWER(name) LIKE ?', ["%{$busca}%"])
                ->orWhereRaw('LOWER(email) LIKE ?', ["%{$busca}%"]));
        }

        return $query;
    }

    /** @return Collection<string, Collection<int, Lead>> estágio (value) => leads, só o pipeline aberto */
    public function getLeadsByStageProperty(): Collection
    {
        $leads = $this->baseQuery()
            ->whereIn('stage', array_map(fn (LeadStage $s) => $s->value, self::OPEN_STAGES))
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

    public function render()
    {
        return view('livewire.consultant.leads-index', [
            'openStages' => self::OPEN_STAGES,
            'leadsByStage' => $this->leadsByStage,
            'closedLeads' => $this->closedLeads,
            'activityTypes' => LeadActivityType::options(),
        ]);
    }
}
