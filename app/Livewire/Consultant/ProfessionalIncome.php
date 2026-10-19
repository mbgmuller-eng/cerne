<?php

namespace App\Livewire\Consultant;

use App\Enums\BillingPeriod;
use App\Enums\IncomeSource;
use App\Enums\IncomeStatus;
use App\Models\IncomeEntry;
use App\Services\ProfessionalIncomeService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Recebimentos do profissional (consultor ou corretor): quanto está entrando por mês, de onde e de quem. Só os dados do
 * próprio profissional (ver ProfessionalIncomeService). Os contratos de honorário fixo são do consultor.
 */
#[Layout('components.layouts.app')]
class ProfessionalIncome extends Component
{
    #[Url]
    public int $ano = 0;

    #[Url]
    public int $mes = 0;

    #[Url]
    public string $aba = 'recebimentos';

    public ?string $aviso = null;

    // Lançamento (criar / editar)
    public bool $showEntryForm = false;

    public ?string $entryId = null;

    public string $entrySource = 'other';

    public string $entryProfileId = '';

    public string $entryDescription = '';

    public string $entryDueOn = '';

    public string $entryAmount = '';

    public string $entryStatus = 'expected';

    public string $entryReceivedOn = '';

    public string $entryReceivedAmount = '';

    // Dar baixa
    public bool $showReceiveForm = false;

    public ?string $receivingId = null;

    public string $receiveAmount = '';

    public string $receiveOn = '';

    // Contrato de honorário
    public bool $showAgreementForm = false;

    public ?string $agreementId = null;

    public string $agreementProfileId = '';

    public string $agreementAmount = '';

    public string $agreementPeriod = 'monthly';

    public string $agreementStartsOn = '';

    public string $agreementEndsOn = '';

    public string $agreementNotes = '';

