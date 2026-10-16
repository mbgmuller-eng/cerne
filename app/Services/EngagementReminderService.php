<?php

namespace App\Services;

use App\Enums\EngagementMeetingStatus;
use App\Enums\EngagementTaskOwner;
use App\Enums\EngagementTaskStatus;
use App\Models\EngagementMeeting;
use App\Models\EngagementTask;
use App\Models\FinancialProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lembretes diários do Acompanhamento: reunião no dia seguinte, tarefa que vence amanhã e tarefa que venceu e continua aberta.
 *
 * Idempotente: cada lembrete é "reservado" por um índice único (usuário, assunto, tipo) antes de ser enviado, então o agendador
 * disparando duas vezes não avisa duas vezes. Só avisa quando o vínculo com o consultor está ativo.
 */
class EngagementReminderService
{
    /** Tarefa vencida há mais que isto não gera lembrete (evita despejar o passado na primeira rodada). */
    private const OVERDUE_WINDOW_DAYS = 7;

    public function __construct(
        private readonly EngagementAccess $access,
        private readonly EngagementNotifier $avisos,
    ) {}

    /** @return int quantos lembretes foram enviados */
    public function run(?CarbonImmutable $hoje = null): int
    {
        $hoje = ($hoje ?? CarbonImmutable::today())->startOfDay();
        $enviados = 0;

        $reunioes = EngagementMeeting::withoutGlobalScopes()
            ->where('status', EngagementMeetingStatus::Scheduled)
            ->whereDate('scheduled_at', $hoje->addDay()->toDateString())
            ->get();

        foreach ($reunioes as $reuniao) {
            $enviados += $this->remind(
                $reuniao->profile_id, $reuniao->consultant_id, 'meeting', $reuniao->id, 'meeting_tomorrow', 'both',
                'Reunião amanhã', 'Sua reunião de acompanhamento é amanhã, às '.$reuniao->scheduled_at->format('H:i').'.',
            );
        }

        $abertas = EngagementTask::withoutGlobalScopes()
            ->where('status', '!=', EngagementTaskStatus::Done)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '>=', $hoje->subDays(self::OVERDUE_WINDOW_DAYS)->toDateString())
            ->whereDate('due_date', '<=', $hoje->addDay()->toDateString())
            ->get();

        foreach ($abertas as $tarefa) {
            $amanha = $tarefa->due_date->isSameDay($hoje->addDay());
            $vencida = $tarefa->due_date->lt($hoje);

            if (! $amanha && ! $vencida) {
                continue;
            }

            $enviados += $this->remind(
                $tarefa->profile_id, $tarefa->consultant_id, 'task', $tarefa->id,
                $amanha ? 'task_due_tomorrow' : 'task_overdue',
                $tarefa->owner === EngagementTaskOwner::Client ? 'client' : 'consultant',
                $amanha ? 'Tarefa vence amanhã' : 'Tarefa atrasada',
                $tarefa->title.($amanha ? ' vence amanhã.' : ' venceu em '.$tarefa->due_date->format('d/m').'.'),
            );
        }

        return $enviados;
    }

    private function remind(string $profileId, string $consultantId, string $tipoAssunto, string $assuntoId, string $tipo, string $lado, string $titulo, string $mensagem): int
    {
        $perfil = FinancialProfile::query()->find($profileId);

        // Vínculo encerrado: o histórico fica guardado, mas ninguém é cobrado por ele.
        if ($perfil === null || ! $this->access->activeConsultants($perfil)->pluck('id')->contains($consultantId)) {
            return 0;
        }

        $consultor = User::query()->find($consultantId);
        $usuarios = match ($lado) {
            'client' => $this->access->clientUsers($perfil),
            'consultant' => collect([$consultor])->filter(),
            default => $this->access->clientUsers($perfil)->push($consultor)->filter()->unique('id'),
        };

        $novos = $usuarios->filter(fn (User $u) => $this->claim($u->id, $tipoAssunto, $assuntoId, $tipo))->values();

        $this->avisos->send($novos, $perfil->id, $tipo, $titulo, $mensagem);

        return $novos->count();
    }

    /** Reserva o lembrete: só quem consegue inserir a linha única o envia. */
    private function claim(string $userId, string $tipoAssunto, string $assuntoId, string $tipo): bool
    {
        return DB::table('engagement_reminders')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'subject_type' => $tipoAssunto,
            'subject_id' => $assuntoId,
            'kind' => $tipo,
            'sent_on' => today()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;
    }
}
