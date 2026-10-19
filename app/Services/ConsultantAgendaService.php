<?php

namespace App\Services;

use App\Enums\ConsultantClientStatus;
use App\Enums\EngagementMeetingStatus;
use App\Enums\EngagementTaskStatus;
use App\Models\ConsultantClient;
use App\Models\EngagementMeeting;
use App\Models\EngagementTask;
use App\Models\FinancialProfile;
use App\Models\Lead;
use App\Models\User;
use App\Support\Calendar\CalendarEvent;
use App\Support\ClientDisplayName;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A agenda do profissional: as reuniões marcadas com os clientes, os prazos das tarefas e os retornos combinados com leads.
 *
 * Só do que é dele: reunião e tarefa dos clientes com vínculo ativo e ligadas a este consultor, e os leads dele. Lê sem o escopo
 * de perfil (como a "Minha semana") e se limita pelo consultor e pelos perfis dos clientes ativos. Corretor só tem leads.
 */
class ConsultantAgendaService
{
    public const MEETINGS = 'meetings';

    public const TASKS = 'tasks';

    public const LEADS = 'leads';

    /** Cor de cada tipo: o profissional distingue o que é reunião, prazo e retorno de longe. */
    public const COLORS = [self::MEETINGS => '#0F766E', self::TASKS => '#D97706', self::LEADS => '#7C3AED'];

    /** @return array<string, string> o que o profissional pode ver na agenda dele, com o nome para os filtros */
    public function typesFor(User $profissional): array
    {
        return array_filter([
            self::MEETINGS => $profissional->isConsultant() ? 'Reuniões' : null,
            self::TASKS => $profissional->isConsultant() ? 'Prazos de tarefas' : null,
            self::LEADS => 'Retornos de leads',
        ]);
    }

    /**
     * @param  list<string>  $tipos  quais camadas mostrar (as constantes acima); o que o profissional não pode ver é ignorado
     * @return Collection<int, CalendarEvent>
     */
    public function events(User $profissional, CarbonImmutable $inicio, CarbonImmutable $fim, array $tipos): Collection
    {
        $tipos = array_values(array_intersect($tipos, array_keys($this->typesFor($profissional))));
        $eventos = collect();

        if ($profissional->isConsultant() && array_intersect($tipos, [self::MEETINGS, self::TASKS]) !== []) {
            $perfis = $this->clientProfiles($profissional);
            $nomes = $perfis->map(fn (FinancialProfile $p) => ClientDisplayName::forProfile($p));

            if (in_array(self::MEETINGS, $tipos, true)) {
                $eventos = $eventos->concat($this->meetings($profissional, $perfis->keys()->all(), $nomes, $inicio, $fim));
            }

            if (in_array(self::TASKS, $tipos, true)) {
                $eventos = $eventos->concat($this->tasks($profissional, $perfis->keys()->all(), $nomes, $inicio, $fim));
            }
        }

        if (in_array(self::LEADS, $tipos, true)) {
            $eventos = $eventos->concat($this->leads($profissional, $inicio, $fim));
        }

        return $eventos->values();
    }

