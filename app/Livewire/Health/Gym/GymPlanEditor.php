<?php

namespace App\Livewire\Health\Gym;

use App\Enums\GymMeasureType;
use App\Enums\GymMuscleGroup;
use App\Livewire\Concerns\RequiresActiveProfile;
use App\Livewire\Concerns\RequiresPersonalHealth;
use App\Models\GymEquipment;
use App\Models\GymExercise;
use App\Models\GymExerciseCatalog;
use App\Models\GymWorkout;
use App\Models\GymWorkoutExercise;
use App\Services\GymPlanService;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Montagem da fase de treino: treinos da rotação (A/B/C) e os exercícios
 * de cada um, com meta de séries, faixa de repetições e pausa.
 *
 * Os ids que chegam do navegador são resolvidos por consultas escopadas
 * (PersonalHealthScope): id de outra pessoa dá 404, nunca edita.
 */
#[Layout('components.layouts.app')]
class GymPlanEditor extends Component
{
    use RequiresActiveProfile, RequiresPersonalHealth, WithFileUploads;

    public bool $showPlanForm = false;
    public string $planName = '';

    public string $workoutName = '';
    public string $workoutFocus = '';

    public bool $showExerciseForm = false;
    public ?string $exerciseWorkoutId = null;
    public ?string $editingItemId = null;
    public string $exName = '';
    public string $exGroup = 'chest';
    public string $exType = 'load_reps';
    public string $exSets = '3';
    public string $exRepsMin = '';
    public string $exRepsMax = '';
    public string $exDuration = '';
    public string $exRest = '60';
    public string $exEquipment = '';
    public string $exNotes = '';

    /** @var UploadedFile|null */
    public $exImage = null;
    public bool $removeImage = false;
    public ?string $exImageUrl = null;

    /** Exercício do catálogo compartilhado que bateu com o nome digitado — só em criação nova. */
    public ?string $catalogMatchId = null;
    public ?string $catalogHint = null;

    public function mount(): void
    {
        $this->redirectOrAbortWithoutProfile();
        $this->abortUnlessPersonalHealthOwner();
    }

    public function togglePlanForm(): void
    {
        $this->showPlanForm = ! $this->showPlanForm;
        $this->planName = '';
        $this->resetErrorBag();
    }

    public function savePlan(GymPlanService $service): void
    {
        $this->validate(['planName' => ['required', 'string', 'max:80']]);

        $service->createPlan($this->planName);

        $this->showPlanForm = false;
        $this->planName = '';
    }

    public function addWorkout(GymPlanService $service): void
    {
        $this->validate([
            'workoutName' => ['required', 'string', 'max:80'],
            'workoutFocus' => ['nullable', 'string', 'max:160'],
        ]);

        $plan = $service->activePlan();
        abort_if($plan === null, 422);

        $service->addWorkout($plan, $this->workoutName, $this->workoutFocus);

        $this->reset('workoutName', 'workoutFocus');
    }

    public function deleteWorkout(string $id, GymPlanService $service): void
    {
        try {
            $service->deleteWorkout(GymWorkout::query()->findOrFail($id));
        } catch (DomainException $e) {
            $this->addError('workout', $e->getMessage());
        }
    }

    public function moveWorkout(string $id, int $direction, GymPlanService $service): void
    {
        $service->moveWorkout(GymWorkout::query()->findOrFail($id), $direction < 0 ? -1 : 1);
    }

    public function newExercise(string $workoutId): void
    {
        $this->resetExerciseForm();
        $this->exerciseWorkoutId = GymWorkout::query()->findOrFail($workoutId)->id;
        $this->showExerciseForm = true;
    }

