<?php

namespace App\Services;

use App\Enums\ConsultantClientStatus;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\InsurancePolicy;
use App\Models\InsurancePolicyRenewal;
use App\Models\InvestmentRecord;
use App\Models\ProfileMember;
use App\Models\User;
use App\Notifications\ClientBirthdayUpcoming;
use App\Notifications\InsurancePolicyAnniversaryUpcoming;
use App\Notifications\InsurancePolicyExpiringUpcoming;
use App\Notifications\InvestmentMaturityUpcoming;
use App\Support\RecurringDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "Datas importantes" da carteira: aniversário de titular/cônjuge,
 * aniversário (renovação) de apólice, vencimento fixo de apólice, e
 * vencimento de investimento — sempre visto do lado do PROFISSIONAL
 * (consultor/corretor), nunca do cliente (ver PortfolioInsurance::mount()
 * / ImportantDates::mount() pro gate). Mesmo padrão de
 * ConsultantPortfolioService: escopa pelos clientes ativos deste
 * profissional, atravessando profile_id de propósito via
 * withoutProfileScope().
 *
 * As duas leituras que existem aqui — "pra tela" (por profissional, com
 * intervalo de datas) e "pro cron" (a plataforma inteira, um dia por vez)
 * — usam a mesma base de cálculo (RecurringDate), só que a de tela olha
 * pra frente em PHP (carteira de um profissional é pequena, não compensa
 * complicar SQL pra recorrência de mês/dia) e a do cron filtra no banco
 * (a plataforma inteira não é pequena).
 */
class ImportantDatesService
{
    // -----------------------------------------------------------------
    // Leitura — tela
    // -----------------------------------------------------------------

    /**
     * `policies` é o prêmio de CADA apólice do aniversariante, não uma
     * soma — um cliente com vida na ICATU e na AZOS mostra as duas linhas,
     * porque "prêmio" é sempre o custo de UMA apólice específica. Já
     * escopado pro profissional (corretor só vê a apólice compartilhada
     * com ele) — reaproveita activePoliciesFor(), a mesma base de
     * upcomingPolicyAnniversaries()/upcomingPolicyExpiries().
     *
     * @return Collection<int, array{member: ProfileMember, client_name: string, occurrence_date: Carbon, turning_age: int, policies: Collection<int, InsurancePolicy>}>
     */
    public function upcomingBirthdays(User $professional, Carbon $from, Carbon $to): Collection
    {
        $profiles = $this->activeClientProfiles($professional);

        if ($profiles->isEmpty()) {
            return collect();
        }

        $nomes = $this->clientNamesByProfile($profiles);
        $apolicesPorMembro = $this->activePoliciesFor($professional)
            ->pluck('policy')
            ->filter(fn (InsurancePolicy $p) => $p->member_id !== null)
            ->groupBy('member_id');

        return ProfileMember::query()
            ->whereIn('profile_id', $profiles->pluck('id'))
            ->where('is_active', true)
            ->whereNotNull('birthdate')
            ->get()
            ->map(function (ProfileMember $membro) use ($from): array {
                $ocorrencia = RecurringDate::nextOccurrence($membro->birthdate, $from);

                return [
                    'member' => $membro,
                    'occurrence_date' => $ocorrencia,
                    'turning_age' => RecurringDate::yearsCompletingAt($membro->birthdate, $from),
                ];
            })
            ->filter(fn (array $l) => $l['occurrence_date']->isBetween($from->copy()->startOfDay(), $to->copy()->endOfDay()))
            ->map(fn (array $l) => $l + [
                'client_name' => $nomes[$l['member']->profile_id] ?? '—',
                'policies' => $apolicesPorMembro->get($l['member']->id) ?? collect(),
            ])
            ->sortBy('occurrence_date')
            ->values();
    }

    /**
     * @return Collection<int, array{policy: InsurancePolicy, client_name: string, occurrence_date: Carbon, years_completing: int}>
     */
    public function upcomingPolicyAnniversaries(User $professional, Carbon $from, Carbon $to): Collection
    {
        return $this->activePoliciesFor($professional)
            ->map(function (array $l) use ($from): array {
                $apolice = $l['policy'];

                return $l + [
                    'occurrence_date' => RecurringDate::nextOccurrence($apolice->start_date, $from),
                    'years_completing' => RecurringDate::yearsCompletingAt($apolice->start_date, $from),
                ];
            })
            ->filter(fn (array $l) => $l['occurrence_date']->isBetween($from->copy()->startOfDay(), $to->copy()->endOfDay()))
            ->sortBy('occurrence_date')
            ->values();
    }

    /**
     * @return Collection<int, array{policy: InsurancePolicy, client_name: string, occurrence_date: Carbon}>
     */
    public function upcomingPolicyExpiries(User $professional, Carbon $from, Carbon $to): Collection
    {
        return $this->activePoliciesFor($professional)
            ->filter(fn (array $l) => $l['policy']->expiry_date !== null
                && $l['policy']->expiry_date->isBetween($from->copy()->startOfDay(), $to->copy()->endOfDay()))
            ->map(fn (array $l) => $l + ['occurrence_date' => $l['policy']->expiry_date])
            ->sortBy('occurrence_date')
            ->values();
    }

