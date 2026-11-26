<?php

namespace App\Livewire\Activities;

use App\Enums\ActivityType;
use App\Livewire\Activities\Concerns\ActivityScreen;
use App\Models\ActivityRecord;
use App\Services\Activities\ActivityService;
use App\Services\Activities\ActivityWeekService;
use App\Services\Activities\PlanReviewService;
use App\Services\Activities\RunningService;
use App\Support\Forms\FormConsequence;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/** "Minha semana": o previsto junto com o feito. O Cerne avisa sobre o encaixe e não move nada sozinho. */
#[Layout('components.layouts.app')]
class ActivityWeek extends Component
{
    use ActivityScreen;

    /** A segunda-feira da semana exibida (Y-m-d); vazio = a atual. */
    #[Url]
    public string $semana = '';

    /** O item aberto para "Fiz". */
    public string $doneRef = '';

    /** O registro aberto para correção. */
    public string $editId = '';

    public string $minutes = '60';

    public ?int $effort = 6;

    public ?int $pain = null;

    public bool $planning = false;

    public string $planType = 'tennis';

    public string $planName = '';

    public string $planDay = '';

    public string $planMinutes = '60';

    public ?int $planEffort = 6;

    public function mount(): void
    {
        $this->guardActivityScreen();
    }

    public function previousWeek(): void
    {
        $this->goTo($this->start()->subWeek());
    }

    public function nextWeek(): void
    {
        // Um mês à frente é o bastante para planejar; mais que isso é só ruído.
        $this->goTo($this->start()->addWeek()->min(ActivityWeekService::weekStart(CarbonImmutable::today())->addWeeks(4)));
    }

    public function currentWeek(): void
    {
        $this->goTo(ActivityWeekService::weekStart(CarbonImmutable::today()));
    }

    private function goTo(CarbonImmutable $inicio): void
    {
        $this->semana = $inicio->isSameDay(ActivityWeekService::weekStart(CarbonImmutable::today())) ? '' : $inicio->toDateString();
        $this->reset('doneRef', 'editId', 'planning');
    }

    private function start(): CarbonImmutable
    {
        $hoje = CarbonImmutable::today();
        $data = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->semana) === 1 ? rescue(fn () => CarbonImmutable::parse($this->semana), $hoje, false) : $hoje;