    public function editExercise(string $itemId): void
    {
        $item = GymWorkoutExercise::query()->with('exercise', 'defaultEquipment')->findOrFail($itemId);

        $this->resetExerciseForm();
        $this->editingItemId = $item->id;
        $this->exerciseWorkoutId = $item->workout_id;
        $this->exName = $item->exercise->name;
        $this->exGroup = $item->exercise->muscle_group->value;
        $this->exType = $item->exercise->measure_type->value;
        $this->exSets = (string) $item->target_sets;
        $this->exRepsMin = (string) ($item->target_reps_min ?? '');
        $this->exRepsMax = (string) ($item->target_reps_max ?? '');
        $this->exDuration = (string) ($item->target_duration_seconds ?? '');
        $this->exRest = (string) ($item->rest_seconds ?? '');
        $this->exEquipment = $item->defaultEquipment?->name ?? '';
        $this->exNotes = (string) $item->notes;
        $this->exImageUrl = $item->exercise->imageUrl();
        $this->showExerciseForm = true;
    }

    public function closeExerciseForm(): void
    {
        $this->showExerciseForm = false;
    }

    /**
     * Nome bateu exato com o catálogo compartilhado? Preenche grupo, tipo
     * e pausa sozinho — a pessoa ainda pode mudar tudo antes de salvar.
     * Só em criação nova: editando, nome/grupo/tipo já estão travados.
     */
    public function updatedExName(): void
    {
        if ($this->editingItemId !== null) {
            return;
        }

        $nome = trim($this->exName);
        $catalogo = $nome === ''
            ? null
            : GymExerciseCatalog::query()->active()->whereRaw('LOWER(name) = ?', [mb_strtolower($nome)])->first();

        if ($catalogo === null) {
            $this->catalogMatchId = null;
            $this->catalogHint = null;

            return;
        }

        $this->catalogMatchId = $catalogo->id;
        $this->exGroup = $catalogo->muscle_group->value;
        $this->exType = $catalogo->measure_type->value;
        $this->updatedExType();
        if ($catalogo->equipment_hint !== null && trim($this->exEquipment) === '') {
            $this->exEquipment = $catalogo->equipment_hint;
        }

        $partes = array_filter([$catalogo->muscle_group->label(), $catalogo->equipment_hint]);
        $this->catalogHint = 'Do catálogo — '.implode(' · ', $partes).($catalogo->imageUrl() ? ' · já tem foto' : '');
    }

    public function saveExercise(GymPlanService $service): void
    {
        $timed = GymMeasureType::from($this->exType)->usesStopwatch();

        $this->validate([
            'exName' => ['required', 'string', 'max:120'],
            'exGroup' => ['required', Rule::in(GymMuscleGroup::values())],
            'exType' => ['required', Rule::in(GymMeasureType::values())],
            'exSets' => ['required', 'integer', 'min:1', 'max:20'],
            'exRepsMin' => ['nullable', 'integer', 'min:1', 'max:200'],
            // gte só faz sentido com o mínimo preenchido ('' não compara com número).
            'exRepsMax' => array_merge(['nullable', 'integer', 'min:1', 'max:200'], trim($this->exRepsMin) !== '' ? ['gte:exRepsMin'] : []),
            'exDuration' => $timed ? ['nullable', 'integer', 'min:5', 'max:7200'] : ['nullable'],
            'exRest' => ['nullable', 'integer', 'min:0', 'max:600'],
            'exEquipment' => ['nullable', 'string', 'max:80'],
            'exNotes' => ['nullable', 'string', 'max:500'],
            'exImage' => ['nullable', 'image', 'max:'.config('cerne.gym_images.max_kb')],
        ], attributes: [
            'exName' => 'nome', 'exSets' => 'séries', 'exRepsMin' => 'repetições mínimas',
            'exRepsMax' => 'repetições máximas', 'exDuration' => 'duração', 'exRest' => 'pausa',
            'exEquipment' => 'equipamento', 'exNotes' => 'observações', 'exImage' => 'foto',
        ]);

        $equipment = $service->findOrCreateEquipment($this->exEquipment);
        $meta = [
            'target_sets' => $this->exSets,
            'target_reps_min' => $this->intOrNull($this->exRepsMin),
            'target_reps_max' => $this->intOrNull($this->exRepsMax),
            'target_duration_seconds' => $this->intOrNull($this->exDuration),
            'rest_seconds' => $this->intOrNull($this->exRest),
            'default_equipment_id' => $equipment?->id,
            'notes' => $this->exNotes,
        ];

        if ($this->editingItemId !== null) {
            // Nome, grupo e tipo ficam travados na edição: o exercício é
            // compartilhado com outros treinos e com o histórico.
            $item = GymWorkoutExercise::query()->with('exercise')->findOrFail($this->editingItemId);
            $service->updateExercise($item, $meta);
            $exercise = $item->exercise;
        } else {
            $workout = GymWorkout::query()->findOrFail($this->exerciseWorkoutId);
            $exercise = $service->findOrCreateExercise(
                $this->exName,
                GymMuscleGroup::from($this->exGroup),
                GymMeasureType::from($this->exType),
            );
            $service->addExercise($workout, $exercise, $meta);

            // Sem upload manual: se o nome bateu com o catálogo, herda a
            // foto de lá (só entra em exercício que ainda não tem foto
            // nenhuma — findOrCreateExercise pode ter reaproveitado um já
            // existente, com foto própria, que não pode ser sobrescrita).
            if ($this->catalogMatchId !== null && $this->exImage === null && ! $this->removeImage) {
                $catalogo = GymExerciseCatalog::query()->find($this->catalogMatchId);
                if ($catalogo !== null) {
                    $service->copyCatalogImage($exercise, $catalogo);
                }
            }
        }

        if ($this->exImage !== null || $this->removeImage) {
            $service->setExerciseImage($exercise, $this->exImage, remove: $this->removeImage);
        }

        $this->showExerciseForm = false;
    }

