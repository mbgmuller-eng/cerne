<?php

namespace App\Services;

use App\Enums\GymGoal;
use App\Enums\GymLevel;
use App\Enums\PlatformModule;
use App\Models\GymPlan;
use App\Models\GymSession;
use App\Models\ProfileMember;
use App\Notifications\GymPlanReviewDue;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;

/**
 * Há quanto tempo a pessoa está na fase de treino e se já é hora de trocar. A regra junta o TEMPO na fase, que varia com o
 * objetivo, a ESTAGNAÇÃO (metade dos exercícios sem evoluir) e a ADESÃO (de nada adianta trocar um treino que mal se usou).
 *
 * Roda sob o ProfileContext da pessoa (escopo de saúde pessoal); o aviso diário (`notifyDue`) monta esse contexto plano a plano.
 */
class GymPhaseReviewService
{
    /** @var array<string, array{0: int, 1: int}> objetivo => semanas em que a troca começa a valer e em que passa a ser hora */
    public const WEEKS = [
        'hypertrophy' => [8, 12],
        'general_health' => [8, 12],
        'strength' => [6, 8],
        'weight_loss' => [6, 8],
        'endurance' => [6, 8],
    ];

    /** Sem objetivo guardado (plano montado à mão), vale a faixa mais larga. */
    private const DEFAULT_WEEKS = [8, 12];

    /** Iniciante precisa de mais tempo para o corpo se adaptar antes de trocar. */
    private const BEGINNER_EXTRA_WEEKS = 2;

    /** Metade dos exercícios parados é sinal de que o estímulo acabou. */
    private const STAGNATION_SHARE = 0.5;

    /** Poucos exercícios com histórico não dizem nada sobre estagnação. */
    private const STAGNATION_MIN_TRACKED = 3;

    /** Treinou menos que isso do planejado: o tempo na fase não significa que o treino foi usado. */
    private const LOW_ADHERENCE = 0.5;

    public function __construct(private readonly GymProgressService $progress, private readonly GymPlanService $plans) {}

    /**
     * @return array{
     *     plan: GymPlan, weeks: int, sessions: int, goal: ?GymGoal, level: ?GymLevel, soft: int, hard: int,
     *     tracked: int, plateau: int, plateau_share: ?float, planned_per_week: int, expected: int, adherence: ?float,
     *     status: string, headline: string, message: string
     * }|null nulo quando não há fase ativa. Status: ok, soon, due ou slow.
     */
    public function review(?GymPlan $plano = null, ?CarbonImmutable $hoje = null): ?array
    {
        $plano ??= $this->plans->activePlan();

        if ($plano === null) {
            return null;
        }

        $hoje ??= CarbonImmutable::today();
        $inicio = CarbonImmutable::parse($plano->started_on ?? $plano->created_at)->startOfDay();
        $semanas = max(0, intdiv((int) $inicio->diffInDays($hoje), 7));
        $treinos = GymSession::query()->whereNotNull('finished_at')->whereIn('workout_id', $plano->workouts()->select('id'))->count();

        [$suave, $firme] = $this->thresholds($plano->goal, $plano->level);

        $exerciciosDoPlano = $plano->workouts()->with('workoutExercises:id,workout_id,exercise_id')->get()
            ->flatMap(fn ($t) => $t->workoutExercises->pluck('exercise_id'))->unique();
        $linhas = $this->progress->overview()->filter(fn (array $l) => $exerciciosDoPlano->contains($l['exercise']->id) && $l['sessions_count'] >= GymProgressService::MIN_SESSIONS);
        $acompanhados = $linhas->count();
        $parados = $linhas->where('status', 'plateau')->count();
        $parte = $acompanhados > 0 ? $parados / $acompanhados : null;

        $porSemana = max(1, min(6, $plano->days_per_week ?? $plano->workouts()->count()));
        $esperados = $semanas * $porSemana;
        $adesao = $esperados > 0 ? $treinos / $esperados : null;

        $estagnado = $semanas >= 3 && $acompanhados >= self::STAGNATION_MIN_TRACKED && $parte >= self::STAGNATION_SHARE;
        $pouco = $adesao !== null && $adesao < self::LOW_ADHERENCE;

        $status = match (true) {
            $estagnado => 'due',
            $semanas >= $suave && $pouco => 'slow',
            $semanas >= $firme => 'due',
            $semanas >= $suave => 'soon',
            default => 'ok',
        };

        $objetivo = $plano->goal ? mb_strtolower($plano->goal->label()) : null;
        $faixa = "{$suave} a {$firme} semanas";
        $base = "Sua fase \"{$plano->name}\" está há {$semanas} ".($semanas === 1 ? 'semana' : 'semanas')." e você fez {$treinos} ".($treinos === 1 ? 'treino' : 'treinos').'.';

        [$titulo, $mensagem] = match ($status) {
            'due' => $estagnado
                ? ['Hora de renovar o treino', "{$base} {$parados} de {$acompanhados} exercícios estão sem evoluir: é um bom sinal de que o treino precisa mudar."]
                : ['Hora de renovar o treino', "{$base} Para ".($objetivo ?? 'o seu objetivo')." a troca costuma valer entre {$faixa}: já é hora de criar a próxima fase."],
            'soon' => ['Já dá para pensar na próxima fase', "{$base} Você entrou na faixa de troca para ".($objetivo ?? 'o seu objetivo')." ({$faixa}): se o treino estiver ficando fácil, é a hora de planejar o próximo."],
            'slow' => ['Antes de trocar, retome a rotina', "{$base} O planejado seria cerca de {$esperados}. Vale voltar a treinar com regularidade antes de mudar o treino."],
            default => ['Fase em dia', $base],
        };

        if ($plano->goal === null && in_array($status, ['soon', 'due'], true)) {
            $mensagem .= ' Defina o objetivo desta fase para o aviso ficar mais preciso.';
        }

        return [
            'plan' => $plano, 'weeks' => $semanas, 'sessions' => $treinos, 'goal' => $plano->goal, 'level' => $plano->level, 'soft' => $suave, 'hard' => $firme,
            'tracked' => $acompanhados, 'plateau' => $parados, 'plateau_share' => $parte, 'planned_per_week' => $porSemana, 'expected' => $esperados,
            'adherence' => $adesao, 'status' => $status, 'headline' => $titulo, 'message' => $mensagem,
        ];
    }