    /**
     * Só consultor — corretor não tem acesso a investimento em nenhuma
     * outra tela (mesma régua de PortfolioInvestments), então aqui também
     * devolve vazio pra ele em vez de esconder só na blade.
     *
     * @return Collection<int, array{investment: InvestmentRecord, client_name: string, occurrence_date: Carbon}>
     */
    public function upcomingInvestmentMaturities(User $professional, Carbon $from): Collection
    {
        if (! $professional->isConsultant()) {
            return collect();
        }

        $profiles = $this->activeClientProfiles($professional);

        if ($profiles->isEmpty()) {
            return collect();
        }

        $nomes = $this->clientNamesByProfile($profiles);

        return InvestmentRecord::withoutProfileScope()
            ->whereIn('profile_id', $profiles->pluck('id'))
            ->active()
            ->whereNotNull('maturity_date')
            ->whereDate('maturity_date', '>=', $from->toDateString())
            ->orderBy('maturity_date')
            ->get()
            ->map(fn (InvestmentRecord $investimento): array => [
                'investment' => $investimento,
                'client_name' => $nomes[$investimento->profile_id] ?? '—',
                'occurrence_date' => $investimento->maturity_date,
            ]);
    }

    /** @return Collection<int, array{policy: InsurancePolicy, client_name: string}> */
    private function activePoliciesFor(User $professional): Collection
    {
        $profiles = $this->activeClientProfiles($professional);

        if ($profiles->isEmpty()) {
            return collect();
        }

        $nomes = $this->clientNamesByProfile($profiles);

        return InsurancePolicy::withoutProfileScope()
            ->whereIn('profile_id', $profiles->pluck('id'))
            ->active()
            // Mesmo raciocínio de ConsultantPortfolioService::allActivePolicies():
            // sem ProfileContext ativo aqui, o InsurancePolicyBrokerScope não
            // filtra sozinho — o corretor só vê apólice com o próprio broker_id.
            ->when($professional->isBroker(), fn ($q) => $q->where('broker_id', $professional->id))
            ->with('renewals', 'member')
            ->get()
            ->map(fn (InsurancePolicy $apolice): array => [
                'policy' => $apolice,
                'client_name' => $nomes[$apolice->profile_id] ?? '—',
            ]);
    }

    /** @return Collection<int, FinancialProfile> */
    private function activeClientProfiles(User $professional): Collection
    {
        $clientUserIds = ConsultantClient::query()
            ->where('consultant_id', $professional->id)
            ->where('status', ConsultantClientStatus::Active)
            ->pluck('client_id');

        return FinancialProfile::query()
            ->whereIn('owner_user_id', $clientUserIds)
            ->with('owner')
            ->get();
    }

    /**
     * @param  Collection<int, FinancialProfile>  $profiles
     * @return array<string, string>
     */
    private function clientNamesByProfile(Collection $profiles): array
    {
        return $profiles->mapWithKeys(fn (FinancialProfile $p) => [$p->id => $p->owner->name])->all();
    }

    // -----------------------------------------------------------------
    // Escrita — registrar renovação
    // -----------------------------------------------------------------

    /**
     * Grava a linha de histórico E atualiza a apólice com os valores
     * novos — o log técnico de campo a campo já acontece sozinho por
     * baixo, via InsurancePolicy::Auditable.
     */
    public function registerPolicyRenewal(
        InsurancePolicy $policy,
        User $recordedBy,
        string $newMonthlyPremium,
        ?string $newCoverageAmount,
        ?string $notes,
    ): InsurancePolicyRenewal {
        $renewal = InsurancePolicyRenewal::create([
            'insurance_policy_id' => $policy->id,
            'recorded_by_user_id' => $recordedBy->id,
            'renewed_at' => now()->toDateString(),
            'previous_monthly_premium' => $policy->monthly_premium,
            'new_monthly_premium' => $newMonthlyPremium,
            'previous_coverage_amount' => $policy->coverage_amount,
            'new_coverage_amount' => $newCoverageAmount,
            'notes' => $notes,
        ]);

        $policy->update([
            'monthly_premium' => $newMonthlyPremium,
            'coverage_amount' => $newCoverageAmount ?? $policy->coverage_amount,
        ]);

        return $renewal;
    }

    // -----------------------------------------------------------------
    // Notificação — cron
    // -----------------------------------------------------------------

    public function notifyUpcomingBirthdays(?Carbon $hoje = null): int
    {
        $hoje ??= Carbon::now();
        $alvo = $hoje->copy()->addDays(config('cerne.notifications.days_before_important_date'));
        $notificados = 0;

        ProfileMember::query()
            ->where('is_active', true)
            ->whereNotNull('birthdate')
            ->whereMonth('birthdate', $alvo->month)
            ->whereDay('birthdate', $alvo->day)
            ->with('profile')
            ->chunkById(200, function ($membros) use (&$notificados, $alvo): void {
                foreach ($membros as $membro) {
                    if ($membro->profile === null) {
                        continue;
                    }

                    $idade = $alvo->year - $membro->birthdate->year;

                    foreach ($this->professionalsFor($membro->profile->owner_user_id) as $profissional) {
                        if ($this->alreadyNotifiedToday($profissional, ClientBirthdayUpcoming::class, 'member_id', $membro->id)) {
                            continue;
                        }

                        $profissional->notify(ClientBirthdayUpcoming::forMember($membro, $alvo, $idade));
                        $notificados++;
                    }
                }
            });

        return $notificados;
    }

