<?php

namespace App\Services\Activities;

use App\Enums\CautionMark;
use App\Enums\GymMeasureType;
use App\Enums\GymMuscleGroup;
use App\Enums\LimitationStatus;
use App\Enums\MovementLoad;
use App\Models\GymExercise;
use App\Models\GymExerciseCatalog;
use App\Models\GymExerciseCaution;
use App\Models\GymPlan;
use App\Models\GymPlanAdjustment;
use App\Models\GymSession;
use App\Models\GymWorkout;
use App\Models\GymWorkoutExercise;
use App\Models\HealthActivity;
use App\Models\HealthActivityExercise;
use App\Models\PhysicalLimitation;
use App\Services\GymPlanService;
use App\Support\GymExerciseClassifier;
use App\Support\ProfileContext;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A revisão do treino diante das limitações da pessoa.
 *
 * A regra que não se quebra: o Cerne TIRA ou ADAPTA por precaução, e só ACRESCENTA o que um profissional indicou (os exercícios digitados no
 * programa do acompanhamento). Em investigação, ele só sugere pausar; trocar passa a ser oferecido depois da orientação. Nada muda sem a
 * pessoa aplicar, e o histórico dos treinos não muda.
 */
class PlanReviewService
{
    private const SECONDS_PER_SET = 45;

    private const DEFAULT_REST = 60;

    private const TRANSITION_SECONDS = 60;

    private const WARM_UP_MINUTES = 5;

    public function __construct(private readonly GymPlanService $plans, private readonly ActivityService $activities) {}

    /** @return Collection<int, PhysicalLimitation> as da própria pessoa (nunca as do cônjuge, mesmo que ela as veja na Saúde) */
    public function ownLimitations(): Collection
    {
        $membro = app(ProfileContext::class)->memberId();

        if ($membro === null || app(ProfileContext::class)->isConsultant()) {
            return collect();
        }

        return PhysicalLimitation::query()->where('member_id', $membro)->with('followup')->orderBy('created_at')->get();
    }

    /** @return Collection<int, PhysicalLimitation> as que os programas levam em conta: ativas, e só se a pessoa não desligou no perfil */
    public function considered(): Collection
    {
        if (! $this->activities->profile()->consider_limitations) {
            return collect();
        }

        return $this->ownLimitations()->filter(fn (PhysicalLimitation $l) => $l->isActive())->values();
    }

    /**
     * O que o treino automático deve deixar de fora.
     *
     * @return array{restrictions: list<\App\Enums\GymRestriction>, avoid: list<MovementLoad>, exclude: list<string>, summary: list<string>}
     */
    public function generatorLimits(): array
    {
        $limitacoes = $this->ownLimitations()->filter(fn (PhysicalLimitation $l) => $l->isActive())->values();
        $restricoes = [];
        $evitar = [];
        $resumo = [];

        foreach ($limitacoes as $l) {
            if (($r = $l->bodyRegion()?->gymRestriction()) !== null) {
                $restricoes[$r->value] = $r;
            }

            foreach ($l->avoidLoads() as $c) {
                $evitar[$c->value] = $c;
            }

            $oQue = array_map(fn (MovementLoad $c) => mb_strtolower($c->label()), $l->avoidLoads());
            $resumo[] = $l->regionLabel().' ('.mb_strtolower($l->status->label()).')'.($oQue !== [] ? ': evitar '.implode(', ', $oQue) : '');
        }

        $excluir = $limitacoes->isEmpty() ? [] : GymExerciseCaution::query()
            ->whereIn('limitation_id', $limitacoes->pluck('id'))->where('mark', CautionMark::Avoid->value)->with('exercise')->get()
            ->map(fn (GymExerciseCaution $c) => GymExerciseClassifier::normalize((string) $c->exercise?->name))->filter()->unique()->values()->all();

        return ['restrictions' => array_values($restricoes), 'avoid' => array_values($evitar), 'exclude' => $excluir, 'summary' => $resumo];
    }

