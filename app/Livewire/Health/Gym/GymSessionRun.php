<?php

namespace App\Livewire\Health\Gym;

use App\Enums\GymLoadMode;
use App\Enums\GymMeasureType;
use App\Livewire\Concerns\RequiresActiveProfile;
use App\Livewire\Concerns\RequiresPersonalHealth;
use App\Models\GymEquipment;
use App\Models\GymExercise;
use App\Models\GymSession;
use App\Models\GymSetLog;
use App\Models\GymWorkoutExercise;
use App\Services\GymPlanService;
use App\Services\GymProgressService;
use App\Services\GymSessionService;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Tela do treino em andamento: uma série por vez ("Concluir série"), com
 * pausa em contagem regressiva ou cronômetro pros exercícios em tempo.
 *
 * A pausa e o cronômetro rodam no navegador (Alpine, guardando o instante
 * de fim/início) — o servidor só recebe as séries concluídas. Os três
 * interruptores (tela ligada, vibrar, som) são da conta, não do treino.
 */
#[Layout('components.layouts.app')]
class GymSessionRun extends Component
{
    use RequiresActiveProfile, RequiresPersonalHealth;

    public string $sessionId = '';

    /**
     * Campos da PRÓXIMA série de cada exercício, por id do item. Ficam
     * preenchidos depois de concluir uma série: a carga quase sempre se
     * repete na seguinte.
     *
     * @var array<string, array{load: string, mode: string, reps: string, equipment: string, distance: string}>
     */
    public array $draft = [];

    /** @var array<string, bool> exercícios em que a pessoa pediu uma série além da meta */
    public array $extra = [];

    /** @var array<string, string> resumo "última vez" por id do item */
    public array $lastLabel = [];

    /** @var array<string, array{type: string, message: string}> sugestão de progressão por id do item */
    public array $hint = [];

    // Troca de exercício: para as próximas vezes (muda o treino) ou só por hoje (só esta sessão).
    public bool $showSwap = false;
    public ?string $swapItemId = null;
    public string $swapSearch = '';
    public bool $swapAllGroups = false;
    public string $swapScope = 'plan';
    public string $swapCustomName = '';

    public bool $keepAwake = true;
    public bool $vibrate = false;
    public bool $sound = false;

    public function mount(string $session, GymSessionService $service, GymProgressService $progress): void
    {
        $this->redirectOrAbortWithoutProfile();
        $this->abortUnlessPersonalHealthOwner();

        $sessao = GymSession::query()->with('workout.workoutExercises.exercise')->findOrFail($session);
        $this->sessionId = $sessao->id;

        $usuario = auth()->user();
        $this->keepAwake = (bool) ($usuario->gym_keep_awake ?? true);
        $this->vibrate = (bool) $usuario->gym_vibrate;
        $this->sound = (bool) $usuario->gym_sound;

        foreach ($service->applySwaps($sessao, $sessao->workout->workoutExercises) as $item) {
            $this->prepareItem($item, $sessao, $service, $progress);
        }
    }

    /** Carga, última vez e sugestão de progressão do exercício que o item mostra agora (o de hoje, se houve troca). */
    private function prepareItem(GymWorkoutExercise $item, GymSession $sessao, GymSessionService $service, GymProgressService $progress): void
    {
        $ultima = $service->lastPerformance($item->exercise, $sessao);
        // Treino retomado/recarregado: a carga já usada HOJE vale mais que a da última vez.
        $hoje = $service->lastPerformance($item->exercise, only: $sessao) ?? $ultima;

        $this->draft[$item->id] = [
            'load' => $hoje['load'] ?? '',
            'mode' => $hoje['mode'] ?? GymLoadMode::Total->value,
            'reps' => (string) ($item->target_reps_max ?? $item->target_reps_min ?? ''),
            'equipment' => $hoje['equipment_id'] ?? $item->default_equipment_id ?? '',
            'distance' => '',
        ];

        unset($this->hint[$item->id], $this->lastLabel[$item->id]);

        // Progressão dupla / platô, calculados só com treinos JÁ finalizados.
        $sugestao = $progress->analyze($item->exercise, $item)['suggestion'];
        if ($sugestao !== null) {
            $this->hint[$item->id] = ['type' => $sugestao['type'], 'message' => $sugestao['message']];
        }

        if ($ultima !== null && $ultima['label'] !== '') {
            $this->lastLabel[$item->id] = $ultima['label'].' · '.$ultima['date'];
        }
    }
    public function completeSet(string $itemId, int $setNumber, GymSessionService $service): void
    {
        $item = $this->item($itemId);
        abort_if($item->exercise->measure_type->usesStopwatch(), 422);

        $this->validate([
            "draft.$itemId.load" => ['nullable', 'regex:/^\d{1,4}([.,]\d{1,2})?$/'],
            "draft.$itemId.mode" => ['nullable', Rule::in(GymLoadMode::values())],
            "draft.$itemId.reps" => ['required', 'integer', 'min:1', 'max:999'],
            "draft.$itemId.equipment" => ['nullable', 'string'],
        ], [
            "draft.$itemId.reps.required" => 'Informe as repetições.',
            "draft.$itemId.reps.integer" => 'Repetições devem ser um número inteiro.',
            "draft.$itemId.load.regex" => 'Carga inválida (ex.: 14 ou 12,5).',
        ]);

        $d = $this->draft[$itemId];

        $log = $this->guard(fn () => $service->recordSet($this->session(), $item, $setNumber, [
            'load' => $d['load'],
            'mode' => $d['mode'],
            'reps' => (int) $d['reps'],
            'equipment_id' => $this->equipmentId($d['equipment']),
        ]));

        $this->afterSet($log, $item);
    }

