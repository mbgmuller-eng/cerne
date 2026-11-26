<?php

namespace App\Livewire\Activities;

use App\Enums\ActivityType;
use App\Livewire\Activities\Concerns\ActivityScreen;
use App\Services\Activities\ActivityService;
use App\Services\Activities\ActivityWeekService;
use App\Services\Activities\PlanReviewService;
use App\Support\Forms\FormConsequence;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** O perfil de atividades: objetivos em ordem, o que a pessoa já faz, dias da academia e o que a Saúde diz sobre limitações. */
#[Layout('components.layouts.app')]
class ActivityProfilePage extends Component
{
    use ActivityScreen;

    /** @var list<string> */
    public array $goals = [];

    public string $newGoal = '';

    /** @var list<int|string> */
    public array $gymDays = [];

    public string $sessionMinutes = '60';

    public bool $consider = true;

    public bool $showCommitment = false;

    public string $commitmentId = '';

    public string $cType = 'tennis';

    public string $cName = '';

    /** 'fixed' | 'flexible' */
    public string $cKind = 'fixed';

    public string $cWeekday = '1';

    public string $cTimes = '2';

    public string $cMinutes = '60';

    public ?int $cEffort = 6;

    public function mount(ActivityService $activities): void
    {
        $this->guardActivityScreen();

        $perfil = $activities->profile();
        $this->goals = array_values((array) $perfil->goals);
        $this->gymDays = array_map('strval', (array) $perfil->gym_days);
        $this->sessionMinutes = (string) ($perfil->session_minutes ?: 60);
        $this->consider = (bool) $perfil->consider_limitations;
    }

    public function addGoal(): void
    {
        $objetivo = trim($this->newGoal);
        $this->validate(['newGoal' => ['required', 'string', 'max:120']], attributes: ['newGoal' => 'objetivo']);

        if (count($this->goals) < 10) {
            $this->goals[] = $objetivo;
        }

        $this->newGoal = '';
    }

    public function moveGoal(int $i): void
    {
        if ($i > 0 && isset($this->goals[$i])) {
            [$this->goals[$i - 1], $this->goals[$i]] = [$this->goals[$i], $this->goals[$i - 1]];
        }
    }

    public function removeGoal(int $i): void
    {
        if (isset($this->goals[$i])) {
            array_splice($this->goals, $i, 1);
        }
    }

    public function save(ActivityService $activities): void
    {
        $this->validate([
            'goals' => ['array', 'max:10'],
            'goals.*' => ['string', 'max:120'],
            'gymDays' => ['array'],
            'gymDays.*' => ['integer', 'between:0,6'],
            'sessionMinutes' => ['required', 'integer', 'between:15,240'],
        ], attributes: ['sessionMinutes' => 'tempo por treino']);

        // Um objetivo digitado e não adicionado não se perde.
        if (trim($this->newGoal) !== '') {
            $this->addGoal();
        }

        $activities->saveProfile($this->goals, $this->gymDays, (int) $this->sessionMinutes, $this->consider);
        session()->flash('status', 'Perfil salvo.');
    }

    public function openCommitment(string $id, ActivityService $activities): void
    {
        $this->resetErrorBag();
        $this->reset('cType', 'cName', 'cKind', 'cWeekday', 'cTimes', 'cMinutes', 'cEffort');
        $this->commitmentId = '';
        $this->showCommitment = true;

        if ($id === '') {
            return;
        }

        $c = $activities->commitments()->firstWhere('id', $id);
        abort_if($c === null, 404);

        $this->commitmentId = $c->id;
        $this->cType = $c->activity->value;
        $this->cName = (string) $c->custom_name;
        $this->cKind = $c->isFixed() ? 'fixed' : 'flexible';
        $this->cWeekday = (string) ($c->weekday ?? 1);
        $this->cTimes = (string) ($c->times_per_week ?? 2);
        $this->cMinutes = (string) $c->minutes;
        $this->cEffort = $c->effort;
    }

    public function saveCommitment(ActivityService $activities): void
    {
        $this->validate([
            'cType' => ['required', Rule::in(array_diff(ActivityType::values(), ['gym']))],
            'cName' => [$this->cType === 'other' ? 'required' : 'nullable', 'string', 'max:60'],
            'cKind' => ['required', Rule::in(['fixed', 'flexible'])],
            'cWeekday' => [$this->cKind === 'fixed' ? 'required' : 'nullable', 'integer', 'between:0,6'],
            'cTimes' => [$this->cKind === 'flexible' ? 'required' : 'nullable', 'integer', 'between:1,14'],
            'cMinutes' => ['required', 'integer', 'between:5,600'],
            'cEffort' => ['required', 'integer', 'between:0,10'],
        ], ['cName.required' => 'Diga o nome da atividade.', 'cType.in' => 'A academia entra pelos dias preferidos, ao lado.'],
            ['cType' => 'atividade', 'cWeekday' => 'dia', 'cTimes' => 'vezes por semana', 'cMinutes' => 'duração', 'cEffort' => 'esforço']);

        $activities->saveCommitment([
            'activity' => $this->cType, 'custom_name' => $this->cName,
            'weekday' => $this->cKind === 'fixed' ? (int) $this->cWeekday : null,
            'times_per_week' => $this->cKind === 'flexible' ? (int) $this->cTimes : null,
            'minutes' => (int) $this->cMinutes, 'effort' => (int) $this->cEffort,
        ], $this->commitmentId ?: null);

        $this->showCommitment = false;
    }

    public function removeCommitment(string $id, ActivityService $activities): void
    {
        $activities->removeCommitment($id);
        $this->showCommitment = false;
    }

    public function render(ActivityService $activities, PlanReviewService $review)
    {
        $dias = ActivityWeekService::DAYS;

        return view('livewire.activities.activity-profile', [
            'dias' => $dias,
            'tipos' => array_diff_key(ActivityType::choices(), ['gym' => true]),
            'compromissos' => $activities->commitments(),
            'limitacoes' => $review->ownLimitations(),
            'pendentes' => $review->pendingCount(),
            'consequencia' => FormConsequence::activityProfile(array_map(fn ($d) => $dias[(int) $d], collect($this->gymDays)->sort()->values()->all()), $this->consider),
            'consequenciaCompromisso' => $this->showCommitment ? FormConsequence::activityCommitment($this->cKind === 'fixed', $dias[(int) $this->cWeekday] ?? null, (int) $this->cTimes) : [],
        ]);
    }
}