    /**
     * Por que uma limitação marca um exercício, ou nulo se não marca.
     *
     * @param  Collection<string, GymExerciseCaution>  $marcas  pela chave "limitação|exercício"
     * @return array{reason: string, default: string, limitation: PhysicalLimitation}|null
     */
    private function flag(PhysicalLimitation $l, string $exercicioId, string $nome, Collection $marcas): ?array
    {
        $marca = $marcas->get($l->id.'|'.$exercicioId)?->mark;

        if ($marca === CautionMark::Ok || $marca === CautionMark::Kept) {
            return null;
        }

        if ($marca === CautionMark::Avoid) {
            return ['reason' => 'O profissional marcou: evitar.', 'default' => 'pause', 'limitation' => $l];
        }

        if ($marca === CautionMark::Adapt) {
            return ['reason' => 'O profissional marcou: adaptar. Faça como foi orientado ou troque.', 'default' => 'keep', 'limitation' => $l];
        }

        $exige = array_map(fn (MovementLoad $c) => mb_strtolower($c->label()), MovementLoad::hits($nome, $l->avoidLoads()));
        $regiao = $l->bodyRegion()?->gymRestriction();
        $daRegiao = $regiao !== null && GymExerciseClassifier::hitsRestriction($nome, [$regiao]);

        if ($exige === [] && ! $daRegiao) {
            return null;
        }

        $motivos = [];

        if ($exige !== []) {
            $motivos[] = 'exige de: '.implode(' e ', $exige);
        }

        if ($daRegiao) {
            $motivos[] = 'costuma exigir da região ('.mb_strtolower($l->regionLabel()).')';
        }

        return ['reason' => 'Pelo nome, '.implode('; ', $motivos).'.', 'default' => 'pause', 'limitation' => $l];
    }

    /**
     * Tudo o que a revisão propõe para a fase ativa.
     *
     * @return array{
     *     plan: ?GymPlan,
     *     limitations: Collection<int, PhysicalLimitation>,
     *     items: array<string, array<string, mixed>>,
     *     restores: array<string, array<string, mixed>>,
     *     additions: array<string, array<string, mixed>>
     * }
     */
    public function suggestions(): array
    {
        $plano = $this->plans->activePlan();
        $limitacoes = $this->considered();
        $vazio = ['plan' => $plano, 'limitations' => $limitacoes, 'items' => [], 'restores' => [], 'additions' => []];

        if ($plano === null) {
            return $vazio;
        }

        $treinos = $plano->workouts()->with('workoutExercises.exercise')->get();
        $marcas = $limitacoes->isEmpty() ? collect() : GymExerciseCaution::query()->whereIn('limitation_id', $limitacoes->pluck('id'))->get()
            ->keyBy(fn (GymExerciseCaution $c) => $c->limitation_id.'|'.$c->exercise_id);

        $itens = [];

        foreach ($treinos as $treino) {
            foreach ($treino->workoutExercises as $item) {
                $flags = $limitacoes->map(fn (PhysicalLimitation $l) => $this->flag($l, $item->exercise_id, $item->exercise->name, $marcas))->filter()->values();

                if ($flags->isEmpty()) {
                    continue;
                }

                // Trocar só depois de alguém ter orientado: em investigação o caminho é pausar.
                $podeTrocar = $flags->every(fn (array $f) => $f['limitation']->status === LimitationStatus::Guided);

                $itens['i:'.$item->id] = [
                    'item_id' => $item->id,
                    'workout_id' => $treino->id,
                    'name' => $item->exercise->name,
                    'dose' => $this->dose($item),
                    'reasons' => $flags->pluck('reason')->unique()->values()->all(),
                    'default' => $flags->contains(fn (array $f) => $f['default'] === 'pause') ? 'pause' : 'keep',
                    'actions' => $podeTrocar ? ['pause', 'swap', 'keep'] : ['pause', 'keep'],
                    'limitation_ids' => $flags->map(fn (array $f) => $f['limitation']->id)->all(),
                ];
            }
        }

        return [...$vazio, 'items' => $itens, 'restores' => $this->restores($plano), 'additions' => $this->additions($plano, $treinos)];
    }

    /** Quantas sugestões há para decidir. */
    public function pendingCount(): int
    {
        $s = $this->suggestions();

        return count($s['items']) + count($s['restores']) + count($s['additions']);
    }

