<?php

namespace App\Services;

use App\Enums\ConsultantClientStatus;
use App\Enums\InsuranceType;
use App\Enums\LeadKind;
use App\Enums\LeadSource;
use App\Enums\LeadStage;
use App\Models\ConsultantClient;
use App\Models\InsurancePolicy;
use App\Models\Lead;
use App\Models\LeadSuggestionDismissal;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Leads que nascem da carteira do profissional, em vez de digitados:
 *
 *  - Renovação: a apólice de um cliente vence (ou faz aniversário) em breve. O cron cria o lead sozinho, uma vez por
 *    apólice e por data (chave de automação com índice único, como as demais rotinas agendadas).
 *  - Venda cruzada: o cliente não tem um tipo de seguro que costuma fazer sentido (vida, residência, saúde). Aqui NÃO
 *    se cria nada sozinho: viram sugestões que o profissional aceita ou dispensa, para não despejar dezenas de
 *    contatos de uma vez numa carteira grande.
 *
 * O que cada profissional enxerga segue a regra do resto do app: o consultor vê todas as apólices dos clientes dele; o
 * corretor, só as que foram compartilhadas com ele (ver ImportantDatesService::activePoliciesFor).
 */
class LeadAutomationService
{
    public function __construct(private readonly ImportantDatesService $dates) {}

    // -----------------------------------------------------------------
    // Renovação — cron
    // -----------------------------------------------------------------

    /** @return int quantos leads foram criados */
    public function createRenewalLeadsForAll(?Carbon $hoje = null): int
    {
        $criados = 0;

        User::query()
            ->whereIn('id', ConsultantClient::query()->where('status', ConsultantClientStatus::Active)->select('consultant_id')->distinct())
            ->each(function (User $profissional) use ($hoje, &$criados): void {
                if ($profissional->isLinkedProfessional()) {
                    $criados += $this->createRenewalLeads($profissional, $hoje);
                }
            });

        return $criados;
    }

    /**
     * As escolhas do profissional para a renovação: quais tipos de seguro geram lead e com quantos dias de antecedência.
     *
     * @return array{types: list<string>, days: int}
     */
    public function renewalSettings(User $profissional): array
    {
        return [
            'types' => $profissional->renewal_lead_types ?? config('cerne.lead_automation.renewal_default_types'),
            'days' => (int) ($profissional->renewal_lead_days ?? config('cerne.lead_automation.renewal_days')),
        ];
    }

    /** @return int quantos leads foram criados para este profissional */
    public function createRenewalLeads(User $profissional, ?Carbon $hoje = null): int
    {
        $escolhas = $this->renewalSettings($profissional);
        $de = ($hoje ?? Carbon::now())->copy()->startOfDay();
        $ate = $de->copy()->addDays($escolhas['days']);
        $criados = 0;

        // Apólice com vencimento renova no vencimento; sem vencimento (vida vitalícia, por exemplo), no aniversário.
        $vencimentos = $this->dates->upcomingPolicyExpiries($profissional, $de, $ate);
        $aniversarios = $this->dates->upcomingPolicyAnniversaries($profissional, $de, $ate)
            ->reject(fn (array $l) => $l['policy']->expiry_date !== null || $l['years_completing'] < 1);

        // Fora o que o profissional não quer tratar como renovação (tipo desligado) e a apólice que renova sozinha.
        $candidatos = $vencimentos->concat($aniversarios)->reject(fn (array $l) => $l['policy']->auto_renews
            || ! in_array($l['policy']->insurance_type->value, $escolhas['types'], true));

        foreach ($candidatos as $item) {
            if ($this->createRenewalLead($profissional, $item['policy'], $item['client_name'], $item['occurrence_date'], $de)) {
                $criados++;
            }
        }

        return $criados;
    }

