<?php

namespace App\Livewire\Health;

use App\Enums\HealthActivityKind;
use App\Livewire\Concerns\RequiresPersonalHealth;
use App\Models\HealthActivity;
use App\Models\HealthActivityExercise;
use App\Models\HealthActivitySession;
use App\Models\HealthFollowup;
use App\Models\ProfileMember;
use App\Services\HealthActivityService;
use App\Services\HealthFollowupService;
use App\Support\Charts\HealthCharts;
use App\Support\Forms\FormConsequence;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * O programa de atividades de um acompanhamento: o que o profissional orientou, os exercícios (cadastrados à mão), as presenças com nota de dor e
 * a evolução semanal. O Cerne não escolhe exercício para um problema de saúde.
 */
class HealthProgramPanel extends Component
{
    use \App\Livewire\Health\Concerns\PicksHealthProfessional, RequiresPersonalHealth;

    #[Locked]
    public string $followupId = '';

    public string $activityId = '';

    /** '' | program | activity | exercise | session */
    public string $panel = '';

    public string $guidedBy = '';
    public string $guidedOn = '';
    public string $avoid = '';

    public string $kind = 'physio';
    public string $activityName = '';
    public string $perWeek = '2';
    public string $totalSessions = '';
    public string $gymPlanId = '';

    public string $exerciseName = '';
    public string $exerciseSets = '';
    public string $exerciseReps = '';
    public string $exerciseNote = '';

    public string $sessionDate = '';
    public string $sessionPresence = 'yes';
    public ?int $sessionPain = null;
    public string $sessionNote = '';

    /** A presença que veio do treino da Academia e ainda espera a nota de dor. */
    public ?string $painFor = null;
    public ?int $painValue = null;

    public function mount(string $followupId): void
    {
        $this->abortUnlessHealthParticipant();
        $this->followupId = HealthFollowup::query()->findOrFail($followupId)->id;

        $presenca = request()->query('presenca');

        if (is_string($presenca) && $presenca !== '') {
            $achada = HealthActivitySession::query()->whereKey($presenca)
                ->whereIn('activity_id', HealthActivity::query()->where('followup_id', $this->followupId)->select('id'))
                ->first();

            if ($achada !== null) {
                $this->activityId = $achada->activity_id;
                $this->painFor = $achada->pain === null ? $achada->id : null;
            }
        }
    }

    public function open(string $panel): void
    {
        $this->resetErrorBag();
        $this->panel = $this->panel === $panel ? '' : $panel;

        if ($this->panel === 'program') {
            $a = $this->followup();
            $this->guidedBy = (string) $a->guided_by;
            $this->guidedOn = $a->guided_on?->toDateString() ?? '';
            $this->avoid = (string) $a->avoid;
            $this->resetProfessional($a->professional_id, (string) $a->guided_by === '');
        }

        if ($this->panel === 'activity') {
            $this->reset('kind', 'activityName', 'perWeek', 'totalSessions', 'gymPlanId');
        }

        if ($this->panel === 'exercise') {
            $this->reset('exerciseName', 'exerciseSets', 'exerciseReps', 'exerciseNote');
        }

        if ($this->panel === 'session') {
            $this->reset('sessionPresence', 'sessionPain', 'sessionNote');
            $this->sessionDate = CarbonImmutable::today()->toDateString();
        }
    }

    public function selectActivity(string $id): void
    {
        $this->activityId = $this->activity($id)->id;
        $this->panel = '';
        $this->painFor = null;
        $this->resetErrorBag();
    }

    public function saveProgram(HealthFollowupService $service): void
    {
        $this->validate([
            'guidedBy' => ['nullable', 'string', 'max:160'],
            'guidedOn' => ['nullable', 'date', 'before_or_equal:today', 'after:1900-01-01'],
            'avoid' => ['nullable', 'string', 'max:1000'],
        ], attributes: ['guidedBy' => 'quem orientou', 'guidedOn' => 'data da orientação', 'avoid' => 'o que evitar']);

        $acompanhamento = $this->followup();
        $service->saveProgram($acompanhamento, [
            'guided_by' => $this->guidedBy, 'guided_on' => $this->guidedOn, 'avoid' => $this->avoid,
            'professional_id' => $this->resolveProfessional($acompanhamento->member_id, $this->guidedBy),
        ]);
        $this->panel = '';
    }

    protected function professionalPersonId(): string
    {
        return $this->followup()->member_id;
    }

    protected function applyProfessional(\App\Models\HealthProfessional $profissional): void
    {
        $this->guidedBy = $profissional->name;
    }

    public function saveActivity(HealthActivityService $service): void
    {
        $this->validate([
            'kind' => ['required', Rule::enum(HealthActivityKind::class)],
            'activityName' => ['nullable', 'string', 'max:80'],
            'perWeek' => ['required', 'integer', 'between:1,14'],
            'totalSessions' => ['nullable', 'integer', 'between:1,500'],
            'gymPlanId' => ['nullable', 'string'],
        ], attributes: ['kind' => 'tipo', 'activityName' => 'nome', 'perWeek' => 'vezes por semana', 'totalSessions' => 'total de sessões', 'gymPlanId' => 'fase da Academia']);

        try {
            $criada = $service->create($this->followup(), [
                'kind' => $this->kind, 'name' => $this->activityName, 'per_week' => (int) $this->perWeek,
                'total_sessions' => $this->totalSessions !== '' ? (int) $this->totalSessions : null, 'gym_plan_id' => $this->gymPlanId,
            ], $this->author());
        } catch (InvalidArgumentException $e) {
            $this->addError('gymPlanId', $e->getMessage());

            return;
        }

        $this->activityId = $criada->id;
        $this->panel = '';
    }