    /**
     * O que foi pausado, trocado ou reduzido por uma limitação que não está mais ativa pode voltar.
     *
     * @return array<string, array<string, mixed>>
     */
    private function restores(GymPlan $plano): array
    {
        $ativas = $this->ownLimitations()->filter(fn (PhysicalLimitation $l) => $l->isActive())->pluck('id')->all();

        return GymPlanAdjustment::query()->where('plan_id', $plano->id)->whereNull('reverted_at')
            ->whereIn('kind', [GymPlanAdjustment::PAUSED, GymPlanAdjustment::SWAPPED, GymPlanAdjustment::FEWER_SETS])
            ->with(['workout', 'exercise', 'replacement'])->orderBy('created_at')->get()
            ->reject(fn (GymPlanAdjustment $a) => $a->limitation_id !== null && in_array($a->limitation_id, $ativas, true))
            // Voltam na ordem em que estavam no treino: do primeiro lugar para o último.
            ->sortBy(fn (GymPlanAdjustment $a) => [$a->workout_id, (int) ($a->snapshot['position'] ?? 99)])
            ->mapWithKeys(fn (GymPlanAdjustment $a) => ['a:'.$a->id => [
                'adjustment_id' => $a->id,
                'workout_id' => $a->workout_id,
                'workout' => $a->workout?->name,
                'kind' => $a->kind,
                'text' => match ($a->kind) {
                    GymPlanAdjustment::PAUSED => 'Devolver '.$a->exercise?->name.' ao '.$a->workout?->name.'.',
                    GymPlanAdjustment::SWAPPED => 'Voltar '.$a->exercise?->name.' no lugar de '.($a->replacement?->name ?? 'outro exercício').'.',
                    default => 'Voltar às '.($a->snapshot['target_sets'] ?? 3).' séries de '.$a->exercise?->name.'.',
                },
                'default' => 'restore',
            ]])
            ->all();
    }

    /**
     * Os exercícios que um profissional indicou (digitados no programa de um acompanhamento com limitação orientada) e que ainda não estão
     * no treino.
     *
     * @param  Collection<int, GymWorkout>  $treinos
     * @return array<string, array<string, mixed>>
     */
    private function additions(GymPlan $plano, Collection $treinos): array
    {
        $orientadas = $this->considered()->filter(fn (PhysicalLimitation $l) => $l->status === LimitationStatus::Guided);

        if ($orientadas->isEmpty() || $treinos->isEmpty()) {
            return [];
        }

        $jaEntraram = GymPlanAdjustment::query()->where('plan_id', $plano->id)->where('kind', GymPlanAdjustment::ADDED)->whereNull('reverted_at')
            ->pluck('source_exercise_id')->filter()->all();
        $saida = [];

        foreach ($orientadas as $limitacao) {
            $atividades = HealthActivity::query()->where('followup_id', $limitacao->followup_id)->where('member_id', $limitacao->member_id)
                ->where('is_active', true)->with('exercises')->orderBy('created_at')->get();

            foreach ($atividades as $atividade) {
                foreach ($atividade->exercises as $e) {
                    if (in_array($e->id, $jaEntraram, true) || isset($saida['p:'.$e->id])) {
                        continue;
                    }

                    $vezes = max(1, min($treinos->count(), (int) $atividade->per_week));

                    $saida['p:'.$e->id] = [
                        'source_id' => $e->id,
                        'limitation_id' => $limitacao->id,
                        'name' => $e->name,
                        'dose' => (string) $e->dose,
                        'note' => (string) $e->note,
                        'per_week' => (int) $atividade->per_week,
                        'workouts' => $treinos->take($vezes)->pluck('id')->all(),
                    ];
                }
            }
        }

        return $saida;
    }

    /**
     * Opções para trocar um item: do mesmo grupo muscular, sem nenhuma das que alguma limitação considerada marcaria.
     *
     * @return list<array{key: string, name: string, group: string, equipment: ?string, photo: bool, own: bool}>
     */
    public function replacements(GymWorkoutExercise $item, int $limite = 20): array
    {
        $limites = $this->generatorLimits();

        return collect($this->plans->swapOptions($item, limite: 200))
            ->reject(fn (array $o) => MovementLoad::hits($o['name'], $limites['avoid']) !== []
                || GymExerciseClassifier::hitsRestriction($o['name'], $limites['restrictions'])
                || in_array(GymExerciseClassifier::normalize($o['name']), $limites['exclude'], true))
            ->take($limite)->values()->all();
    }

