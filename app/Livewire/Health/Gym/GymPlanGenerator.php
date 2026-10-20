<?php

namespace App\Livewire\Health\Gym;

use App\Enums\GymEquipmentAccess;
use App\Enums\GymGoal;
use App\Enums\GymLevel;
use App\Enums\GymRestriction;
use App\Enums\PlatformModule;
use App\Livewire\Concerns\RequiresActiveProfile;
use App\Livewire\Concerns\RequiresModule;
use App\Livewire\Concerns\RequiresPersonalHealth;
use App\Services\GymPlanService;
use App\Services\GymWorkoutGenerator;
use App\Support\GymGeneratorInput;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Treino automático: a pessoa responde objetivo, nível, dias, tempo, equipamento e restrições, o gerador monta um rascunho com
 * os exercícios do catálogo e ela revisa (troca, remove) antes de salvar como uma fase nova. Nada é gravado até o "Salvar".
 *
 * Orientação geral, não prescrição de profissional. O rascunho mora na própria tela (sem banco): fechar a página o descarta.
 */
#[Layout('components.layouts.app')]
class GymPlanGenerator extends Component
{
    use RequiresActiveProfile, RequiresModule, RequiresPersonalHealth;

    public string $goal = 'hypertrophy';

    public string $level = 'beginner';

    public int $days = 3;

    public int $minutes = 60;

    public string $access = 'full_gym';

    /** @var list<string> */
    public array $restrictions = [];

    public int $variant = 0;

    /** @var array<string, mixed>|null o rascunho; nulo = ainda no formulário */
    public ?array $draft = null;

    public bool $showSwap = false;

    public ?int $swapWorkout = null;

    public ?int $swapIndex = null;

    public string $swapSearch = '';

    public bool $swapAllGroups = false;

    public function mount(): void
    {
        $this->redirectOrAbortWithoutProfile();
        $this->abortUnlessPersonalHealthOwner();
        // O treino automático termina na Academia, que é do módulo Saúde: sem a assinatura, nem monta.
        $this->abortUnlessModuleEntitled(PlatformModule::Saude);
    }

    public function generate(GymWorkoutGenerator $generator): void
    {
        $this->validate([
            'goal' => ['required', Rule::in(GymGoal::values())],
            'level' => ['required', Rule::in(GymLevel::values())],
            'days' => ['required', 'integer', 'min:2', 'max:6'],
            'minutes' => ['required', Rule::in(GymGeneratorInput::MINUTES)],
            'access' => ['required', Rule::in(GymEquipmentAccess::values())],
            'restrictions' => ['array'],
            'restrictions.*' => [Rule::in(GymRestriction::values())],
        ]);

        $this->variant = 0;
        $this->draft = $generator->generate($this->input());
    }

    /** "Sortear outras opções": mesmo pedido, próximos exercícios da fila de cada espaço. */
    public function regenerate(GymWorkoutGenerator $generator): void
    {
        $this->variant++;
        $this->draft = $generator->generate($this->input());
    }

    public function backToForm(): void
    {
        $this->draft = null;
        $this->showSwap = false;
    }

    public function removeExercise(int $w, int $i): void
    {
        if (! isset($this->draft['workouts'][$w]['exercises'][$i])) {
            return;
        }

        array_splice($this->draft['workouts'][$w]['exercises'], $i, 1);
    }

    public function openSwap(int $w, int $i): void
    {
        abort_unless(isset($this->draft['workouts'][$w]['exercises'][$i]), 404);

        $this->swapWorkout = $w;
        $this->swapIndex = $i;
        $this->reset('swapSearch', 'swapAllGroups');
        $this->showSwap = true;
    }

    public function closeSwap(): void
    {
        $this->showSwap = false;
    }

    public function swapTo(string $catalogId, GymWorkoutGenerator $generator): void
    {
        abort_unless($this->swapWorkout !== null && isset($this->draft['workouts'][$this->swapWorkout]['exercises'][$this->swapIndex]), 404);

        $nova = $generator->rowFor($catalogId, $this->input());

        if ($nova === null) {
            $this->addError('swap', 'Esse exercício não está disponível para o seu pedido.');

            return;
        }

        $linhas = &$this->draft['workouts'][$this->swapWorkout]['exercises'];

        // O aquecimento é recomendado no primeiro exercício do dia: acompanha a posição, não o exercício.
        if ($this->swapIndex === 0) {
            $nova['notes'] = trim(($nova['notes'] ?? '').' '.GymWorkoutGenerator::WARM_UP);
        }

        $linhas[$this->swapIndex] = $nova;
        $this->showSwap = false;
    }

    public function save(GymPlanService $service): void
    {
        $this->validate(['draft.name' => ['required', 'string', 'max:80']], attributes: ['draft.name' => 'nome da fase']);

        $temExercicio = collect($this->draft['workouts'] ?? [])->contains(fn (array $w) => $w['exercises'] !== []);

        if (! $temExercicio) {
            $this->addError('draft', 'O treino ficou sem exercícios. Volte e ajuste o pedido.');

            return;
        }

        $service->createPlanFromDraft($this->draft);

        $this->redirect(route('health.gym.index'), navigate: true);
    }

    public function render(GymPlanService $plano, GymWorkoutGenerator $generator)
    {
        $linha = $this->showSwap && $this->swapWorkout !== null ? ($this->draft['workouts'][$this->swapWorkout]['exercises'][$this->swapIndex] ?? null) : null;
        $jaNoTreino = $linha !== null ? array_column($this->draft['workouts'][$this->swapWorkout]['exercises'], 'catalog_id') : [];

        return view('livewire.health.gym.gym-plan-generator', [
            'objetivos' => GymGoal::cases(),
            'niveis' => GymLevel::options(),
            'acessos' => GymEquipmentAccess::options(),
            'restricoes' => GymRestriction::options(),
            'minutosOpcoes' => GymGeneratorInput::MINUTES,
            'temPlanoAtivo' => $plano->activePlan() !== null,
            'swapLinha' => $linha,
            'swapOptions' => $linha !== null ? $generator->alternatives($linha, $this->input(), $jaNoTreino, $this->swapSearch, $this->swapAllGroups) : [],
        ]);
    }

    private function input(): GymGeneratorInput
    {
        return new GymGeneratorInput(
            GymGoal::from($this->goal),
            GymLevel::from($this->level),
            $this->days,
            $this->minutes,
            GymEquipmentAccess::from($this->access),
            array_map(fn (string $r) => GymRestriction::from($r), array_values(array_intersect($this->restrictions, GymRestriction::values()))),
            $this->variant,
        );
    }
}
