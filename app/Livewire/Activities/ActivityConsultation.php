<?php

namespace App\Livewire\Activities;

use App\Enums\ActivityType;
use App\Enums\CautionMark;
use App\Livewire\Activities\Concerns\ActivityScreen;
use App\Livewire\Health\Concerns\PicksHealthProfessional;
use App\Models\GymExerciseCaution;
use App\Models\HealthProfessional;
use App\Models\PhysicalLimitation;
use App\Services\Activities\ActivityService;
use App\Services\Activities\LimitationService;
use App\Services\Activities\PlanReviewService;
use App\Services\GymPlanService;
use App\Support\Forms\FormConsequence;
use App\Support\ProfileContext;
use DomainException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** A folha para levar à consulta e o registro do que o profissional respondeu. */
#[Layout('components.layouts.app')]
class ActivityConsultation extends Component
{
    use ActivityScreen, PicksHealthProfessional;

    public string $limitationId = '';

    /** @var array<string, string> exercício => ok | adapt | avoid | '' */
    public array $marks = [];

    public bool $allExercises = false;

    public string $running = '';

    /** @var array<string, string> atividade => ok | care | stop | '' */
    public array $sports = [];

    public string $guidance = '';

    public string $professional = '';

    /** @var list<array{name: string, sets: string, reps: string, per_week: int}> */
    public array $exercises = [];

    public string $exName = '';

    public string $exSets = '';

    public string $exReps = '';

    public string $exPerWeek = '2';

    public function mount(PlanReviewService $review): void
    {
        $this->guardActivityScreen();

        $ativa = $review->ownLimitations()->first(fn (PhysicalLimitation $l) => $l->isActive());
        $this->limitationId = $ativa?->id ?? '';
        $this->load($ativa);
    }

    public function updatedLimitationId(PlanReviewService $review): void
    {
        $this->load($this->limitation($review));
    }

    private function load(?PhysicalLimitation $l): void
    {
        $this->reset('marks', 'sports', 'running', 'guidance', 'professional', 'exercises');
        $this->resetErrorBag();

        if ($l === null) {
            return;
        }

        $this->marks = GymExerciseCaution::query()->where('limitation_id', $l->id)->get()
            ->filter(fn (GymExerciseCaution $c) => in_array($c->mark, CautionMark::fromProfessional(), true))
            ->mapWithKeys(fn (GymExerciseCaution $c) => [$c->exercise_id => $c->mark->value])->all();
        $this->running = (string) $l->runningAnswer();
        $this->sports = $l->sportAnswers();
        $this->guidance = (string) $l->guidance;
        $this->professional = (string) $l->professional;
        $this->resetProfessional($l->professional_id, (string) $l->professional === '');
    }

    protected function professionalPersonId(): string
    {
        return (string) app(ProfileContext::class)->memberId();
    }

    protected function applyProfessional(HealthProfessional $profissional): void
    {
        $this->professional = $profissional->name;
    }

    public function addExercise(): void
    {
        $this->validate([
            'exName' => ['required', 'string', 'max:120'],
            'exSets' => ['nullable', 'string', 'max:20'],
            'exReps' => ['nullable', 'string', 'max:40'],
            'exPerWeek' => ['required', 'integer', 'between:1,7'],
        ], ['exName.required' => 'Escreva o nome do exercício.'], ['exSets' => 'séries', 'exReps' => 'repetições ou tempo', 'exPerWeek' => 'vezes por semana']);

        if (count($this->exercises) < 20) {
            $this->exercises[] = ['name' => trim($this->exName), 'sets' => trim($this->exSets), 'reps' => trim($this->exReps), 'per_week' => (int) $this->exPerWeek];
        }

        $this->reset('exName', 'exSets', 'exReps');
    }

    public function removeExercise(int $i): void
    {
        if (isset($this->exercises[$i])) {
            array_splice($this->exercises, $i, 1);
        }
    }

    public function save(LimitationService $limitations, PlanReviewService $review): void
    {
        $limitacao = $this->limitation($review);

        if ($limitacao === null) {
            $this->addError('limitationId', 'Escolha a limitação da consulta.');

            return;
        }

        // Um exercício digitado e não adicionado não se perde.
        if (trim($this->exName) !== '') {
            $this->addExercise();
        }

        $this->validate([
            'guidance' => ['nullable', 'string', 'max:2000'],
            'professional' => ['nullable', 'string', 'max:160'],
            'running' => ['nullable', 'in:,yes,not_yet'],
        ], attributes: ['guidance' => 'orientação', 'professional' => 'profissional']);

        try {
            $limitations->registerConsultation($limitacao, [
                'marks' => array_filter($this->marks),
                'running' => $this->running ?: null,
                'sports' => array_filter($this->sports),
                'guidance' => $this->guidance,
                'professional' => $this->professional,
                'professional_id' => $this->resolveProfessional($limitacao->member_id, $this->professional),
                'exercises' => $this->exercises,
            ], app(ProfileContext::class)->member());
        } catch (DomainException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        session()->flash('status', 'Respostas registradas. Confira as sugestões antes de aplicar.');
        $this->redirect(route('health.activities.review'), navigate: true);
    }

    private function limitation(PlanReviewService $review): ?PhysicalLimitation
    {
        // Sempre entre as da própria pessoa: o id vem do navegador.
        return $this->limitationId === '' ? null : $review->ownLimitations()->firstWhere('id', $this->limitationId);
    }

    public function render(PlanReviewService $review, GymPlanService $plans, ActivityService $activities)
    {
        $limitacao = $this->limitation($review);
        $plano = $plans->activePlan();
        $treinos = $plano?->workouts()->with('workoutExercises.exercise')->get() ?? collect();
        $marcaveis = collect();

        foreach ($treinos as $treino) {
            foreach ($treino->workoutExercises as $item) {
                $e = $item->exercise;
                $exige = $limitacao !== null && (\App\Enums\MovementLoad::hits($e->name, $limitacao->avoidLoads()) !== []
                    || ($limitacao->bodyRegion()?->gymRestriction() !== null && \App\Support\GymExerciseClassifier::hitsRestriction($e->name, [$limitacao->bodyRegion()->gymRestriction()])));

                if ($this->allExercises || $exige || isset($this->marks[$e->id])) {
                    $marcaveis[$e->id] = $treino->name.': '.$e->name;
                }
            }
        }

        $praticados = $activities->commitments()->filter(fn ($c) => $c->activity->isSport() || in_array($c->activity, [ActivityType::Swimming, ActivityType::Cycling, ActivityType::Pilates, ActivityType::Dance], true))
            ->mapWithKeys(fn ($c) => [$c->activity->value => $c->activity->label()]);

        return view('livewire.activities.activity-consultation', [
            'limitacoes' => $review->ownLimitations()->filter(fn (PhysicalLimitation $l) => $l->isActive())->values(),
            'limitacao' => $limitacao,
            'marcaveis' => $marcaveis,
            'temPlano' => $plano !== null,
            'esportes' => $praticados,
            'campoProfissional' => $limitacao !== null ? $this->professionalField($limitacao->member_id, $this->professional) : null,
            'consequencia' => $limitacao !== null ? FormConsequence::consultation(count(array_filter($this->marks)), count($this->exercises)) : [],
        ]);
    }
}