    private function createRenewalLead(User $profissional, InsurancePolicy $apolice, string $cliente, CarbonInterface $data, Carbon $hoje): bool
    {
        // Se já há um contato aberto para este cliente neste tipo de seguro (digitado à mão ou de outra rotina), é ele
        // que se trabalha: um segundo lead só duplicaria o mesmo assunto.
        if ($this->hasOpenLeadFor($profissional, $apolice->profile_id, $apolice->insurance_type)) {
            return false;
        }

        $tipo = $apolice->insurance_type;
        $acao = $data->copy()->subDays((int) config('cerne.lead_automation.contact_days_before'))->startOfDay();

        try {
            Lead::create([
                'consultant_id' => $profissional->id,
                'client_profile_id' => $apolice->profile_id,
                'source_policy_id' => $apolice->id,
                'automation_key' => 'renewal:'.$apolice->id.':'.$data->toDateString(),
                'kind' => LeadKind::Insurance,
                'name' => $cliente,
                'email' => $apolice->profile?->owner?->email,
                'interests' => [$tipo->value],
                'source' => LeadSource::Renewal,
                'source_detail' => 'Apólice '.$apolice->insurer_name.' ('.mb_strtolower($tipo->label()).')',
                'stage' => LeadStage::NewContact,
                'notes' => ($apolice->expiry_date !== null ? 'A apólice vence em ' : 'A apólice completa mais um ano em ').$data->format('d/m/Y').'. Hora de revisar a renovação.',
                'next_action_at' => $acao->lt($hoje) ? $hoje : $acao,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false; // outro disparo do cron já criou este lead
        }

        return true;
    }

    // -----------------------------------------------------------------
    // Venda cruzada — sugestões
    // -----------------------------------------------------------------

    /**
     * Clientes sem um dos tipos de seguro configurados, entre as apólices que o profissional enxerga.
     *
     * @return Collection<int, array{key: string, profile_id: string, client_name: string, type: InsuranceType, policies: int}>
     */
    public function crossSellSuggestions(User $profissional): Collection
    {
        $perfis = $this->dates->activeClientProfiles($profissional);

        if ($perfis->isEmpty()) {
            return collect();
        }

        $tipos = collect(config('cerne.lead_automation.cross_sell_types'))
            ->map(fn (string $t) => InsuranceType::tryFrom($t))
            ->filter()
            ->values();

        $apolicasPorPerfil = $this->dates->activePoliciesFor($profissional)->groupBy(fn (array $l) => $l['policy']->profile_id);

        $jaTratadas = Lead::query()->where('consultant_id', $profissional->id)->whereNotNull('automation_key')->pluck('automation_key')->flip();
        $dispensadas = LeadSuggestionDismissal::query()->where('consultant_id', $profissional->id)->pluck('suggestion_key')->flip();
        $abertos = Lead::query()->where('consultant_id', $profissional->id)->open()->whereNotNull('client_profile_id')->get(['client_profile_id', 'interests']);

        $sugestoes = collect();

        foreach ($perfis as $perfil) {
            $daPessoa = $apolicasPorPerfil->get($perfil->id, collect());
            $tiposQueTem = $daPessoa->map(fn (array $l) => $l['policy']->insurance_type->value)->unique()->all();
            $nome = $daPessoa->first()['client_name'] ?? \App\Support\ClientDisplayName::forProfile($perfil);

            foreach ($tipos as $tipo) {
                $chave = $this->crossKey($perfil->id, $tipo);

                if (in_array($tipo->value, $tiposQueTem, true) || isset($jaTratadas[$chave]) || isset($dispensadas[$chave])) {
                    continue;
                }

                if ($abertos->contains(fn (Lead $l) => $l->client_profile_id === $perfil->id && in_array($tipo->value, $l->interests ?? [], true))) {
                    continue;
                }

                $sugestoes->push(['key' => $chave, 'profile_id' => $perfil->id, 'client_name' => $nome, 'type' => $tipo, 'policies' => $daPessoa->count()]);
            }
        }

        return $sugestoes->sortBy([['client_name', 'asc']])->values();
    }

    /** Aceita uma sugestão: cria o lead. Nulo se a sugestão não existe (mais) para este profissional. */
    public function createFromSuggestion(User $profissional, string $profileId, string $tipo): ?Lead
    {
        $sugestao = $this->crossSellSuggestions($profissional)->first(fn (array $s) => $s['profile_id'] === $profileId && $s['type']->value === $tipo);

        if ($sugestao === null) {
            return null;
        }

        $perfil = $this->dates->activeClientProfiles($profissional)->firstWhere('id', $profileId);

        try {
            return Lead::create([
                'consultant_id' => $profissional->id,
                'client_profile_id' => $profileId,
                'automation_key' => $sugestao['key'],
                'kind' => LeadKind::Insurance,
                'name' => $sugestao['client_name'],
                'email' => $perfil?->owner?->email,
                'interests' => [$tipo],
                'source' => LeadSource::CrossSell,
                'source_detail' => 'Cliente sem '.mb_strtolower($sugestao['type']->label()).' entre as apólices acompanhadas',
                'stage' => LeadStage::NewContact,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /** Dispensa uma sugestão para sempre. Só vale para uma sugestão que existe para este profissional. */
    public function dismissSuggestion(User $profissional, string $profileId, string $tipo): bool
    {
        $sugestao = $this->crossSellSuggestions($profissional)->first(fn (array $s) => $s['profile_id'] === $profileId && $s['type']->value === $tipo);

        if ($sugestao === null) {
            return false;
        }

        LeadSuggestionDismissal::firstOrCreate(['consultant_id' => $profissional->id, 'suggestion_key' => $sugestao['key']]);

        return true;
    }

    private function crossKey(string $profileId, InsuranceType $tipo): string
    {
        return 'cross:'.$profileId.':'.$tipo->value;
    }

    private function hasOpenLeadFor(User $profissional, string $profileId, InsuranceType $tipo): bool
    {
        return Lead::query()
            ->where('consultant_id', $profissional->id)
            ->where('client_profile_id', $profileId)
            ->open()
            ->whereJsonContains('interests', $tipo->value)
            ->exists();
    }
}
