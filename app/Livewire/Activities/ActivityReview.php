<?php

namespace App\Livewire\Activities;

use App\Livewire\Activities\Concerns\ActivityScreen;
use App\Models\GymPlanAdjustment;
use App\Models\GymWorkoutExercise;
use App\Services\Activities\PlanReviewService;
use App\Support\Forms\FormConsequence;
use DomainException;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** A revisão do treino diante das limitações: a pessoa decide item por item e só então o treino muda. */
#[Layout('components.layouts.app')]
class ActivityReview extends Component
{
    use ActivityScreen;

    /** @var array<string, array{action?: string, swap?: string}> pela chave da sugestão com "_" no lugar de ":" */
    public array $decisions = [];

    /** @var array<string, list<string>> os treinos em que cada exercício indicado entra */
    public array $targets = [];

    public bool $fit = true;

    public function mount(PlanReviewService $review): void
    {
        $this->guardActivityScreen();

        foreach ($review->suggestions()['additions'] as $chave => $a) {
            $this->targets[self::key($chave)] = $a['workouts'];
        }
    }

    public static function key(string $chave): string
    {
        return str_replace(':', '_', $chave);
    }

    public function apply(PlanReviewService $review): void
    {
        $sugestoes = $review->suggestions();
        $decisoes = [];

        foreach (array_keys($sugestoes['items'] + $sugestoes['restores']) as $chave) {
            $decisoes[$chave] = array_filter($this->decisions[self::key($chave)] ?? [], fn ($v) => is_string($v) && $v !== '');
        }

        foreach (array_keys($sugestoes['additions']) as $chave) {
            if (array_key_exists(self::key($chave), $this->targets)) {
                $decisoes[$chave] = ['workouts' => array_values(array_filter((array) $this->targets[self::key($chave)], 'is_string'))];
            }
        }

        try {
            $feito = $review->apply($decisoes, $this->fit);
        } catch (DomainException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        $this->reset('decisions', 'targets');
        $this->resetErrorBag();

        foreach ($review->suggestions()['additions'] as $chave => $a) {
            $this->targets[self::key($chave)] = $a['workouts'];
        }

        session()->flash('status', 'Treino atualizado. O histórico dos treinos não mudou.'.($feito['reduced'] > 0 ? ' Para caber no tempo, '.($feito['reduced'] === 1 ? '1 exercício ficou' : $feito['reduced'].' exercícios ficaram').' com uma série a menos.' : ''));
    }

    public function render(PlanReviewService $review)
    {
        $s = $review->suggestions();
        $plano = $s['plan'];
        $limite = $plano !== null ? $review->sessionLimit($plano) : 60;
        $treinos = [];
        $trocas = [];
        $contagem = ['pause' => 0, 'swap' => 0, 'keep' => 0, 'restore' => 0, 'add' => 0];

        foreach ($s['restores'] as $chave => $r) {
            $contagem['restore'] += ($this->decisions[self::key($chave)]['action'] ?? $r['default']) === 'restore' ? 1 : 0;
        }

        foreach ($plano?->workouts()->with('workoutExercises.exercise')->get() ?? collect() as $treino) {
            $sugeridos = collect($s['items'])->filter(fn (array $i) => $i['workout_id'] === $treino->id);
            $ficam = new Collection;

            foreach ($treino->workoutExercises as $item) {
                $sugestao = $s['items']['i:'.$item->id] ?? null;
                $acao = $sugestao === null ? null : ($this->decisions[self::key('i:'.$item->id)]['action'] ?? $sugestao['default']);

                if ($acao !== null) {
                    $contagem[$acao] = ($contagem[$acao] ?? 0) + 1;
                }

                // A lista de substitutos só é montada para o que a pessoa pediu para trocar.
                if ($acao === 'swap') {
                    $trocas['i:'.$item->id] = $review->replacements(GymWorkoutExercise::query()->with('exercise')->findOrFail($item->id));
                }

                if ($acao !== 'pause') {
                    $ficam->push($item);
                }
            }

            $entram = collect($s['additions'])->filter(fn (array $a, string $chave) => in_array($treino->id, (array) ($this->targets[self::key($chave)] ?? []), true));
            // Cada indicado conta como 3 séries curtas: é só para a ordem de grandeza do tempo.
            $minutos = $review->estimateMinutes($ficam) + $entram->count() * 5;

            $treinos[] = [
                'id' => $treino->id,
                'name' => $treino->name,
                'focus' => $treino->focus,
                'suggested' => $sugeridos,
                'untouched' => $treino->workoutExercises->reject(fn ($i) => isset($s['items']['i:'.$i->id])),
                'incoming' => $entram,
                'minutes' => $minutos,
                'over' => max(0, $minutos - $limite),
            ];
        }

        $contagem['add'] = count(array_filter($s['additions'], fn (array $a, string $chave) => ! empty($this->targets[self::key($chave)] ?? []), ARRAY_FILTER_USE_BOTH));
        $total = count($s['items']) + count($s['restores']) + count($s['additions']);

        return view('livewire.activities.activity-review', [
            'sugestoes' => $s,
            'plano' => $plano,
            'treinos' => $treinos,
            'trocas' => $trocas,
            'total' => $total,
            'limite' => $limite,
            'passaDoTempo' => collect($treinos)->contains(fn (array $t) => $t['over'] > 0),
            'esportes' => $s['limitations']->flatMap(fn ($l) => collect($l->sportAnswers())->map(fn (string $r, string $tipo) => ['type' => $tipo, 'answer' => $r, 'region' => $l->regionLabel()]))->values(),
            'ajustes' => $plano !== null
                ? GymPlanAdjustment::query()->where('plan_id', $plano->id)->with(['exercise', 'replacement', 'workout'])->orderByDesc('created_at')->limit(30)->get()
                : collect(),
            'consequencia' => $total > 0 ? FormConsequence::planReview($contagem) : [],
        ]);
    }
}
