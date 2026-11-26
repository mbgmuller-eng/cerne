<?php

namespace App\Livewire\Activities;

use App\Livewire\Activities\Concerns\ActivityScreen;
use App\Services\Activities\ActivityWeekService;
use App\Services\Activities\ReadinessService;
use App\Services\Activities\RunningService;
use App\Support\Activities\RunningLadder;
use App\Support\Forms\FormConsequence;
use Carbon\CarbonImmutable;
use DomainException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** O programa de caminhada e corrida: começar do que se consegue hoje e avançar semana a semana. */
#[Layout('components.layouts.app')]
class ActivityRunning extends Component
{
    use ActivityScreen;

    public int $test = 1;

    public int $times = 2;

    /** @var list<int|string> */
    public array $weekdays = [];

    public ?int $painToday = 0;

    /** A pessoa leu a recomendação de aguardar e escolheu montar assim mesmo. */
    public bool $anyway = false;

    public function mount(ActivityWeekService $weeks): void
    {
        $this->guardActivityScreen();
        $this->weekdays = array_map('strval', $this->suggestDays($weeks, 2));
    }

    public function updatedTimes(int $vezes, ActivityWeekService $weeks): void
    {
        $this->weekdays = array_map('strval', $this->suggestDays($weeks, max(1, min(3, $vezes))));
    }

    /**
     * Dias da próxima semana que não caem depois das pernas, não ficam colados uns nos outros e, de preferência, já não estão cheios. É só
     * uma sugestão: a pessoa escolhe.
     *
     * @return list<int>
     */
    private function suggestDays(ActivityWeekService $weeks, int $quantos): array
    {
        $itens = $weeks->week(CarbonImmutable::today()->addWeek())['items'];
        $candidatos = collect(range(0, 6))->map(fn (int $d) => [
            'day' => $d,
            'cost' => ($itens->contains(fn (array $i) => $i['legs'] && ($i['weekday'] === $d - 1 || $i['weekday'] === $d)) ? 10 : 0)
                + $itens->where('weekday', $d)->count() * 2,
        ])->sortBy([['cost', 'asc'], ['day', 'desc']]);

        $escolhidos = [];

        foreach ($candidatos as $c) {
            if (count($escolhidos) < $quantos && collect($escolhidos)->every(fn (int $e) => abs($e - $c['day']) > 1)) {
                $escolhidos[] = $c['day'];
            }
        }

        sort($escolhidos);

        return $escolhidos;
    }

    public function start(RunningService $running): void
    {
        $this->validate([
            'test' => ['required', 'integer', 'between:0,3'],
            'times' => ['required', 'integer', 'between:1,3'],
            'weekdays' => ['required', 'array'],
            'weekdays.*' => ['integer', 'between:0,6'],
            'painToday' => ['required', 'integer', 'between:0,10'],
        ], ['weekdays.required' => 'Escolha os dias.'], ['painToday' => 'dor de hoje']);

        try {
            $running->start($this->test, $this->times, $this->weekdays, (int) $this->painToday, $this->anyway);
        } catch (DomainException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        session()->flash('status', 'Programa montado. As sessões aparecem em Minha semana.');
    }

    public function close(string $resultado, RunningService $running): void
    {
        $programa = $running->active();
        abort_if($programa === null, 404);

        try {
            $running->closeWeek($programa, $resultado);
        } catch (DomainException $e) {
            $this->addError('form', $e->getMessage());
        }
    }

    public function stop(RunningService $running): void
    {
        $programa = $running->active();

        if ($programa !== null) {
            $running->stop($programa);
        }

        $this->anyway = false;
    }

    public function render(RunningService $running)
    {
        $programa = $running->active();
        $dias = ActivityWeekService::DAYS;
        $bloqueio = $running->block();
        $prontidao = app(ReadinessService::class)->gate();

        return view('livewire.activities.activity-running', [
            'programa' => $programa,
            'prontidao' => $prontidao,
            'bloqueio' => $bloqueio,
            'passos' => RunningLadder::all(),
            'testes' => RunningLadder::TESTS,
            'dias' => $dias,
            'fechar' => $programa !== null ? $running->weekToClose($programa) : null,
            'semanaAtual' => $programa !== null ? $running->weekSummary($programa, ActivityWeekService::weekStart(CarbonImmutable::today())) : null,
            'resultados' => RunningService::OUTCOMES,
            'historico' => $programa !== null ? $programa->weeks()->limit(8)->get() : collect(),
            'consequencia' => $programa === null && $prontidao === null && ($bloqueio === null || $this->anyway)
                ? FormConsequence::runningStart(RunningLadder::describe(RunningLadder::startFor($this->test)), array_map(fn ($d) => $dias[(int) $d] ?? '', collect($this->weekdays)->sort()->values()->all()), $bloqueio !== null)
                : [],
        ]);
    }
}
