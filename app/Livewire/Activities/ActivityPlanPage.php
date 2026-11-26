<?php

namespace App\Livewire\Activities;

use App\Livewire\Activities\Concerns\ActivityScreen;
use App\Services\Activities\ActivityWeekService;
use App\Services\Activities\ProgramUpdateService;
use App\Services\Activities\ReadinessService;
use App\Services\Activities\WeekPlannerService;
use App\Services\HealthMeasurementService;
use App\Support\Forms\FormConsequence;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use DomainException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * O plano da semana e o "Atualizar meu programa". A tela mostra a proposta inteira (dias, minutos, o que muda no treino) e nada muda sem a
 * pessoa aplicar; o que foi aplicado pode ser desfeito.
 */
#[Layout('components.layouts.app')]
class ActivityPlanPage extends Component
{
    use ActivityScreen;

    public string $goal = 'health';

    /** @var list<int|string> dias em que a pessoa pode se exercitar */
    public array $days = [];

    /** @var list<string> */
    public array $types = ['walking'];

    /** @var list<string> treinos em que as séries e repetições mudam */
    public array $adjust = [];

    /** @var list<string> sugestões de pausa aprovadas */
    public array $pauses = [];

    /** Mostra o formulário mesmo com um plano em vigor. */
    public bool $editing = false;

    public function mount(WeekPlannerService $planner, HealthMeasurementService $measurements): void
    {
        $this->guardActivityScreen();
        $atual = $planner->active();

        if ($atual !== null) {
            $this->goal = $atual->goal;
            $dias = [...(array) $atual->strength_days, ...(array) $atual->aerobic_days];
            sort($dias);
            $this->types = array_values((array) $atual->aerobic_types);
        } else {
            $membro = app(ProfileContext::class)->memberId();
            $this->goal = $membro !== null && $measurements->goal($membro) !== null ? 'weight_loss' : 'health';
            $dias = [0, 1, 2, 3, 4, 5];
            $this->editing = true;
        }

        $this->days = array_map('strval', $dias);
        $this->selectEverything();
    }

    public function updatedGoal(): void
    {
        $this->selectEverything();
    }

    /** Vem tudo marcado; quem desmarca é a pessoa. */
    private function selectEverything(): void
    {
        $update = app(ProgramUpdateService::class);
        $leve = ! app(ReadinessService::class)->allowsProgression();
        $this->adjust = array_map('strval', array_keys($update->prescriptionChanges($this->goal, $leve)));
        $this->pauses = array_keys($update->pauses());
    }

    public function edit(): void
    {
        $this->editing = true;
        $this->selectEverything();
    }

    public function apply(ProgramUpdateService $update): void
    {
        $this->validate([
            'goal' => ['required', 'in:'.implode(',', array_keys(WeekPlannerService::GOALS))],
            'days' => ['required', 'array'],
            'days.*' => ['integer', 'between:0,6'],
            'types' => ['required', 'array'],
        ], ['days.required' => 'Marque os dias em que você pode se exercitar.', 'types.required' => 'Escolha ao menos um tipo de aeróbico.'], ['goal' => 'objetivo']);

        try {
            $update->apply($this->goal, array_map('intval', $this->days), $this->types, $this->adjust, $this->pauses);
        } catch (DomainException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        $this->editing = false;
        $this->selectEverything();
        session()->flash('status', 'Programa atualizado. O aeróbico aparece em Minha semana.');
    }

    public function undo(ProgramUpdateService $update, WeekPlannerService $planner): void
    {
        $plano = $planner->active();
        abort_if($plano === null, 404);

        try {
            $update->undo($plano);
        } catch (DomainException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        $this->editing = true;
        $this->selectEverything();
        session()->flash('status', 'Atualização desfeita: o treino e os dias voltaram a ser como antes.');
    }

    public function render(ReadinessService $readiness, WeekPlannerService $planner, ProgramUpdateService $update)
    {
        $respondido = $readiness->answered();
        $leve = ! $readiness->allowsProgression();
        $plano = $planner->active();
        $dias = array_map('intval', $this->days);
        $proposta = $respondido && ($this->editing || $plano === null) && isset(WeekPlannerService::GOALS[$this->goal])
            ? $planner->proposal($this->goal, $dias, $this->types, $leve) : null;
        $mudancas = $proposta !== null ? $update->prescriptionChanges($this->goal, $leve) : [];
        $pausas = $proposta !== null ? $update->pauses() : [];
        $semCoisaNaSemana = $proposta !== null && $proposta['layout']['strength'] === [] && $proposta['layout']['aerobic'] === [];

        $vigente = null;

        if ($plano !== null) {
            $andamento = $planner->progress($plano);
            $inicio = ActivityWeekService::weekStart(CarbonImmutable::today());
            $vigente = [
                'plan' => $plano,
                'step' => $andamento['step'],
                'history' => array_slice($andamento['history'], 0, 8),
                'minutes' => $planner->minutesAt($andamento['step'], $plano->base_minutes, $plano->target_minutes, $plano->is_light),
                'done' => (int) $planner->aerobicRecords($inicio)->sum('minutes'),
                'sessions' => $planner->sessionsFor($plano, $inicio),
                'progression' => $planner->progression($plano->base_minutes, $plano->target_minutes, $plano->is_light),
                // Quando a liberação mudou depois de aplicar, o plano precisa ser refeito para valer.
                'stale' => $respondido && $plano->is_light !== $leve,
            ];
        }

        return view('livewire.activities.activity-plan', [
            'porta' => $respondido ? null : $readiness->gate(),
            'leve' => $leve,
            'proposta' => $proposta,
            'mudancas' => $mudancas,
            'sugestoesDePausa' => $pausas,
            'seriesMantidas' => $proposta !== null && $update->keptPrescription($this->goal, $leve) !== null,
            'vigente' => $vigente,
            'objetivos' => WeekPlannerService::GOALS,
            'tipos' => WeekPlannerService::aerobicTypes(),
            'nomesDosDias' => ActivityWeekService::DAYS,
            'consequencia' => $proposta !== null && ! $semCoisaNaSemana
                ? FormConsequence::weekPlan($proposta, count(array_intersect(array_keys($mudancas), $this->adjust)), count(array_intersect(array_keys($pausas), $this->pauses)), $plano !== null)
                : [],
        ]);
    }
}