    public function notifyUpcomingPolicyAnniversaries(?Carbon $hoje = null): int
    {
        $hoje ??= Carbon::now();
        $alvo = $hoje->copy()->addDays(config('cerne.notifications.days_before_important_date'));
        $notificados = 0;

        InsurancePolicy::withoutProfileScope()
            ->active()
            ->whereMonth('start_date', $alvo->month)
            ->whereDay('start_date', $alvo->day)
            ->with('profile')
            ->chunkById(200, function ($apolices) use (&$notificados, $alvo): void {
                foreach ($apolices as $apolice) {
                    $anos = $alvo->year - $apolice->start_date->year;

                    if ($anos < 1) {
                        continue;
                    }

                    foreach ($this->policyRecipients($apolice) as $profissional) {
                        if ($this->alreadyNotifiedToday($profissional, InsurancePolicyAnniversaryUpcoming::class, 'insurance_policy_id', $apolice->id)) {
                            continue;
                        }

                        $profissional->notify(InsurancePolicyAnniversaryUpcoming::forPolicy($apolice, $alvo, $anos));
                        $notificados++;
                    }
                }
            });

        return $notificados;
    }

    public function notifyUpcomingPolicyExpiries(?Carbon $hoje = null): int
    {
        $alvo = ($hoje ?? Carbon::now())->copy()->addDays(config('cerne.notifications.days_before_important_date'));
        $notificados = 0;

        InsurancePolicy::withoutProfileScope()
            ->active()
            ->whereDate('expiry_date', $alvo->toDateString())
            ->with('profile')
            ->chunkById(200, function ($apolices) use (&$notificados): void {
                foreach ($apolices as $apolice) {
                    foreach ($this->policyRecipients($apolice) as $profissional) {
                        if ($this->alreadyNotifiedToday($profissional, InsurancePolicyExpiringUpcoming::class, 'insurance_policy_id', $apolice->id)) {
                            continue;
                        }

                        $profissional->notify(InsurancePolicyExpiringUpcoming::forPolicy($apolice));
                        $notificados++;
                    }
                }
            });

        return $notificados;
    }

    public function notifyUpcomingInvestmentMaturities(?Carbon $hoje = null): int
    {
        $alvo = ($hoje ?? Carbon::now())->copy()->addDays(config('cerne.notifications.days_before_important_date'));
        $notificados = 0;

        InvestmentRecord::withoutProfileScope()
            ->active()
            ->whereDate('maturity_date', $alvo->toDateString())
            ->with('profile')
            ->chunkById(200, function ($investimentos) use (&$notificados): void {
                foreach ($investimentos as $investimento) {
                    if ($investimento->profile === null) {
                        continue;
                    }

                    // Só consultor — corretor não recebe nada de investimento.
                    $consultores = $this->professionalsFor($investimento->profile->owner_user_id)
                        ->filter(fn (User $p) => $p->isConsultant());

                    foreach ($consultores as $profissional) {
                        if ($this->alreadyNotifiedToday($profissional, InvestmentMaturityUpcoming::class, 'investment_id', $investimento->id)) {
                            continue;
                        }

                        $profissional->notify(InvestmentMaturityUpcoming::forInvestment($investimento));
                        $notificados++;
                    }
                }
            });

        return $notificados;
    }

    /**
     * Consultor + corretor daquela apólice específica (broker_id) — nunca
     * corretor de outro produto do mesmo cliente.
     *
     * @return Collection<int, User>
     */
    private function policyRecipients(InsurancePolicy $policy): Collection
    {
        return $this->professionalsFor($policy->profile->owner_user_id)
            ->filter(fn (User $p) => $p->isConsultant() || $p->id === $policy->broker_id);
    }

    /**
     * Todo profissional com vínculo ATIVO ao dono deste perfil — consultor
     * e qualquer corretor, sem distinção (quem filtra por papel é o
     * chamador, quando o caso pede).
     *
     * @return Collection<int, User>
     */
    private function professionalsFor(string $ownerUserId): Collection
    {
        $professionalIds = ConsultantClient::query()
            ->where('client_id', $ownerUserId)
            ->where('status', ConsultantClientStatus::Active)
            ->pluck('consultant_id');

        return User::query()->whereIn('id', $professionalIds)->get();
    }

    /** Evita duplicar aviso se a rotina for reexecutada manualmente no mesmo dia. */
    private function alreadyNotifiedToday(User $user, string $tipo, string $chave, string $id): bool
    {
        return $user->notifications()
            ->where('type', $tipo)
            ->whereJsonContains("data->{$chave}", $id)
            ->whereDate('created_at', today())
            ->exists();
    }
}
