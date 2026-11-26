<?php

namespace App\Http\Controllers;

use App\Enums\PlatformModule;
use App\Models\HealthCondition;
use App\Services\Activities\ActivityService;
use App\Services\Activities\ActivityWeekService;
use App\Services\Activities\PlanReviewService;
use App\Services\EntitlementService;
use App\Services\GymPlanService;
use App\Support\ProfileContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * A folha para levar à consulta: a semana habitual e os exercícios do treino atual, com espaço para o profissional marcar o que pode, o que
 * adaptar e o que evitar. Só a própria pessoa gera, porque traz o treino dela.
 */
class ActivitySheetPdfController extends Controller
{
    public function __invoke(
        ProfileContext $contexto,
        EntitlementService $assinaturas,
        ActivityService $atividades,
        PlanReviewService $revisao,
        GymPlanService $planos,
    ): Response {
        abort_if($contexto->profile() === null, 404);
        abort_if($contexto->isConsultant() || $contexto->memberId() === null, 403);
        abort_unless($assinaturas->profileHasModule($contexto->profile(), PlatformModule::Saude), 402);

        $perfil = $atividades->profile();
        $plano = $planos->activePlan();

        $pdf = Pdf::loadView('pdf.activity-sheet', [
            'nome' => $contexto->member()?->name,
            'objetivos' => (array) $perfil->goals,
            'compromissos' => $atividades->commitments(),
            'diasDaAcademia' => array_map(fn ($d) => mb_strtolower(ActivityWeekService::DAYS[(int) $d]), (array) $perfil->gym_days),
            'minutosPorTreino' => $perfil->session_minutes,
            'dias' => ActivityWeekService::DAYS,
            'treinos' => $plano?->workouts()->with('workoutExercises.exercise')->get() ?? collect(),
            'limitacoes' => $revisao->considered(),
            // Só os da própria pessoa (o casal vê os dois na Saúde) e só os que ela marcou que pedem cuidado no exercício.
            'cuidados' => HealthCondition::query()->where('member_id', $contexto->memberId())->where('exercise_care', true)->where('status', '!=', 'resolved')->get(),
            'geradoEm' => now(),
        ]);

        return $pdf->download('folha-de-atividades-'.now()->format('Y-m-d').'.pdf')->header('Cache-Control', 'no-store, private');
    }
}
