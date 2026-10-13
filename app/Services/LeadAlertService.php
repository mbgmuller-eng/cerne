<?php

namespace App\Services;

use App\Enums\LeadQuoteStatus;
use App\Models\Lead;
use App\Models\LeadAlertDigest;
use App\Models\LeadQuote;
use App\Models\User;
use App\Notifications\LeadFollowUpDigest;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Um aviso por dia por profissional com o que pede atenção nos leads dele: cotações que vencem em poucos dias, contatos
 * sem retorno há muito tempo e próximos passos que já passaram da data. Um resumo, não um aviso por lead: quem tem 30
 * contatos não pode receber 30 notificações.
 *
 * Idempotente por índice único (lead_alert_digests: profissional + dia), como as demais rotinas agendadas.
 */
class LeadAlertService
{
    /** @return int quantos profissionais foram avisados */
    public function notifyDigests(?CarbonImmutable $hoje = null): int
    {
        $hoje ??= CarbonImmutable::now();
        $avisados = 0;

        User::query()
            ->whereIn('id', Lead::query()->open()->select('consultant_id')->distinct())
            ->each(function (User $profissional) use ($hoje, &$avisados): void {
                $contagem = $this->countsFor($profissional, $hoje);

                if (array_sum($contagem) === 0) {
                    return;
                }

                try {
                    LeadAlertDigest::create([
                        'consultant_id' => $profissional->id,
                        'sent_on' => $hoje->toDateString(),
                        'quotes_expiring' => $contagem['quotes_expiring'],
                        'stale_leads' => $contagem['stale_leads'],
                        'overdue_actions' => $contagem['overdue_actions'],
                    ]);
                } catch (UniqueConstraintViolationException) {
                    return; // outro disparo do cron já avisou hoje
                }

                $profissional->notify(new LeadFollowUpDigest($contagem['quotes_expiring'], $contagem['stale_leads'], $contagem['overdue_actions']));
                $avisados++;
            });

        return $avisados;
    }

    /** @return array{quotes_expiring: int, stale_leads: int, overdue_actions: int} */
    public function countsFor(User $profissional, CarbonImmutable $hoje): array
    {
        $hoje = $hoje->startOfDay();
        $abertos = fn () => Lead::query()->where('consultant_id', $profissional->id)->open();

        $cotacoes = LeadQuote::query()
            ->whereIn('lead_id', $abertos()->select('id'))
            ->whereIn('status', [LeadQuoteStatus::Draft->value, LeadQuoteStatus::Sent->value])
            ->whereBetween('valid_until', [$hoje->toDateString(), $hoje->addDays((int) config('cerne.lead_alerts.quote_days'))->toDateString()])
            ->count();

        // Sem contato: a última atividade registrada, ou a entrada do lead quando nunca houve nenhuma.
        $limite = $hoje->subDays((int) config('cerne.lead_alerts.stale_days'));
        $parados = $abertos()
            ->whereRaw('COALESCE((SELECT MAX(occurred_at) FROM lead_activities WHERE lead_activities.lead_id = leads.id), leads.created_at) < ?', [$limite->toDateTimeString()])
            ->count();

        $atrasados = $abertos()
            ->whereNotNull('next_action_at')
            ->where('next_action_at', '<', $hoje->toDateTimeString())
            ->count();

        return ['quotes_expiring' => $cotacoes, 'stale_leads' => $parados, 'overdue_actions' => $atrasados];
    }
}