    /** @return array{0: int, 1: int} */
    public function thresholds(?GymGoal $objetivo, ?GymLevel $nivel): array
    {
        [$suave, $firme] = $objetivo !== null ? (self::WEEKS[$objetivo->value] ?? self::DEFAULT_WEEKS) : self::DEFAULT_WEEKS;
        $extra = $nivel === GymLevel::Beginner ? self::BEGINNER_EXTRA_WEEKS : 0;

        return [$suave + $extra, $firme + $extra];
    }

    /**
     * Depois de meses treinando, o nível que a pessoa marcou pode estar baixo. Sugere o próximo quando há tempo E volume de
     * treinos que sustentem; nunca troca sozinho.
     */
    public function levelSuggestion(?GymLevel $atual, ?CarbonImmutable $hoje = null): ?GymLevel
    {
        $hoje ??= CarbonImmutable::today();
        $primeira = GymSession::query()->whereNotNull('finished_at')->min('performed_on');

        if ($primeira === null || $atual === null || $atual === GymLevel::Advanced) {
            return null;
        }

        $meses = (int) CarbonImmutable::parse($primeira)->diffInMonths($hoje);
        $treinos = GymSession::query()->whereNotNull('finished_at')->count();

        return match ($atual) {
            GymLevel::Beginner => $meses >= 6 && $treinos >= 40 ? GymLevel::Intermediate : null,
            GymLevel::Intermediate => $meses >= 24 && $treinos >= 200 ? GymLevel::Advanced : null,
            default => null,
        };
    }

    /**
     * Rotina diária: avisa UMA vez por fase quem já passou do ponto de trocar. Só o dono recebe (é dado de saúde pessoal) e só
     * com o módulo Saúde. Idempotente pelo livro de lembretes (índice único usuário + fase + tipo).
     *
     * @return int quantos avisos foram enviados
     */
    public function notifyDue(?CarbonImmutable $hoje = null): int
    {
        $enviados = 0;
        $contexto = app(ProfileContext::class);
        $livro = app(ReminderLedger::class);
        $direitos = app(EntitlementService::class);

        GymPlan::withoutGlobalScopes()->where('is_active', true)->with(['member.user'])->chunkById(100, function ($planos) use (&$enviados, $contexto, $livro, $direitos, $hoje): void {
            foreach ($planos as $plano) {
                /** @var ProfileMember|null $membro */
                $membro = $plano->member;
                $usuario = $membro?->user;
                $perfil = $membro?->profile;

                if ($usuario === null || $perfil === null || ! $direitos->profileHasModule($perfil, PlatformModule::Saude)) {
                    continue;
                }

                // As consultas de saúde dependem de quem está no contexto: monta o da pessoa, avalia e solta.
                $contexto->set($perfil, $membro);

                try {
                    $avaliacao = $this->review(GymPlan::query()->find($plano->id), $hoje);
                } finally {
                    $contexto->clear();
                }

                if ($avaliacao === null || $avaliacao['status'] !== 'due') {
                    continue;
                }

                if ($livro->claim($usuario->id, 'gym_plan', $plano->id, 'review_due')) {
                    $usuario->notify(new GymPlanReviewDue($perfil->id, $plano->id, $avaliacao['headline'], $avaliacao['message']));
                    $enviados++;
                }
            }
        });

        return $enviados;
    }
}