    public function toggleActivity(string $id, HealthActivityService $service): void
    {
        $atividade = $this->activity($id);
        $service->setActive($atividade, ! $atividade->is_active);
    }

    public function deleteActivity(string $id, HealthActivityService $service): void
    {
        $service->delete($this->activity($id));
        $this->activityId = '';
    }

    public function saveExercise(HealthActivityService $service): void
    {
        $this->validate([
            'exerciseName' => ['required', 'string', 'max:120'],
            'exerciseSets' => ['nullable', 'string', 'max:20'],
            'exerciseReps' => ['nullable', 'string', 'max:40'],
            'exerciseNote' => ['nullable', 'string', 'max:200'],
        ], attributes: ['exerciseName' => 'exercício', 'exerciseSets' => 'séries', 'exerciseReps' => 'repetições ou tempo', 'exerciseNote' => 'observação']);

        $service->addExercise($this->activity($this->activityId), $this->exerciseName, $this->exerciseSets, $this->exerciseReps, $this->exerciseNote, $this->author());
        $this->reset('exerciseName', 'exerciseSets', 'exerciseReps', 'exerciseNote');
    }

    public function removeExercise(string $id, HealthActivityService $service): void
    {
        $service->removeExercise(HealthActivityExercise::query()->where('activity_id', $this->activityId)->findOrFail($id));
    }

    public function saveSession(HealthActivityService $service): void
    {
        $this->validate([
            'sessionDate' => ['required', 'date', 'before_or_equal:today', 'after:2000-01-01'],
            'sessionPresence' => ['required', 'in:yes,no'],
            'sessionPain' => ['nullable', 'integer', 'between:0,10'],
            'sessionNote' => ['nullable', 'string', 'max:500'],
        ], attributes: ['sessionDate' => 'data', 'sessionPresence' => 'presença', 'sessionPain' => 'nota de dor', 'sessionNote' => 'observação']);

        try {
            $service->registerSession($this->activity($this->activityId), $this->sessionDate, $this->sessionPresence === 'yes', $this->sessionPain, $this->sessionNote, $this->author());
        } catch (InvalidArgumentException $e) {
            $this->addError('sessionPain', $e->getMessage());

            return;
        }

        $this->panel = '';
    }

    public function askPain(string $sessionId): void
    {
        $this->painFor = $this->session($sessionId)->id;
        $this->painValue = null;
        $this->panel = '';
    }

    public function savePain(HealthActivityService $service): void
    {
        if ($this->painFor === null || $this->painValue === null || $this->painValue < 0 || $this->painValue > 10) {
            $this->addError('painValue', 'Escolha a nota de dor de 0 a 10.');

            return;
        }

        $this->resetErrorBag();
        $service->setPain($this->session($this->painFor), $this->painValue);
        $this->painFor = null;
        $this->painValue = null;
    }

    public function deleteSession(string $sessionId, HealthActivityService $service): void
    {
        $service->deleteSession($this->session($sessionId));
    }

    public function render(HealthActivityService $service)
    {
        $acompanhamento = $this->followup();
        $atividades = $acompanhamento->activities()->with('exercises')->withCount(['sessions as presencas' => fn ($q) => $q->where('attended', true)])
            ->orderByDesc('is_active')->orderBy('created_at')->get();
        $escolhida = $atividades->firstWhere('id', $this->activityId) ?? $atividades->first();

        if ($escolhida !== null) {
            $this->activityId = $escolhida->id;
            // A fase da Academia só vem para a própria pessoa: para os outros o escopo devolve nada.
            $escolhida->load(['gymPlan', 'createdByUser']);
        }

        $semanas = $service->weekly($atividades->pluck('id')->all());
        $rotulo = fn (array $s) => $s['week']->format('d/m');

        return view('livewire.health.health-program-panel', [
            'campoProfissional' => $this->panel === 'program' ? $this->professionalField($acompanhamento->member_id, $this->guidedBy) : null,
            'acompanhamento' => $acompanhamento,
            'atividades' => $atividades,
            'escolhida' => $escolhida,
            'presencas' => $escolhida !== null ? $escolhida->sessions()->with('createdByUser')->orderByDesc('done_on')->orderByDesc('created_at')->limit(30)->get() : collect(),
            'tipos' => HealthActivityKind::cases(),
            'fases' => $this->panel === 'activity' ? $service->linkableGymPlans($acompanhamento) : collect(),
            'semanas' => $semanas,
            'graficoSessoes' => $semanas !== [] ? HealthCharts::sessions(array_map(fn (array $s) => ['label' => $rotulo($s), 'value' => $s['sessions']], $semanas), 'Sessões por semana') : '',
            'graficoDor' => $semanas !== [] ? HealthCharts::pain(array_map(fn (array $s) => ['label' => $rotulo($s), 'value' => $s['pain']], $semanas), 'Dor média da semana, de 0 a 10') : '',
            'consequencia' => $this->panel === 'session' ? FormConsequence::healthSession($this->sessionPresence === 'yes') : [],
        ]);
    }

    private function followup(): HealthFollowup
    {
        return HealthFollowup::query()->findOrFail($this->followupId);
    }

    /** Sempre dentro do acompanhamento aberto: o id vem do navegador. */
    private function activity(string $id): HealthActivity
    {
        return HealthActivity::query()->where('followup_id', $this->followupId)->findOrFail($id);
    }

    private function session(string $id): HealthActivitySession
    {
        return HealthActivitySession::query()
            ->whereIn('activity_id', HealthActivity::query()->where('followup_id', $this->followupId)->select('id'))
            ->findOrFail($id);
    }

    private function author(): ?ProfileMember
    {
        return app(ProfileContext::class)->member();
    }
}
