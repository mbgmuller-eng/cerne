<?php

namespace App\Livewire\Health\Gym;

use App\Enums\GymLoadMode;
use App\Enums\GymMeasureType;
use App\Livewire\Concerns\RequiresActiveProfile;
use App\Livewire\Concerns\RequiresPersonalHealth;
use App\Models\GymEquipment;
use App\Models\GymSession;
use App\Models\GymSetLog;
use App\Models\GymWorkoutExercise;
use App\Services\GymProgressService;
use App\Services\GymSessionService;
use DomainException;
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

        foreach ($sessao->workout->workoutExercises as $item) {
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

            // Progressão dupla / platô, calculados só com treinos JÁ finalizados.
            $sugestao = $progress->analyze($item->exercise, $item)['suggestion'];
            if ($sugestao !== null) {
                $this->hint[$item->id] = ['type' => $sugestao['type'], 'message' => $sugestao['message']];
            }

            if ($ultima !== null && $ultima['label'] !== '') {
                $this->lastLabel[$item->id] = $ultima['label'].' · '.$ultima['date'];
            }
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

        $series = GymSetLog::query()
            ->where('session_id', $sessao->id)
            ->with('equipment')
            ->orderBy('set_number')
            ->get()
            ->groupBy('exercise_id');

        return view('livewire.health.gym.gym-session-run', [
            'sessao' => $sessao,
            'itens' => $sessao->workout->workoutExercises,
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

    private function item(string $itemId): GymWorkoutExercise
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
