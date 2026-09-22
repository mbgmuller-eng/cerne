<?php

namespace App\Livewire\Consultant;

use App\Models\FinancialProfile;
use App\Models\InsurancePolicy;
use App\Models\ProfileMember;
use App\Services\ImportantDatesService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * "Datas importantes" da carteira — aniversário de titular/cônjuge,
 * aniversário (renovação) e vencimento fixo de apólice, vencimento de
 * investimento. Só pro profissional (consultor/corretor); o cliente nunca
 * vê o próprio aviso aqui (ver ImportantDatesService, que já filtra por
 * quem é vinculado a quem).
 *
 * Aba de investimento é consultor-only — corretor não tem acesso a
 * investimento em nenhuma outra tela, mantém a régua (ver
 * ImportantDatesService::upcomingInvestmentMaturities()).
 */
#[Layout('components.layouts.app')]
class ImportantDates extends Component
{
    private const PERIODOS_PADRAO = [
        '7dias' => 'Próximos 7 dias',
        'mes' => 'Este mês',
    ];

    /** @var array<string, string> token do mês => rótulo, só pra aba de aniversariantes */
    private const MESES = [
        'jan' => 'Janeiro', 'fev' => 'Fevereiro', 'mar' => 'Março', 'abr' => 'Abril',
        'mai' => 'Maio', 'jun' => 'Junho', 'jul' => 'Julho', 'ago' => 'Agosto',
        'set' => 'Setembro', 'out' => 'Outubro', 'nov' => 'Novembro', 'dez' => 'Dezembro',
    ];

    #[Url]
    public string $aba = 'aniversariantes';

    #[Url]
    public string $periodo = '7dias';

    public bool $showRenewalForm = false;

    public ?string $renewingPolicyId = null;

    public string $renewalInsurerName = '';

    public string $renewalPremium = '';

    public string $renewalCoverage = '';

    public string $renewalNotes = '';

    public ?string $addingBirthdateMemberId = null;

