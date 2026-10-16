<?php

namespace App\Services;

use App\Enums\EngagementMeetingStatus;
use App\Enums\EngagementTaskStatus;
use App\Models\EngagementMeeting;
use App\Models\EngagementNote;
use App\Models\EngagementPlanStage;
use App\Models\EngagementRequest;
use App\Models\EngagementTask;
use App\Models\FinancialProfile;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;

/**
 * O plano de acompanhamento em PDF: o que o consultor decide entregar ao cliente quando o vínculo termina, ou baixa quando
 * quiser. Nunca inclui notas privadas do consultor. Lê direto pelo perfil e pelo consultor (sem escopo): roda no envio do
 * e-mail, na fila, sem usuário logado.
 */
class EngagementPlanReport
{
    public function hasData(string $profileId, string $consultantId): bool
    {
        foreach ([EngagementMeeting::class, EngagementTask::class, EngagementRequest::class, EngagementPlanStage::class] as $modelo) {
            if ($modelo::withoutGlobalScopes()->where('profile_id', $profileId)->where('consultant_id', $consultantId)->exists()) {
                return true;
            }
        }

        return EngagementNote::withoutGlobalScopes()->where('profile_id', $profileId)->where('consultant_id', $consultantId)->where('is_private', false)->exists();
    }

    /** @return array<string, mixed> */
    public function data(string $profileId, string $consultantId): array
    {
        $perfil = FinancialProfile::query()->findOrFail($profileId);
        $consultor = User::query()->findOrFail($consultantId);

        $relacao = fn (string $modelo) => $modelo::withoutGlobalScopes()->where('profile_id', $profileId)->where('consultant_id', $consultantId);

        $tarefas = $relacao(EngagementTask::class)->orderBy('due_date')->orderBy('created_at')->get();

        return [
            'profileName' => $perfil->profile_name,
            'consultantName' => $consultor->name,
            'stages' => $relacao(EngagementPlanStage::class)->with(['items' => fn ($q) => $q->withoutGlobalScopes()->orderBy('position')])->orderBy('position')->get(),
            'nextMeeting' => $relacao(EngagementMeeting::class)->where('status', EngagementMeetingStatus::Scheduled)->where('scheduled_at', '>=', now())->orderBy('scheduled_at')->first(),
            'openTasks' => $tarefas->filter(fn (EngagementTask $t) => $t->status !== EngagementTaskStatus::Done)->values(),
            'doneTasks' => $tarefas->filter(fn (EngagementTask $t) => $t->status === EngagementTaskStatus::Done)->values(),
            'meetings' => $relacao(EngagementMeeting::class)->where('status', EngagementMeetingStatus::Done)->orderByDesc('scheduled_at')->get(),
            'requests' => $relacao(EngagementRequest::class)->with(['comments' => fn ($q) => $q->withoutGlobalScopes()->with('author')])->orderByDesc('created_at')->get(),
            'notes' => $relacao(EngagementNote::class)->where('is_private', false)->with('author')->orderByDesc('created_at')->get(),
            'generatedAt' => now(),
        ];
    }

    /** O arquivo PDF, em bytes. */
    public function pdf(string $profileId, string $consultantId): string
    {
        return Pdf::loadView('pdf.engagement-plan', $this->data($profileId, $consultantId))->setPaper('a4')->output();
    }

    public function fileName(string $profileName): string
    {
        return 'plano-de-acompanhamento-'.Str::slug($profileName).'.pdf';
    }
}