    /** @return Collection<string, FinancialProfile> os perfis dos clientes com vínculo ativo, pelo id */
    private function clientProfiles(User $consultor): Collection
    {
        return FinancialProfile::query()
            ->whereIn('owner_user_id', ConsultantClient::query()
                ->where('consultant_id', $consultor->id)
                ->where('status', ConsultantClientStatus::Active)
                ->select('client_id'))
            ->with(['owner', 'members.user'])
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  list<string>  $ids
     * @param  Collection<string, string>  $nomes
     * @return Collection<int, CalendarEvent>
     */
    private function meetings(User $consultor, array $ids, Collection $nomes, CarbonImmutable $inicio, CarbonImmutable $fim): Collection
    {
        return EngagementMeeting::withoutGlobalScopes()
            ->where('consultant_id', $consultor->id)
            ->whereIn('profile_id', $ids)
            ->whereIn('status', [EngagementMeetingStatus::Scheduled->value, EngagementMeetingStatus::Done->value])
            ->whereBetween('scheduled_at', [$inicio->startOfDay(), $fim->endOfDay()])
            ->orderBy('scheduled_at')
            ->get()
            ->map(function (EngagementMeeting $m) use ($nomes): CalendarEvent {
                $cliente = $nomes[$m->profile_id] ?? '—';
                $pauta = $m->agendaItems();

                return new CalendarEvent(
                    key: 'reuniao:'.$m->id,
                    title: 'Reunião: '.$cliente,
                    start: CarbonImmutable::instance($m->scheduled_at),
                    end: CarbonImmutable::instance($m->scheduled_at)->addMinutes(max(15, (int) $m->duration_minutes)),
                    color: self::COLORS[self::MEETINGS],
                    tag: $m->status === EngagementMeetingStatus::Done ? 'Reunião realizada' : 'Reunião',
                    lines: array_values(array_filter([
                        $cliente, $m->format->label(), $m->location,
                        $pauta !== [] ? 'Pauta: '.count($pauta).($pauta === [] || count($pauta) === 1 ? ' item' : ' itens') : null,
                    ])),
                    muted: $m->status === EngagementMeetingStatus::Done,
                    shortTitle: $cliente,
                    meta: [
                        'type' => self::MEETINGS, 'profile_id' => $m->profile_id, 'client' => $cliente, 'format' => $m->format->label(),
                        'location' => $m->location, 'agenda' => $pauta, 'prep' => $m->prep_notes, 'done' => $m->status === EngagementMeetingStatus::Done,
                        'summary' => $m->summary,
                    ],
                    detailLines: [],
                );
            });
    }

    /**
     * @param  list<string>  $ids
     * @param  Collection<string, string>  $nomes
     * @return Collection<int, CalendarEvent>
     */
    private function tasks(User $consultor, array $ids, Collection $nomes, CarbonImmutable $inicio, CarbonImmutable $fim): Collection
    {
        return EngagementTask::withoutGlobalScopes()
            ->where('consultant_id', $consultor->id)
            ->whereIn('profile_id', $ids)
            ->where('status', '!=', EngagementTaskStatus::Done->value)
            ->whereBetween('due_date', [$inicio->toDateString(), $fim->toDateString()])
            ->orderBy('due_date')
            ->get()
            ->map(function (EngagementTask $t) use ($nomes): CalendarEvent {
                $cliente = $nomes[$t->profile_id] ?? '—';

                return new CalendarEvent(
                    key: 'tarefa:'.$t->id,
                    title: 'Prazo: '.$t->title,
                    start: CarbonImmutable::parse($t->due_date->toDateString())->startOfDay(),
                    allDay: true,
                    color: self::COLORS[self::TASKS],
                    tag: 'Prazo de tarefa',
                    lines: [$cliente, 'Tarefa do '.($t->owner->value === 'client' ? 'cliente' : 'consultor')],
                    hollow: true,
                    shortTitle: $cliente.': '.$t->title,
                    meta: ['type' => self::TASKS, 'profile_id' => $t->profile_id, 'client' => $cliente, 'description' => $t->description],
                    detailLines: [],
                );
            });
    }

    /** @return Collection<int, CalendarEvent> */
    private function leads(User $profissional, CarbonImmutable $inicio, CarbonImmutable $fim): Collection
    {
        return Lead::query()
            ->where('consultant_id', $profissional->id)
            ->open()
            ->whereBetween('next_action_at', [$inicio->startOfDay(), $fim->endOfDay()])
            ->orderBy('next_action_at')
            ->get()
            ->map(function (Lead $l): CalendarEvent {
                $quando = CarbonImmutable::instance($l->next_action_at);
                // Retorno combinado só para o dia (meia-noite) fica como compromisso de dia inteiro.
                $diaInteiro = $quando->format('H:i:s') === '00:00:00';

                return new CalendarEvent(
                    key: 'lead:'.$l->id,
                    title: 'Retorno: '.$l->name,
                    start: $quando,
                    allDay: $diaInteiro,
                    color: self::COLORS[self::LEADS],
                    tag: 'Lead',
                    lines: array_values(array_filter([$l->name, $l->stageLabel(), $l->phone])),
                    hollow: $diaInteiro,
                    shortTitle: $l->name,
                    meta: ['type' => self::LEADS, 'lead_id' => $l->id, 'phone' => $l->phone, 'email' => $l->email],
                    detailLines: [],
                );
            });
    }
}