    public string $birthdateInput = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isLinkedProfessional(), 403);
    }

    public function setAba(string $aba): void
    {
        $this->aba = $aba;
        // Aba de investimento não tem seletor de mês — evita ficar com um
        // token de mês selecionado que não existe mais na lista.
        if ($aba !== 'aniversariantes' && ! array_key_exists($this->periodo, self::PERIODOS_PADRAO)) {
            $this->periodo = '7dias';
        }
    }

    // -----------------------------------------------------------------
    // Registrar renovação
    // -----------------------------------------------------------------

    public function startRenewal(string $policyId): void
    {
        $apolice = $this->apoliceVinculada($policyId);

        $this->renewingPolicyId = $apolice->id;
        $this->renewalInsurerName = $apolice->insurer_name;
        $this->renewalPremium = $apolice->monthly_premium;
        $this->renewalCoverage = (string) ($apolice->coverage_amount ?? '');
        $this->renewalNotes = '';
        $this->resetErrorBag();
        $this->showRenewalForm = true;
    }

    public function cancelRenewal(): void
    {
        $this->reset('renewingPolicyId', 'renewalInsurerName', 'renewalPremium', 'renewalCoverage', 'renewalNotes', 'showRenewalForm');
    }

    public function saveRenewal(ImportantDatesService $service): void
    {
        $apolice = $this->apoliceVinculada($this->renewingPolicyId);

        $data = $this->validate([
            'renewalPremium' => ['required', 'numeric', 'min:0'],
            'renewalCoverage' => ['nullable', 'numeric', 'min:0'],
            'renewalNotes' => ['nullable', 'string'],
        ], attributes: [
            'renewalPremium' => 'prêmio novo',
            'renewalCoverage' => 'cobertura nova',
        ]);

        $service->registerPolicyRenewal(
            $apolice,
            auth()->user(),
            $data['renewalPremium'],
            $data['renewalCoverage'] !== '' && $data['renewalCoverage'] !== null ? $data['renewalCoverage'] : null,
            $data['renewalNotes'] !== '' ? $data['renewalNotes'] : null,
        );

        $this->cancelRenewal();
        session()->flash('status', 'Renovação registrada.');
    }

    /**
     * Só deixa mexer numa apólice que o profissional de fato enxerga —
     * mesmo escopo de leitura da tela, checado de novo aqui porque o id
     * chega do cliente (wire:click), nunca confia só no que a tela mostrou.
     *
     * `authorize('view', profile)` sozinho não basta pro corretor: ele
     * pode estar vinculado ao MESMO cliente por outro tipo de seguro (ex.:
     * corretor de auto vinculado ao cliente da Rosana, que também tem
     * corretor de vida) — sem o segundo check, ele conseguiria renovar uma
     * apólice que não é a dele, só por estar na carteira do mesmo cliente.
     */
    private function apoliceVinculada(?string $policyId): InsurancePolicy
    {
        // withoutProfileScope(): esta tela nunca tem um perfil ativo
        // aberto (é a carteira inteira, não o detalhe de um cliente) —
        // com o escopo normal, a query devolveria zero linhas sempre.
        // Quem autoriza de verdade é o par authorize()+broker_id abaixo.
        $apolice = InsurancePolicy::withoutProfileScope()->find($policyId);

        if ($apolice === null) {
            abort(404);
        }

        $this->authorize('view', $apolice->profile);
        abort_if(auth()->user()->isBroker() && $apolice->broker_id !== auth()->id(), 403);

        return $apolice;
    }

    // -----------------------------------------------------------------
    // Aniversário — preencher quando falta
    // -----------------------------------------------------------------

    public function startAddingBirthdate(string $memberId): void
    {
        $this->addingBirthdateMemberId = $memberId;
        $this->birthdateInput = '';
        $this->resetErrorBag();
    }

    public function cancelAddingBirthdate(): void
    {
        $this->reset('addingBirthdateMemberId', 'birthdateInput');
    }

    public function saveBirthdate(): void
    {
        $membro = $this->membroVinculado($this->addingBirthdateMemberId);

        $data = $this->validate([
            'birthdateInput' => ['required', 'date', 'before:today'],
        ], attributes: ['birthdateInput' => 'data de nascimento']);

        $membro->update(['birthdate' => $data['birthdateInput']]);

        $this->cancelAddingBirthdate();
        session()->flash('status', 'Aniversário salvo.');
    }

    /** Mesmo raciocínio de apoliceVinculada(): reconfirma o acesso pelo perfil do membro. */
    private function membroVinculado(?string $memberId): ProfileMember
    {
        $membro = ProfileMember::query()->find($memberId);

        if ($membro === null) {
            abort(404);
        }

        $perfil = FinancialProfile::query()->find($membro->profile_id);

        if ($perfil === null) {
            abort(404);
        }

        $this->authorize('view', $perfil);

        return $membro;
    }

    // -----------------------------------------------------------------
    // Leitura
    // -----------------------------------------------------------------

    private function intervalo(): array
    {
        $hoje = Carbon::now();

        if ($this->periodo === '7dias') {
            return [$hoje, $hoje->copy()->addDays(7)];
        }

        if ($this->periodo === 'mes') {
            return [$hoje, $hoje->copy()->endOfMonth()];
        }

        $indiceMes = array_search($this->periodo, array_keys(self::MESES), true);

        if ($indiceMes === false) {
            return [$hoje, $hoje->copy()->addDays(7)];
        }

        // Mês escolhido no ano corrente, a não ser que já tenha passado —
        // mesmo raciocínio de RecurringDate::nextOccurrence: sempre a
        // PRÓXIMA ocorrência daquele mês, nunca uma que já ficou pra trás.
        $ancora = $hoje->copy()->startOfMonth()->month($indiceMes + 1);

        if ($ancora->lt($hoje->copy()->startOfMonth())) {
            $ancora = $ancora->addYear();
        }

        return [$ancora->copy()->startOfMonth(), $ancora->copy()->endOfMonth()];
    }

    public function getBirthdaysProperty(): Collection
    {
        [$from, $to] = $this->intervalo();

        return app(ImportantDatesService::class)->upcomingBirthdays(auth()->user(), $from, $to);
    }

    public function getPolicyAnniversariesProperty(): Collection
    {
        [$from, $to] = $this->intervalo();

        return app(ImportantDatesService::class)->upcomingPolicyAnniversaries(auth()->user(), $from, $to);
    }

    public function getPolicyExpiriesProperty(): Collection
    {
        [$from, $to] = $this->intervalo();

        return app(ImportantDatesService::class)->upcomingPolicyExpiries(auth()->user(), $from, $to);
    }

    public function getInvestmentMaturitiesProperty(): Collection
    {
        return app(ImportantDatesService::class)->upcomingInvestmentMaturities(auth()->user(), Carbon::now());
    }

    /** Titular/cônjuge dos clientes vinculados sem aniversário cadastrado — pra preencher direto daqui. */
    public function getMembersWithoutBirthdateProperty(): Collection
    {
        $profileIds = auth()->user()->accessibleProfiles()->pluck('id');

        if ($profileIds->isEmpty()) {
            return collect();
        }

        return ProfileMember::query()
            ->whereIn('profile_id', $profileIds)
            ->where('is_active', true)
            ->whereNull('birthdate')
            ->with('profile.owner')
            ->orderBy('name')
            ->get();
    }

    public function render()
    {
        return view('livewire.consultant.important-dates', [
            'isConsultant' => auth()->user()->isConsultant(),
            'periodos' => self::PERIODOS_PADRAO,
            'meses' => self::MESES,
            'birthdays' => $this->birthdays,
            'policyAnniversaries' => $this->policyAnniversaries,
            'policyExpiries' => $this->policyExpiries,
            'investmentMaturities' => $this->investmentMaturities,
            'membersWithoutBirthdate' => $this->membersWithoutBirthdate,
        ]);
    }
}
