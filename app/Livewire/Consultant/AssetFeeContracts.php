<?php

namespace App\Livewire\Consultant;

use App\Enums\AssetFeeMode;
use App\Enums\BillingPeriod;
use App\Models\CommissionRule;
use App\Services\AssetFeeService;
use App\Services\ProfessionalIncomeService;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Contratos de percentual sobre patrimônio do consultor, um por cliente. Mora na aba "Contratos" da tela de Recebimentos;
 * só mexe nos dados do próprio consultor.
 */
class AssetFeeContracts extends Component
{
    public ?string $aviso = null;

    public bool $showForm = false;

    public ?string $contractId = null;

    public string $profileId = '';

    public string $period = 'quarterly';

    public string $mode = 'total';

    public string $totalPercent = '';

    public string $startsOn = '';

    public string $endsOn = '';

    public string $notes = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isConsultant(), 403);
    }

    public function openForm(?string $id = null): void
    {
        $this->resetErrorBag();
        $this->reset('contractId', 'profileId', 'totalPercent', 'endsOn', 'notes');
        $this->period = BillingPeriod::Quarterly->value;
        $this->mode = AssetFeeMode::Total->value;
        $this->startsOn = now()->startOfMonth()->toDateString();

        if ($id !== null) {
            $contrato = app(AssetFeeService::class)->agreements(auth()->user())->firstWhere('id', $id);
            abort_if($contrato === null, 404);
            $this->contractId = $contrato->id;
            $this->profileId = $contrato->profile_id;
            $this->period = $contrato->period->value;
            $this->mode = $contrato->mode->value;
            $this->totalPercent = (string) $contrato->total_percent;
            $this->startsOn = $contrato->starts_on->toDateString();
            $this->endsOn = (string) $contrato->ends_on?->toDateString();
            $this->notes = (string) $contrato->notes;
        }

        $this->showForm = true;
    }

    public function save(AssetFeeService $service, ProfessionalIncomeService $income): void
    {
        $dados = $this->validate([
            'profileId' => ['required', Rule::in(array_keys($income->clientOptions(auth()->user())))],
            'period' => ['required', Rule::in(array_keys(BillingPeriod::options()))],
            'mode' => ['required', Rule::in(array_keys(AssetFeeMode::options()))],
            'totalPercent' => ['nullable', 'required_if:mode,total', 'numeric', 'min:0.0001', 'max:100'],
            'startsOn' => ['required', 'date'],
            'endsOn' => ['nullable', 'date', 'after_or_equal:startsOn'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], attributes: [
            'profileId' => 'cliente', 'period' => 'periodicidade', 'mode' => 'base de cálculo', 'totalPercent' => 'percentual',
            'startsOn' => 'início', 'endsOn' => 'término', 'notes' => 'observação',
        ]);

        $service->saveAgreement(auth()->user(), $this->contractId, [
            'profile_id' => $dados['profileId'], 'period' => $dados['period'], 'mode' => $dados['mode'], 'total_percent' => $dados['totalPercent'] ?? null,
            'starts_on' => $dados['startsOn'], 'ends_on' => $dados['endsOn'] ?? null, 'notes' => $dados['notes'] ?? null,
        ]);

        $this->showForm = false;
        $this->aviso = 'Contrato salvo. As cobranças previstas já aparecem nos próximos meses.';
    }

    public function toggle(AssetFeeService $service, string $id, bool $ativo): void
    {
        $service->setAgreementActive(auth()->user(), $id, $ativo);
        $this->aviso = $ativo ? 'Contrato reativado.' : 'Contrato pausado. O que ainda era previsão saiu do livro.';
    }

    public function delete(AssetFeeService $service, string $id): void
    {
        $service->deleteAgreement(auth()->user(), $id);
        $this->aviso = 'Contrato excluído. O que já foi recebido continua no livro.';
    }

    public function render(AssetFeeService $service, ProfessionalIncomeService $income)
    {
        $usuario = auth()->user();
        $regras = CommissionRule::query()->ownedBy($usuario)->where('kind', CommissionRule::KIND_INVESTMENT)->get();
        $contratos = $service->agreements($usuario)->map(fn ($c) => ['contrato' => $c, 'estimativa' => $service->preview($c, $regras)]);

        return view('livewire.consultant.asset-fee-contracts', [
            'contratos' => $contratos,
            'clientes' => $income->clientOptions($usuario),
            'periodos' => BillingPeriod::options(),
            'modos' => AssetFeeMode::options(),
            'temRegras' => $regras->where('is_active', true)->isNotEmpty(),
        ]);
    }
}