    /** Exercício em tempo: a duração vem do cronômetro do navegador. */
    public function completeTimedSet(string $itemId, int $setNumber, int $seconds, GymSessionService $service): void
    {
        $item = $this->item($itemId);
        abort_unless($item->exercise->measure_type->usesStopwatch(), 422);

        $seconds = max(1, min($seconds, 86400));
        $d = $this->draft[$itemId];
        $metros = $item->exercise->measure_type === GymMeasureType::DistanceDuration && preg_match('/^\d{1,6}$/', $d['distance'])
            ? (int) $d['distance']
            : null;

        $log = $this->guard(fn () => $service->recordSet($this->session(), $item, $setNumber, [
            'duration' => $seconds,
            'distance' => $metros,
            'equipment_id' => $this->equipmentId($d['equipment']),
        ]));

        // Cronômetro não tem pausa em contagem regressiva — só marca a série.
        $this->afterSet($log, $item, rest: false);
    }

    public function undoSet(string $itemId, GymSessionService $service): void
    {
        $this->guard(fn () => $service->undoLastSet($this->session(), $this->item($itemId)));
        $this->dispatch('gym-rest-cancel');
    }

    public function addExtraSet(string $itemId): void
    {
        $this->item($itemId);
        $this->extra[$itemId] = true;
    }

    public function finish(GymSessionService $service): void
    {
        if ($this->guard(fn () => $service->finish($this->session())) === false) {
            return;
        }

        $this->redirect(route('health.gym.index'), navigate: true);
    }

    public function discard(GymSessionService $service): void
    {
        $service->discard($this->session());

        $this->redirect(route('health.gym.index'), navigate: true);
    }

    // --- Troca de exercício ---------------------------------------------------------------------------------------

    public function openSwap(string $itemId, GymSessionService $service): void
    {
        $sessao = $this->session();
        abort_unless($sessao->isInProgress(), 422);

        $item = $this->item($itemId);

        if (GymSetLog::query()->where('session_id', $sessao->id)->where('exercise_id', $item->exercise_id)->exists()) {
            $this->addError('session', 'Este exercício já tem séries neste treino. Desfaça as séries antes de trocar.');

            return;
        }

        $this->resetErrorBag();
        $this->reset('swapSearch', 'swapAllGroups', 'swapCustomName');
        $this->swapScope = 'plan';
        $this->swapItemId = $item->id;
        $this->showSwap = true;
    }

    public function closeSwap(): void
    {
        $this->showSwap = false;
    }

    public function swapTo(string $chave, GymPlanService $plano, GymSessionService $service, GymProgressService $progress): void
    {
        try {
            [$novo, $equipamento] = $plano->resolveSwapChoice($chave);
        } catch (DomainException $e) {
            $this->addError('swap', $e->getMessage());

            return;
        }

        $this->applySwap($novo, $equipamento, $plano, $service, $progress);
    }

    /** Digitou um exercício que não está na lista: nasce no grupo e no tipo do que está saindo. */
    public function swapToCustom(GymPlanService $plano, GymSessionService $service, GymProgressService $progress): void
    {
        $this->validate(['swapCustomName' => ['required', 'string', 'max:120']], attributes: ['swapCustomName' => 'nome do exercício']);

        $atual = $this->item((string) $this->swapItemId);
        $novo = $plano->findOrCreateExercise($this->swapCustomName, $atual->exercise->muscle_group, $atual->exercise->measure_type, sugerirAoCatalogo: true);

        $this->applySwap($novo, null, $plano, $service, $progress);
    }

    private function applySwap(GymExercise $novo, ?string $equipamento, GymPlanService $plano, GymSessionService $service, GymProgressService $progress): void
    {
        $sessao = $this->session();
        $doTreino = $this->planItem((string) $this->swapItemId);

        $ok = $this->swapScope === 'today'
            ? $this->guard(fn () => $service->swapToday($sessao, $doTreino, $novo, $equipamento) ?? true)
            : $this->guard(function () use ($plano, $service, $sessao, $doTreino, $novo, $equipamento): bool {
                $plano->swapExercise($doTreino, $novo, $equipamento);
                // A troca definitiva substitui a de hoje, se havia.
                $service->clearSwap($sessao, $doTreino);

                return true;
            });

        if ($ok === false) {
            $this->addError('swap', $this->getErrorBag()->first('session'));
            $this->resetErrorBag('session');

            return;
        }

        $this->showSwap = false;
        $this->prepareItem($this->item($doTreino->id), $sessao, $service, $progress);
    }