    /** @var array<string, string>|null */
    private ?array $clientesCache = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isLinkedProfessional(), 403);

        $hoje = CarbonImmutable::today();
        $this->ano = $this->ano >= 2000 && $this->ano <= 2100 ? $this->ano : (int) $hoje->format('Y');
        $this->mes = $this->mes >= 1 && $this->mes <= 12 ? $this->mes : (int) $hoje->format('n');

        if (! in_array($this->aba, ['recebimentos', 'contratos', 'regras'], true) || ($this->aba === 'contratos' && ! auth()->user()->isConsultant())) {
            $this->aba = 'recebimentos';
        }
    }

    public function setAba(string $aba): void
    {
        if ($aba === 'recebimentos' || $aba === 'regras' || ($aba === 'contratos' && auth()->user()->isConsultant())) {
            $this->aba = $aba;
        }
    }

    public function previousMonth(): void
    {
        $this->goTo($this->referencia()->subMonthsNoOverflow());
    }

    public function nextMonth(): void
    {
        $this->goTo($this->referencia()->addMonthsNoOverflow());
    }

    public function thisMonth(): void
    {
        $this->goTo(CarbonImmutable::today());
    }

    // --- Lançamentos ---------------------------------------------------------------------------------------------

    public function openEntryForm(?string $id = null): void
    {
        $this->resetErrorBag();
        $this->reset('entryId', 'entryProfileId', 'entryDescription', 'entryAmount', 'entryReceivedOn', 'entryReceivedAmount');
        $this->entrySource = IncomeSource::Other->value;
        $this->entryStatus = IncomeStatus::Expected->value;

        $hoje = CarbonImmutable::today();
        $this->entryDueOn = ($hoje->year === $this->ano && $hoje->month === $this->mes ? $hoje : $this->referencia())->toDateString();

        if ($id !== null) {
            $entry = IncomeEntry::query()->ownedBy(auth()->user())->whereNull('fee_agreement_id')->whereNull('origin_key')->findOrFail($id);
            $this->entryId = $entry->id;
            $this->entrySource = $entry->source->value;
            $this->entryProfileId = (string) $entry->profile_id;
            $this->entryDescription = (string) $entry->description;
            $this->entryDueOn = $entry->due_on->toDateString();
            $this->entryAmount = (string) $entry->amount;
            $this->entryStatus = $entry->status === IncomeStatus::Received ? 'received' : 'expected';
            $this->entryReceivedOn = (string) $entry->received_on?->toDateString();
            $this->entryReceivedAmount = (string) $entry->received_amount;
        }

        $this->showEntryForm = true;
    }

    public function saveEntry(ProfessionalIncomeService $service): void
    {
        $dados = $this->validate([
            'entrySource' => ['required', Rule::in(array_keys(IncomeSource::options()))],
            'entryProfileId' => ['nullable', Rule::in(array_keys($this->clientes()))],
            'entryDescription' => ['nullable', 'string', 'max:255'],
            'entryDueOn' => ['required', 'date'],
            'entryAmount' => ['required', 'numeric', 'min:0.01', 'max:999999999'],
            'entryStatus' => ['required', Rule::in(['expected', 'received'])],
            'entryReceivedOn' => ['nullable', 'date'],
            'entryReceivedAmount' => ['nullable', 'numeric', 'min:0.01', 'max:999999999'],
        ], attributes: [
            'entrySource' => 'origem', 'entryProfileId' => 'cliente', 'entryDescription' => 'descrição', 'entryDueOn' => 'data',
            'entryAmount' => 'valor', 'entryStatus' => 'situação', 'entryReceivedOn' => 'data do recebimento', 'entryReceivedAmount' => 'valor recebido',
        ]);

        $entry = $service->saveEntry(auth()->user(), $this->entryId, [
            'source' => $dados['entrySource'], 'profile_id' => $dados['entryProfileId'] ?? null, 'description' => $dados['entryDescription'] ?? null,
            'due_on' => $dados['entryDueOn'], 'amount' => $dados['entryAmount'], 'status' => $dados['entryStatus'],
            'received_on' => $dados['entryReceivedOn'] ?? null, 'received_amount' => $dados['entryReceivedAmount'] ?? null,
        ]);

        $this->showEntryForm = false;
        $this->goTo($entry->due_on);
        $this->aviso = 'Lançamento salvo.';
    }

    public function deleteEntry(ProfessionalIncomeService $service, string $id): void
    {
        $this->aviso = $service->delete(auth()->user(), $id) ? 'Lançamento excluído.' : null;
    }

    public function openReceive(string $id): void
    {
        $entry = IncomeEntry::query()->ownedBy(auth()->user())->findOrFail($id);
        $this->resetErrorBag();
        $this->receivingId = $entry->id;
        $this->receiveAmount = (string) $entry->amount;
        $this->receiveOn = CarbonImmutable::today()->toDateString();
        $this->showReceiveForm = true;
    }

    public function confirmReceive(ProfessionalIncomeService $service): void
    {
        $dados = $this->validate([
            'receiveAmount' => ['required', 'numeric', 'min:0.01', 'max:999999999'],
            'receiveOn' => ['required', 'date'],
        ], attributes: ['receiveAmount' => 'valor recebido', 'receiveOn' => 'data do recebimento']);

        $service->markReceived(auth()->user(), (string) $this->receivingId, $dados['receiveAmount'], $dados['receiveOn']);

        $this->showReceiveForm = false;
        $this->aviso = 'Recebimento registrado.';
    }

    public function reopenEntry(ProfessionalIncomeService $service, string $id): void
    {
        $service->reopen(auth()->user(), $id);
        $this->aviso = 'Voltou para previsto.';
    }

    public function cancelEntry(ProfessionalIncomeService $service, string $id): void
    {
        $service->cancel(auth()->user(), $id);
        $this->aviso = 'Recebimento cancelado.';
    }

    // --- Contratos de honorário (consultor) ------------------------------------------------------------------------

    public function openAgreementForm(?string $id = null): void
    {
        abort_unless(auth()->user()->isConsultant(), 403);

        $this->resetErrorBag();
        $this->reset('agreementId', 'agreementProfileId', 'agreementAmount', 'agreementEndsOn', 'agreementNotes');
        $this->agreementPeriod = BillingPeriod::Monthly->value;
        $this->agreementStartsOn = CarbonImmutable::today()->startOfMonth()->toDateString();

        if ($id !== null) {
            $contrato = app(ProfessionalIncomeService::class)->agreements(auth()->user())->firstWhere('id', $id);
            abort_if($contrato === null, 404);
            $this->agreementId = $contrato->id;
            $this->agreementProfileId = $contrato->profile_id;
            $this->agreementAmount = (string) $contrato->amount;
            $this->agreementPeriod = $contrato->period->value;
            $this->agreementStartsOn = $contrato->starts_on->toDateString();
            $this->agreementEndsOn = (string) $contrato->ends_on?->toDateString();
            $this->agreementNotes = (string) $contrato->notes;
        }

        $this->showAgreementForm = true;
    }

    public function saveAgreement(ProfessionalIncomeService $service): void
    {
        abort_unless(auth()->user()->isConsultant(), 403);

        $dados = $this->validate([
            'agreementProfileId' => ['required', Rule::in(array_keys($this->clientes()))],
            'agreementAmount' => ['required', 'numeric', 'min:0.01', 'max:999999999'],
            'agreementPeriod' => ['required', Rule::in(array_keys(BillingPeriod::options()))],
            'agreementStartsOn' => ['required', 'date'],
            'agreementEndsOn' => ['nullable', 'date', 'after_or_equal:agreementStartsOn'],
            'agreementNotes' => ['nullable', 'string', 'max:500'],
        ], attributes: [
            'agreementProfileId' => 'cliente', 'agreementAmount' => 'valor', 'agreementPeriod' => 'periodicidade',
            'agreementStartsOn' => 'primeira cobrança', 'agreementEndsOn' => 'término', 'agreementNotes' => 'observação',
        ]);

        $service->saveAgreement(auth()->user(), $this->agreementId, [
            'profile_id' => $dados['agreementProfileId'], 'amount' => $dados['agreementAmount'], 'period' => $dados['agreementPeriod'],
            'starts_on' => $dados['agreementStartsOn'], 'ends_on' => $dados['agreementEndsOn'] ?? null, 'notes' => $dados['agreementNotes'] ?? null,
        ]);

        $this->showAgreementForm = false;
        $this->aviso = 'Contrato salvo. Os recebimentos previstos já aparecem nos próximos meses.';
    }

    public function toggleAgreement(ProfessionalIncomeService $service, string $id, bool $ativo): void
    {
        abort_unless(auth()->user()->isConsultant(), 403);

        $service->setAgreementActive(auth()->user(), $id, $ativo);
        $this->aviso = $ativo ? 'Contrato reativado.' : 'Contrato pausado. O que ainda era previsão saiu do livro.';
    }

    public function deleteAgreement(ProfessionalIncomeService $service, string $id): void
    {
        abort_unless(auth()->user()->isConsultant(), 403);

        $service->deleteAgreement(auth()->user(), $id);
        $this->aviso = 'Contrato excluído. O que já foi recebido continua no livro.';
    }

    public function render(ProfessionalIncomeService $service)
    {
        $usuario = auth()->user();
        $ehConsultor = $usuario->isConsultant();

        return view('livewire.consultant.professional-income', [
            'referencia' => $this->referencia(),
            'resumo' => $service->monthSummary($usuario, $this->ano, $this->mes),
            'totaisDoAno' => $service->yearTotals($usuario, $this->ano),
            'evolucao' => $service->evolution($usuario, $this->ano, $this->mes),
            'entradas' => $service->entriesFor($usuario, $this->ano, $this->mes),
            'contratos' => $ehConsultor && $this->aba === 'contratos' ? $service->agreements($usuario) : collect(),
            'clientes' => $this->clientes(),
            'ehConsultor' => $ehConsultor,
            'origens' => IncomeSource::options(),
            'periodos' => BillingPeriod::options(),
        ]);
    }

    private function referencia(): CarbonImmutable
    {
        return CarbonImmutable::create($this->ano, $this->mes, 1);
    }

    private function goTo(\DateTimeInterface $data): void
    {
        $this->ano = (int) $data->format('Y');
        $this->mes = (int) $data->format('n');
    }

    /** @return array<string, string> */
    private function clientes(): array
    {
        return $this->clientesCache ??= app(ProfessionalIncomeService::class)->clientOptions(auth()->user());
    }
}
