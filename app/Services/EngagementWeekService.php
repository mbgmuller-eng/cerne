<?php

namespace App\Services;

use App\Enums\ConsultantClientStatus;
use App\Enums\EngagementMeetingStatus;
use App\Enums\EngagementRequestStatus;
use App\Enums\EngagementTaskStatus;
use App\Models\ConsultantClient;
use App\Models\EngagementMeeting;
use App\Models\EngagementRequest;
use App\Models\EngagementTask;
use App\Models\FinancialProfile;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * "Minha semana" do consultor: reuniões, tarefas vencidas, demandas abertas e clientes sem reunião marcada, de todos os
 * clientes com vínculo ativo. Lê sem o escopo de perfil, sempre limitado ao consultor e aos perfis dos clientes ativos dele.
 */
class EngagementWeekService
{
    /** @return array<string, mixed> */
    public function forConsultant(User $consultor, ?CarbonImmutable $hoje = null): array
    {
        $hoje = ($hoje ?? CarbonImmutable::today())->startOfDay();

        $perfis = FinancialProfile::query()
            ->whereIn('owner_user_id', ConsultantClient::query()
                ->where('consultant_id', $consultor->id)
                ->where('status', ConsultantClientStatus::Active)
                ->select('client_id'))
            ->get()
            ->keyBy('id');

        $ids = $perfis->keys()->all();
        $nome = fn (string $profileId) => $perfis[$profileId]->profile_name ?? '—';

        $reunioes = EngagementMeeting::withoutGlobalScopes()
            ->where('consultant_id', $consultor->id)->whereIn('profile_id', $ids)
            ->where('status', EngagementMeetingStatus::Scheduled)
            ->orderBy('scheduled_at')->get();

        $proximas = $reunioes->filter(fn (EngagementMeeting $m) => $m->scheduled_at->gte($hoje));
        $semAta = $reunioes->filter(fn (EngagementMeeting $m) => $m->scheduled_at->lt($hoje));

        $tarefas = EngagementTask::withoutGlobalScopes()
            ->where('consultant_id', $consultor->id)->whereIn('profile_id', $ids)
            ->where('status', '!=', EngagementTaskStatus::Done)
            ->get();

        $vencidas = $tarefas->filter(fn (EngagementTask $t) => $t->due_date !== null && $t->due_date->lt($hoje))->sortBy('due_date')->values();

        $demandas = EngagementRequest::withoutGlobalScopes()
            ->where('consultant_id', $consultor->id)->whereIn('profile_id', $ids)
            ->where('status', EngagementRequestStatus::Open)
            ->oldest()->get();

        $comReuniao = $proximas->pluck('profile_id')->unique();
        $semReuniao = $perfis->reject(fn (FinancialProfile $p) => $comReuniao->contains($p->id))->values();

        return [
            'counts' => [
                'meetings' => $proximas->filter(fn (EngagementMeeting $m) => $m->scheduled_at->lt($hoje->addDays(7)))->count(),
                'overdue' => $vencidas->count(),
                'requests' => $demandas->count(),
                'without_meeting' => $semReuniao->count(),
            ],
            'meetings' => $proximas->take(8)->map(fn (EngagementMeeting $m) => ['profile_id' => $m->profile_id, 'client' => $nome($m->profile_id), 'at' => $m->scheduled_at, 'agenda_ready' => $m->agendaItems() !== []])->values()->all(),
            'awaiting_summary' => $semAta->map(fn (EngagementMeeting $m) => ['profile_id' => $m->profile_id, 'client' => $nome($m->profile_id), 'at' => $m->scheduled_at])->values()->all(),
            'overdue_tasks' => $vencidas->take(10)->map(fn (EngagementTask $t) => ['profile_id' => $t->profile_id, 'client' => $nome($t->profile_id), 'title' => $t->title, 'due' => $t->due_date, 'owner' => $t->owner])->all(),
            'open_requests' => $demandas->take(10)->map(fn (EngagementRequest $d) => ['profile_id' => $d->profile_id, 'client' => $nome($d->profile_id), 'title' => $d->title, 'since' => $d->created_at])->all(),
            'clients_without_meeting' => $semReuniao->map(fn (FinancialProfile $p) => ['profile_id' => $p->id, 'client' => $p->profile_name])->all(),
        ];
    }
}