    public function removeExercise(string $itemId, GymPlanService $service): void
    {
        $service->removeExercise(GymWorkoutExercise::query()->findOrFail($itemId));
    }

    public function moveExercise(string $itemId, int $direction, GymPlanService $service): void
    {
        $service->moveExercise(GymWorkoutExercise::query()->findOrFail($itemId), $direction < 0 ? -1 : 1);
    }

    /** Ao mudar o tipo, a pausa some (cronômetro no lugar) ou volta ao padrão. */
    public function updatedExType(): void
    {
        if (GymMeasureType::tryFrom($this->exType)?->usesStopwatch()) {
            $this->exRest = '';
            $this->exRepsMin = $this->exRepsMax = '';
        } elseif ($this->exRest === '') {
            $this->exRest = '60';
        }
    }

    public function render(GymPlanService $service)
    {
        $plan = $service->activePlan();

        return view('livewire.health.gym.gym-plan-editor', [
            'plan' => $plan,
            'workouts' => $plan
                ? $plan->workouts()->with('workoutExercises.exercise', 'workoutExercises.defaultEquipment')->get()
                : collect(),
            // Sugestões do datalist: o que a pessoa já usou + o catálogo compartilhado, sem repetir nome.
            'exerciseNames' => GymExercise::query()->pluck('name')
                ->merge(GymExerciseCatalog::query()->active()->pluck('name'))
                ->unique()->sort()->values(),
            'equipmentNames' => GymEquipment::query()->orderBy('name')->pluck('name'),
            'editingExisting' => $this->editingItemId !== null,
            'timed' => GymMeasureType::tryFrom($this->exType)?->usesStopwatch() ?? false,
        ]);
    }

    private function resetExerciseForm(): void
    {
        $this->reset(
            'editingItemId', 'exerciseWorkoutId', 'exName', 'exGroup', 'exType', 'exSets',
            'exRepsMin', 'exRepsMax', 'exDuration', 'exRest', 'exEquipment', 'exNotes',
            'exImage', 'removeImage', 'exImageUrl', 'catalogMatchId', 'catalogHint',
        );
        $this->resetErrorBag();
    }

    private function intOrNull(string $value): ?int
    {
        return trim($value) === '' ? null : (int) $value;
    }
}