    /**
     * Aplica as decisões da tela. O que não vier decidido segue o sugerido.
     *
     * @param  array<string, array{action?: string, swap?: string, workouts?: list<string>}>  $decisoes
     * @return array{paused: int, swapped: int, kept: int, restored: int, added: int, reduced: int}
     */
    public function apply(array $decisoes, bool $caberNoTempo = true): array
    {
        $s = $this->suggestions();
        $plano = $s['plan'];

        if ($plano === null) {
            throw new DomainException('Não há fase de treino ativa.');
        }

        if (GymSession::query()->inProgress()->whereIn('workout_id', $plano->workouts()->select('id'))->exists()) {
            throw new DomainException('Há um treino em andamento. Termine ou descarte o treino antes de aplicar a revisão.');
        }

        return DB::transaction(function () use ($s, $plano, $decisoes, $caberNoTempo): array {
            $feito = ['paused' => 0, 'swapped' => 0, 'kept' => 0, 'restored' => 0, 'added' => 0, 'reduced' => 0];

            // A posição de cada item ANTES de qualquer mudança: é com ela que o pausado volta ao lugar certo, mesmo quando vários saem juntos.
            $posicoes = GymWorkoutExercise::query()->whereIn('workout_id', $plano->workouts()->select('id'))->pluck('position', 'id')->all();

            foreach ($s['restores'] as $chave => $r) {
                $feito['restored'] += $this->applyRestore($r['adjustment_id'], ($decisoes[$chave]['action'] ?? $r['default']) === 'restore') ? 1 : 0;
            }

            foreach ($s['items'] as $chave => $sugestao) {
                $acao = $decisoes[$chave]['action'] ?? $sugestao['default'];
                $acao = in_array($acao, $sugestao['actions'], true) ? $acao : $sugestao['default'];
                $item = GymWorkoutExercise::query()->with('exercise')->find($sugestao['item_id']);

                if ($item === null) {
                    continue;
                }

                if ($acao === 'keep') {
                    foreach ($sugestao['limitation_ids'] as $limitacao) {
                        GymExerciseCaution::query()->updateOrCreate(
                            ['limitation_id' => $limitacao, 'exercise_id' => $item->exercise_id],
                            ['mark' => CautionMark::Kept],
                        );
                    }
                    $feito['kept']++;

                    continue;
                }

                $base = [
                    'plan_id' => $plano->id, 'workout_id' => $item->workout_id, 'limitation_id' => $sugestao['limitation_ids'][0] ?? null,
                    'exercise_id' => $item->exercise_id,
                    'snapshot' => ['position' => $posicoes[$item->id] ?? $item->position] + $this->snapshot($item),
                ];

                if ($acao === 'swap') {
                    $escolha = (string) ($decisoes[$chave]['swap'] ?? '');
                    $permitidas = array_column($this->replacements($item, 200), 'key');

                    if (! in_array($escolha, $permitidas, true)) {
                        throw new DomainException('Escolha na lista o exercício que entra no lugar de '.$item->exercise->name.'.');
                    }

                    [$novo, $equipamento] = $this->plans->resolveSwapChoice($escolha);
                    $this->plans->swapExercise($item, $novo, $equipamento);
                    GymPlanAdjustment::create($base + ['kind' => GymPlanAdjustment::SWAPPED, 'replacement_exercise_id' => $novo->id]);
                    $feito['swapped']++;

                    continue;
                }

                $this->plans->removeExercise($item);
                GymPlanAdjustment::create($base + ['kind' => GymPlanAdjustment::PAUSED]);
                $feito['paused']++;
            }

            $limitacaoDoBloco = null;

            // De trás para a frente: cada um entra no começo do treino, e assim a ordem da lista se mantém.
            foreach (array_reverse($s['additions'], true) as $chave => $a) {
                $treinos = array_key_exists('workouts', $decisoes[$chave] ?? []) ? (array) $decisoes[$chave]['workouts'] : $a['workouts'];

                foreach ($treinos as $treinoId) {
                    $treino = GymWorkout::query()->where('plan_id', $plano->id)->find($treinoId);

                    if ($treino === null) {
                        continue;
                    }

                    if ($this->addIndicated($treino, $a)) {
                        $feito['added']++;
                        $limitacaoDoBloco = $a['limitation_id'];
                    }
                }
            }

            if ($caberNoTempo && $feito['added'] > 0) {
                $feito['reduced'] = $this->fit($plano, $limitacaoDoBloco);
            }

            return $feito;
        });
    }