        // A data vem da URL: fica entre dois anos atrás e um mês à frente.
        return ActivityWeekService::weekStart($data->max($hoje->subYears(2))->min($hoje->addWeeks(4)));
    }

    public function openDone(string $ref, ActivityWeekService $weeks): void
    {
        $item = $weeks->find($this->start(), $ref);
        $this->resetErrorBag();
        $this->doneRef = $ref;
        $this->editId = '';
        $this->planning = false;
        $this->minutes = (string) $item['minutes'];
        $this->effort = $item['effort'] ?? 6;
        $this->pain = null;
    }

    public function saveDone(ActivityWeekService $weeks): void
    {
        $this->validateMeasures();

        try {
            $weeks->confirm($this->start(), $this->doneRef, (int) $this->minutes, $this->effort, $this->pain);
        } catch (DomainException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        $this->doneRef = '';
    }

    public function openEdit(string $recordId): void
    {
        $registro = ActivityRecord::query()->findOrFail($recordId);
        $this->resetErrorBag();
        $this->editId = $registro->id;
        $this->doneRef = '';
        $this->planning = false;
        $this->minutes = (string) $registro->minutes;
        $this->effort = $registro->effort;
        $this->pain = $registro->pain;
    }

    public function saveEdit(ActivityService $activities): void
    {
        $this->validateMeasures();
        $activities->updateDone(ActivityRecord::query()->findOrFail($this->editId), (int) $this->minutes, $this->effort, $this->pain);
        $this->editId = '';
    }

    public function deleteRecord(string $recordId, ActivityService $activities): void
    {
        try {
            $activities->delete(ActivityRecord::query()->findOrFail($recordId));
        } catch (DomainException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        $this->editId = '';
    }

    public function cancel(): void
    {
        $this->reset('doneRef', 'editId', 'planning');
        $this->resetErrorBag();
    }

    public function skip(string $ref, ActivityWeekService $weeks): void
    {
        $weeks->skip($this->start(), $ref);
        $this->doneRef = '';
    }

    public function undoSkip(string $recordId, ActivityWeekService $weeks): void
    {
        $weeks->undoSkip($recordId);
    }

    public function move(string $ref, string $dia, ActivityWeekService $weeks): void
    {
        try {
            $weeks->move($this->start(), $ref, (int) $dia);
        } catch (DomainException $e) {
            $this->addError('form', $e->getMessage());
        }
    }

    public function openPlan(): void
    {
        $this->resetErrorBag();
        $this->reset('planType', 'planName', 'planMinutes', 'planEffort', 'doneRef', 'editId');
        $this->planDay = CarbonImmutable::today()->max($this->start())->toDateString();
        $this->planning = true;
    }

    public function savePlan(ActivityWeekService $weeks): void
    {
        $this->validate([
            'planType' => ['required', Rule::in(ActivityType::values())],
            'planName' => [$this->planType === 'other' ? 'required' : 'nullable', 'string', 'max:60'],
            'planDay' => ['required', 'date', 'after_or_equal:today', 'before:'.CarbonImmutable::today()->addMonths(3)->toDateString()],
            'planMinutes' => ['required', 'integer', 'between:5,600'],
            'planEffort' => ['required', 'integer', 'between:0,10'],
        ], messages: [
            'planName.required' => 'Diga o nome da atividade.',
            'planDay.after_or_equal' => 'Para o que já aconteceu, use Registrar.',
        ], attributes: ['planType' => 'atividade', 'planDay' => 'dia', 'planMinutes' => 'duração', 'planEffort' => 'esforço']);

        $dia = CarbonImmutable::parse($this->planDay);
        $weeks->plan($dia, ['activity' => $this->planType, 'custom_name' => $this->planName, 'minutes' => (int) $this->planMinutes, 'effort' => (int) $this->planEffort]);
        $this->planning = false;
        $this->semana = ActivityWeekService::weekStart($dia)->isSameDay(ActivityWeekService::weekStart(CarbonImmutable::today())) ? '' : ActivityWeekService::weekStart($dia)->toDateString();
    }

    private function validateMeasures(): void
    {
        $this->validate([
            'minutes' => ['required', 'integer', 'between:1,600'],
            'effort' => ['nullable', 'integer', 'between:0,10'],
            'pain' => ['nullable', 'integer', 'between:0,10'],
        ], attributes: ['minutes' => 'duração', 'effort' => 'esforço', 'pain' => 'dor']);
    }

    public function render(ActivityWeekService $weeks, ActivityService $activities, PlanReviewService $review, RunningService $running)
    {
        $semana = $weeks->week($this->start());
        $hoje = CarbonImmutable::today();
        $perfil = $activities->profile();
        $programa = $running->active();
        $item = $this->doneRef !== '' ? $semana['items']->firstWhere('ref', $this->doneRef) : null;

        return view('livewire.activities.activity-week', [
            'dados' => $semana,
            'dias' => ActivityWeekService::DAYS,
            'hoje' => $hoje,
            'atual' => $semana['start']->isSameDay(ActivityWeekService::weekStart($hoje)),
            'pendentes' => $review->pendingCount(),
            'semanaDaCorrida' => $programa !== null ? $running->weekToClose($programa) : null,
            'comecando' => $activities->commitments()->isEmpty() && empty($perfil->gym_days) && ! ActivityRecord::query()->exists(),
            'item' => $item,
            'editando' => $this->editId !== '' ? ActivityRecord::query()->find($this->editId) : null,
            'tipos' => ActivityType::choices(),
            'consequenciaPlano' => $this->planning ? ['A atividade fica marcada no dia como prevista. Você confirma quando fizer.'] : [],
            'consequencia' => $item !== null ? FormConsequence::activityRecord($item['name'], (int) $this->minutes, $this->effort, true) : [],
        ]);
    }
}