    /** Interruptores globais: salvam na conta assim que mudam. */
    public function updatedKeepAwake(): void
    {
        auth()->user()->update(['gym_keep_awake' => $this->keepAwake]);
    }

    public function updatedVibrate(): void
    {
        auth()->user()->update(['gym_vibrate' => $this->vibrate]);
    }

    public function updatedSound(): void
    {
        auth()->user()->update(['gym_sound' => $this->sound]);
    }

    public function render()
    {
        $sessao = $this->session();
        $sessao->load('workout.workoutExercises.exercise', 'workout.workoutExercises.defaultEquipment');
        $servico = app(GymSessionService::class);

        $series = GymSetLog::query()
            ->where('session_id', $sessao->id)
            ->with('equipment')
            ->orderBy('set_number')
            ->get()
            ->groupBy('exercise_id');

        // Em andamento: o treino de hoje (com as trocas só por hoje). Finalizado: o que foi feito de fato, pelas séries
        // gravadas, porque o plano pode ter mudado depois (troca de exercício) e o histórico não pode mudar junto.
        $itens = $sessao->isInProgress()
            ? $servico->applySwaps($sessao, $sessao->workout->workoutExercises)
            : $this->historyRows($sessao, $series);

        $swapItem = $this->showSwap && $this->swapItemId !== null ? $itens->firstWhere('id', $this->swapItemId) : null;

        return view('livewire.health.gym.gym-session-run', [
            'sessao' => $sessao,
            'itens' => $itens,
            'swapItem' => $swapItem,
            'swapOptions' => $swapItem ? app(GymPlanService::class)->swapOptions($swapItem, $this->swapSearch, $this->swapAllGroups) : [],
            'series' => $series,
            'equipamentos' => GymEquipment::query()->orderBy('name')->get(),
            'modos' => GymLoadMode::options(),
            'emAndamento' => $sessao->isInProgress(),
            'totalSeries' => $series->flatten()->count(),
        ]);
    }

    private function afterSet(mixed $log, GymWorkoutExercise $item, bool $rest = true): void
    {
        // null/false = série já gravada (toque duplo), tela desatualizada ou erro:
        // não reinicia a pausa.
        if (! $log instanceof GymSetLog || ! $rest || ! $item->rest_seconds) {
            return;
        }

        $this->dispatch('gym-rest-start', seconds: $item->rest_seconds);
    }

    private function session(): GymSession
    {
        return GymSession::query()->findOrFail($this->sessionId);
    }

    /**
     * Linhas do treino finalizado, uma por exercício que teve série, na ordem em que foram feitos. Usa a meta do item do plano
     * quando o exercício ainda está nele; senão (foi trocado depois), uma linha só com o que foi feito.
     *
     * @param  Collection<string, Collection<int, GymSetLog>>  $series  séries por exercício
     * @return Collection<int, GymWorkoutExercise>
     */
    private function historyRows(GymSession $sessao, Collection $series): Collection
    {
        $doPlano = $sessao->workout->workoutExercises->keyBy('exercise_id');
        $exercicios = GymExercise::query()->whereIn('id', $series->keys())->get()->keyBy('id');

        return $series->map(function (Collection $logs, string $exercicioId) use ($doPlano, $exercicios): ?GymWorkoutExercise {
            $exercicio = $exercicios->get($exercicioId);

            if ($exercicio === null) {
                return null;
            }

            $item = $doPlano->get($exercicioId);

            if ($item === null) {
                $item = new GymWorkoutExercise(['target_sets' => $logs->count()]);
                $item->setAttribute('id', 'feito-'.$exercicioId);
                $item->setRelation('exercise', $exercicio);
            }

            $item->setAttribute('feito_em', $logs->min('completed_at'));

            return $item;
        })->filter()->sortBy(fn (GymWorkoutExercise $i) => $i->getAttribute('feito_em'))->values();
    }

    /** O item do treino já com a troca de hoje aplicada (só na memória): é o que a tela está mostrando. */
    private function item(string $itemId): GymWorkoutExercise
    {
        return app(GymSessionService::class)->applySwaps($this->session(), collect([$this->planItem($itemId)]))->first();
    }

    /** O item como está no plano, sem a troca de hoje: é o que a troca (de hoje ou definitiva) precisa receber. */
    private function planItem(string $itemId): GymWorkoutExercise
    {
        return GymWorkoutExercise::query()
            ->with('exercise')
            ->where('workout_id', $this->session()->workout_id)
            ->findOrFail($itemId);
    }

    /** Equipamento só vale se for da própria pessoa (escopo); id estranho vira "sem equipamento". */
    private function equipmentId(string $id): ?string
    {
        return $id === '' ? null : GymEquipment::query()->whereKey($id)->value('id');
    }

    /** Roda a ação do serviço; regra de negócio violada vira erro na tela e devolve false. */
    private function guard(callable $action): mixed
    {
        try {
            return $action() ?? null;
        } catch (DomainException $e) {
            $this->addError('session', $e->getMessage());

            return false;
        }
    }
}