    private function applyRestore(string $ajusteId, bool $voltar): bool
    {
        $a = GymPlanAdjustment::query()->with('exercise')->whereNull('reverted_at')->find($ajusteId);

        if ($a === null) {
            return false;
        }

        // "Deixar como está" também encerra a sugestão.
        $a->update(['reverted_at' => now()]);

        if (! $voltar || $a->exercise === null) {
            return false;
        }

        $treino = GymWorkout::query()->find($a->workout_id);

        if ($treino === null) {
            return false;
        }

        $itens = GymWorkoutExercise::query()->with('exercise')->where('workout_id', $treino->id)->get();
        $meta = (array) $a->snapshot;
        $posicao = (int) ($meta['position'] ?? $itens->count() + 1);

        if ($a->kind === GymPlanAdjustment::FEWER_SETS) {
            $itens->firstWhere('exercise_id', $a->exercise_id)?->update(['target_sets' => (int) ($meta['target_sets'] ?? 3)]);

            return true;
        }

        if ($itens->contains('exercise_id', $a->exercise_id)) {
            return false;
        }

        if ($a->kind === GymPlanAdjustment::SWAPPED && ($trocado = $itens->firstWhere('exercise_id', $a->replacement_exercise_id)) !== null) {
            $trocado->update(collect($meta)->except('position')->all() + ['exercise_id' => $a->exercise_id]);

            return true;
        }

        $this->insertAt($treino, $a->exercise, collect($meta)->except('position')->all(), $posicao);

        return true;
    }

    /** @param  array<string, mixed>  $sugestao */
    private function addIndicated(GymWorkout $treino, array $sugestao): bool
    {
        [$meta, $tipo] = self::parseDose($sugestao['dose']);
        $nome = mb_substr(trim($sugestao['name']), 0, 120);
        $catalogo = GymExerciseCatalog::query()->active()->withoutInstructions()->get()
            ->first(fn (GymExerciseCatalog $c) => GymExerciseCatalog::normalizeName($c->name) === GymExerciseCatalog::normalizeName($nome));

        $exercicio = $catalogo !== null
            ? $this->plans->findOrCreateExercise($catalogo->name, $catalogo->muscle_group, $catalogo->measure_type)
            : $this->plans->findOrCreateExercise($nome, GymMuscleGroup::Other, $tipo);

        if ($catalogo !== null) {
            $this->plans->copyCatalogImage($exercicio, $catalogo);
        }

        if (GymWorkoutExercise::query()->where('workout_id', $treino->id)->where('exercise_id', $exercicio->id)->exists()) {
            return false;
        }

        if ($exercicio->measure_type->usesStopwatch()) {
            $meta['target_reps_min'] = $meta['target_reps_max'] = null;
            $meta['target_duration_seconds'] ??= 30;
        } else {
            $meta['target_duration_seconds'] = null;
        }

        // A dose como o profissional escreveu vai na observação: os campos do treino são a leitura dela.
        $meta['notes'] = mb_substr(trim('Indicado pelo profissional'.($sugestao['dose'] !== '' ? ': '.$sugestao['dose'] : '').'. '.$sugestao['note']), 0, 500);

        $this->insertAt($treino, $exercicio, $meta, 1);

        GymPlanAdjustment::create([
            'plan_id' => $treino->plan_id, 'workout_id' => $treino->id, 'limitation_id' => $sugestao['limitation_id'], 'kind' => GymPlanAdjustment::ADDED,
            'exercise_id' => $exercicio->id, 'source_exercise_id' => $sugestao['source_id'],
        ]);

        return true;
    }

    /**
     * "3 × 12", "3 × 10 a 12" ou "3 × 30 s" nos campos do treino. O que não der para ler vira 3 séries de 10 (o texto original segue na
     * observação).
     *
     * @return array{0: array<string, mixed>, 1: GymMeasureType}
     */
    public static function parseDose(string $dose): array
    {
        $texto = mb_strtolower(trim($dose));
        $series = 3;
        $resto = $texto;

        if (preg_match('/^(\d{1,2})\s*(?:×|x|séries? de|series? de)\s*(.*)$/u', $texto, $m)) {
            $series = max(1, min(20, (int) $m[1]));
            $resto = trim($m[2]);
        }

        $meta = ['target_sets' => $series, 'target_reps_min' => 10, 'target_reps_max' => 10, 'target_duration_seconds' => null, 'rest_seconds' => 30, 'default_equipment_id' => null];

        if (preg_match('/(\d{1,4})\s*(s\b|seg|segundos?|min|minutos?)/u', $resto, $t)) {
            $segundos = str_starts_with($t[2], 'min') ? (int) $t[1] * 60 : (int) $t[1];

            return [['target_reps_min' => null, 'target_reps_max' => null, 'target_duration_seconds' => max(5, min(7200, $segundos))] + $meta, GymMeasureType::Duration];
        }

        if (preg_match('/(\d{1,3})\s*(?:a|-)\s*(\d{1,3})/u', $resto, $f)) {
            $meta['target_reps_min'] = (int) min($f[1], $f[2]);
            $meta['target_reps_max'] = (int) max($f[1], $f[2]);
        } elseif (preg_match('/(\d{1,3})/u', $resto, $n)) {
            $meta['target_reps_min'] = $meta['target_reps_max'] = max(1, (int) $n[1]);
        }

        return [$meta, GymMeasureType::LoadReps];
    }

    /** @param  array<string, mixed>  $meta */
    private function insertAt(GymWorkout $treino, GymExercise $exercicio, array $meta, int $posicao): void
    {
        $itens = GymWorkoutExercise::query()->where('workout_id', $treino->id)->orderByDesc('position')->get();
        $posicao = max(1, min($posicao, $itens->count() + 1));

        // De cima para baixo: o índice único (treino, posição) não aceita dois iguais nem por um instante.
        foreach ($itens as $item) {
            if ($item->position >= $posicao) {
                $item->update(['position' => $item->position + 1]);
            }
        }

        GymWorkoutExercise::create($meta + ['workout_id' => $treino->id, 'exercise_id' => $exercicio->id, 'position' => $posicao]);
    }

    /** Tira uma série dos exercícios que não são o foco do esporte até o treino caber no tempo. */
    private function fit(GymPlan $plano, ?string $limitacaoId): int
    {
        $limite = $this->sessionLimit($plano);
        $indicados = GymPlanAdjustment::query()->where('plan_id', $plano->id)->where('kind', GymPlanAdjustment::ADDED)->whereNull('reverted_at')->get()
            ->map(fn (GymPlanAdjustment $a) => $a->workout_id.'|'.$a->exercise_id)->all();
        $reduzidos = 0;

        foreach ($plano->workouts()->get() as $treino) {
            $itens = GymWorkoutExercise::query()->with('exercise')->where('workout_id', $treino->id)->orderByDesc('position')->get();

            foreach ($itens as $item) {
                if ($this->estimateMinutes($itens) <= $limite) {
                    break;
                }

                if ($item->target_sets < 3 || in_array($treino->id.'|'.$item->exercise_id, $indicados, true) || $this->isSportFocus($plano, $item->exercise->name)) {
                    continue;
                }

                GymPlanAdjustment::create([
                    'plan_id' => $plano->id, 'workout_id' => $treino->id, 'limitation_id' => $limitacaoId, 'kind' => GymPlanAdjustment::FEWER_SETS,
                    'exercise_id' => $item->exercise_id, 'snapshot' => $this->snapshot($item),
                ]);
                $item->update(['target_sets' => $item->target_sets - 1]);
                $reduzidos++;
            }
        }

        return $reduzidos;
    }

    private function isSportFocus(GymPlan $plano, string $nome): bool
    {
        if ($plano->sport === null) {
            return false;
        }

        $n = GymExerciseClassifier::normalize($nome);

        foreach ($plano->sport->emphasis() as [$palavra]) {
            if (str_contains($n, $palavra)) {
                return true;
            }
        }

        return false;
    }

    public function sessionLimit(GymPlan $plano): int
    {
        return (int) ($this->activities->profile()->session_minutes ?: ($plano->session_minutes ?: 60));
    }

    /**
     * Estimativa de duração de um treino: aquecimento, cada série com a pausa e a troca de aparelho. É ordem de grandeza.
     *
     * @param  iterable<GymWorkoutExercise>  $itens
     */
    public function estimateMinutes(iterable $itens): int
    {
        $segundos = self::WARM_UP_MINUTES * 60;

        foreach ($itens as $item) {
            $trabalho = $item->target_duration_seconds ?: self::SECONDS_PER_SET;
            $pausa = $item->rest_seconds ?? ($item->target_duration_seconds ? 0 : self::DEFAULT_REST);
            $segundos += max(1, (int) $item->target_sets) * ($trabalho + $pausa) + self::TRANSITION_SECONDS;
        }

        return (int) ceil($segundos / 60);
    }

    private function dose(GymWorkoutExercise $item): string
    {
        if ($item->target_duration_seconds) {
            return $item->target_sets.' × '.$item->target_duration_seconds.' s';
        }

        $reps = collect([$item->target_reps_min, $item->target_reps_max])->filter()->unique()->implode(' a ');

        return $item->target_sets.($reps !== '' ? ' × '.$reps : ' séries');
    }

    /** @return array<string, mixed> */
    private function snapshot(GymWorkoutExercise $item): array
    {
        return $item->only([
            'target_sets', 'target_reps_min', 'target_reps_max', 'target_duration_seconds', 'rest_seconds', 'default_equipment_id', 'notes', 'position',
        ]);
    }
}
