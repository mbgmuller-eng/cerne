<?php

namespace App\Livewire\Health\Gym;

use App\Enums\GymEquipmentAccess;
use App\Enums\GymGoal;
use App\Enums\GymLevel;
use App\Enums\GymRestriction;
use App\Enums\GymSeason;
use App\Enums\GymSport;
use App\Enums\PlatformModule;
use App\Livewire\Concerns\RequiresActiveProfile;
use App\Livewire\Concerns\RequiresModule;
use App\Livewire\Concerns\RequiresPersonalHealth;
use App\Models\GymPlan;
use App\Services\GymPhaseReviewService;
use App\Services\GymPlanService;
use App\Services\GymReportService;
use App\Services\GymWorkoutGenerator;
use App\Support\GymExerciseClassifier;
use App\Support\GymGeneratorInput;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Treino automático em três passos: (1) quem já tem uma fase vê como ela foi e responde "seus objetivos continuam os mesmos?";
 * (2) o pedido (objetivo, nível, dias, tempo, equipamento, restrições); (3) o rascunho, que a pessoa revisa (troca, remove)
 * antes de salvar como fase nova. Nada é gravado até o "Salvar".
 *
 * Orientação geral, não prescrição de profissional. O rascunho mora na própria tela (sem banco): fechar a página o descarta.
 */
#[Layout('components.layouts.app')]
class GymPlanGenerator extends Component
{
    use RequiresActiveProfile, RequiresModule, RequiresPersonalHealth;

    /** 'review' (quem já tem fase), 'form' ou 'draft'. */
    public string $step = 'form';

    /** 'body' (objetivo do corpo) ou 'sport' (a academia reforça um esporte). */
    public string $focus = 'body';

    public string $sport = 'running';

    /** Quantas vezes por semana a pessoa pratica o esporte. */
    public int $sportDays = 3;

    public string $season = 'off_season';

    public string $goal = 'hypertrophy';

    public string $level = 'beginner';

    public int $days = 3;

    public int $minutes = 60;

    public string $access = 'full_gym';

    /** @var list<string> */
    public array $restrictions = [];

    /** Em relação à fase anterior: variar os exercícios, manter os que evoluíram ou não se importar. */
    public string $variety = 'vary';

    /** Aceitou a sugestão de subir de nível (só aparece quando há tempo e treinos que sustentem). */
    public bool $upgradeLevel = false;

    public int $variant = 0;

    /** @var array<string, mixed>|null o rascunho; nulo = ainda não gerado */
    public ?array $draft = null;

    public bool $showSwap = false;

    public ?int $swapWorkout = null;

    public ?int $swapIndex = null;

    public string $swapSearch = '';

    public bool $swapAllGroups = false;

    public function mount(GymPlanService $plans): void
    {
        $this->redirectOrAbortWithoutProfile();
        $this->abortUnlessPersonalHealthOwner();
        // O treino automático termina na Academia, que é do módulo Saúde: sem a assinatura, nem monta.
        $this->abortUnlessModuleEntitled(PlatformModule::Saude);

        $plano = $plans->activePlan();

        if ($plano !== null) {
            $this->step = 'review';
            $this->fillFrom($plano);
        }
    }

    /** Escolher o esporte sugere o objetivo de academia que costuma servir a ele; a pessoa pode trocar. */
    public function updatedFocus(string $valor): void
    {
        if ($valor === 'sport') {
            $this->goal = GymSport::tryFrom($this->sport)?->defaultGoal()->value ?? $this->goal;
        }
    }

    public function updatedSport(string $valor): void
    {
        $this->goal = GymSport::tryFrom($valor)?->defaultGoal()->value ?? $this->goal;
    }

    /** "Sim, os objetivos continuam": gera direto com as respostas da fase atual (e o nível novo, se ela aceitou subir). */
    public function keepGoals(GymPlanService $plans, GymPhaseReviewService $review, GymWorkoutGenerator $generator): void
    {
        $plano = $plans->activePlan();

        // Fase montada à mão não guarda as respostas: não há o que "manter", a pessoa responde agora.
        if ($plano === null || $plano->goal === null) {
            $this->step = 'form';

            return;
        }

        // A fase da temporada é a resposta que mais muda de uma fase para a outra: o que a pessoa marcou na pergunta vale.
        $temporada = $this->season;
        $this->fillFrom($plano);

        if ($plano->sport !== null) {
            $this->season = $temporada;
        }

        if ($this->upgradeLevel && ($proximo = $review->levelSuggestion($plano->level)) !== null) {
            $this->level = $proximo->value;
        }

        $this->generate($generator, $plans);
    }

    /** "Mudou": abre o pedido já preenchido com as respostas anteriores, para ajustar só o que mudou. */
    public function changeGoals(): void
    {
        $this->step = 'form';
    }

    public function generate(GymWorkoutGenerator $generator, GymPlanService $plans): void
    {
        $this->validate([
            'goal' => ['required', Rule::in(GymGoal::values())],
            'level' => ['required', Rule::in(GymLevel::values())],
            'days' => ['required', 'integer', 'min:2', 'max:6'],
            'minutes' => ['required', Rule::in(GymGeneratorInput::MINUTES)],
            'access' => ['required', Rule::in(GymEquipmentAccess::values())],
            'restrictions' => ['array'],
            'restrictions.*' => [Rule::in(GymRestriction::values())],
            'variety' => ['required', Rule::in(['vary', 'keep', 'free'])],
            'focus' => ['required', Rule::in(['body', 'sport'])],
            'sport' => [$this->focus === 'sport' ? 'required' : 'nullable', Rule::in(GymSport::values())],
            'sportDays' => [$this->focus === 'sport' ? 'required' : 'nullable', 'integer', 'min:1', 'max:7'],
            'season' => [$this->focus === 'sport' ? 'required' : 'nullable', Rule::in(GymSeason::values())],
        ], attributes: ['sport' => 'esporte', 'sportDays' => 'vezes por semana no esporte', 'season' => 'fase da temporada']);

        $this->variant = 0;
        $this->draft = $generator->generate($this->input($plans));
        $this->step = 'draft';
    }

    /** "Sortear outras opções": mesmo pedido, próximos exercícios da fila de cada espaço. */
    public function regenerate(GymWorkoutGenerator $generator, GymPlanService $plans): void
    {
        $this->variant++;
        $this->draft = $generator->generate($this->input($plans));
    }

    public function backToForm(): void
    {
        $this->draft = null;
        $this->showSwap = false;
        $this->step = 'form';
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

    public function swapTo(string $catalogId, GymWorkoutGenerator $generator, GymPlanService $plans): void
    {
        abort_unless($this->swapWorkout !== null && isset($this->draft['workouts'][$this->swapWorkout]['exercises'][$this->swapIndex]), 404);

        $nova = $generator->rowFor($catalogId, $this->input($plans));

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
        $this->validate([
            'draft.name' => ['required', 'string', 'max:80'],
            'draft.workouts.*.exercises.*.rest' => ['nullable', 'integer', 'min:0', 'max:600'],
        ], [
            'draft.workouts.*.exercises.*.rest.integer' => 'A pausa deve ser um número inteiro de segundos.',
            'draft.workouts.*.exercises.*.rest.min' => 'A pausa não pode ser negativa.',
            'draft.workouts.*.exercises.*.rest.max' => 'A pausa pode ter no máximo 600 segundos (10 minutos).',
        ], attributes: ['draft.name' => 'nome da fase']);

        // Campo vazio ou zero = sem pausa. Salva sempre número inteiro ou nulo.
        foreach ($this->draft['workouts'] as $w => $treino) {
            foreach ($treino['exercises'] as $i => $e) {
                $pausa = $e['rest'] ?? null;
                $this->draft['workouts'][$w]['exercises'][$i]['rest'] = $pausa === null || $pausa === '' || (int) $pausa === 0 ? null : (int) $pausa;
            }
        }

        $temExercicio = collect($this->draft['workouts'] ?? [])->contains(fn (array $w) => $w['exercises'] !== []);

        if (! $temExercicio) {
            $this->addError('draft', 'O treino ficou sem exercícios. Volte e ajuste o pedido.');

            return;
        }

        $service->createPlanFromDraft($this->draft);

        $this->redirect(route('health.gym.index'), navigate: true);
    }

    public function render(GymPlanService $plans, GymWorkoutGenerator $generator, GymPhaseReviewService $reviews, GymReportService $reports)
    {
        $plano = $plans->activePlan();
        $linha = $this->showSwap && $this->swapWorkout !== null ? ($this->draft['workouts'][$this->swapWorkout]['exercises'][$this->swapIndex] ?? null) : null;
        $jaNoTreino = $linha !== null ? array_column($this->draft['workouts'][$this->swapWorkout]['exercises'], 'catalog_id') : [];

        return view('livewire.health.gym.gym-plan-generator', [
            'objetivos' => GymGoal::cases(),
            'niveis' => GymLevel::options(),
            'acessos' => GymEquipmentAccess::options(),
            'restricoes' => GymRestriction::options(),
            'minutosOpcoes' => GymGeneratorInput::MINUTES,
            'esportes' => GymSport::cases(),
            'temporadas' => GymSeason::cases(),
            'plano' => $plano,
            'temPlanoAtivo' => $plano !== null,
            'revisao' => $this->step === 'review' && $plano !== null ? $this->reviewData($plano, $reviews, $reports) : null,
            'swapLinha' => $linha,
            'swapOptions' => $linha !== null ? $generator->alternatives($linha, $this->input($plans), $jaNoTreino, $this->swapSearch, $this->swapAllGroups) : [],
        ]);
    }

    /**
     * O que a pessoa precisa ver antes de decidir se mantém os objetivos: como foi a fase, o que evoluiu e o que parou.
     *
     * @return array<string, mixed>
     */
    private function reviewData(GymPlan $plano, GymPhaseReviewService $reviews, GymReportService $reports): array
    {
        $revisao = $reviews->review($plano);
        $inicio = CarbonImmutable::parse($plano->started_on ?? $plano->created_at)->startOfDay();
        $relatorio = $reports->report($inicio, CarbonImmutable::today());

        $linhas = collect($relatorio['workouts'])->flatMap(fn (array $t) => $t['exercises'])->unique(fn (array $l) => $l['exercise']->id)
            ->filter(fn (array $l) => $l['sessions_count'] >= 2);

        return [
            'review' => $revisao,
            'improved' => $linhas->filter(fn (array $l) => $l['change_pct'] !== null && bccomp($l['change_pct'], '0', 1) > 0)->count(),
            'measured' => $linhas->count(),
            'top' => $relatorio['highlights']['evolution'],
            'level_suggestion' => $plano->level !== null ? $reviews->levelSuggestion($plano->level) : null,
        ];
    }

    /** Preenche o pedido com as respostas guardadas na fase (o que não está guardado fica como estava). */
    private function fillFrom(GymPlan $plano): void
    {
        $this->goal = $plano->goal?->value ?? $this->goal;
        $this->level = $plano->level?->value ?? $this->level;
        $this->days = $plano->days_per_week ?? $this->days;
        $this->minutes = in_array($plano->session_minutes, GymGeneratorInput::MINUTES, true) ? $plano->session_minutes : $this->minutes;
        $this->access = $plano->equipment_access?->value ?? $this->access;
        $this->restrictions = $plano->restrictions ?? [];
        $this->focus = $plano->sport !== null ? 'sport' : 'body';
        $this->sport = $plano->sport?->value ?? $this->sport;
        $this->sportDays = $plano->sport_days ?? $this->sportDays;
        $this->season = $plano->season?->value ?? $this->season;
    }

    private function input(GymPlanService $plans): GymGeneratorInput
    {
        [$evitar, $manter] = $this->previousPhaseNames($plans);

        return new GymGeneratorInput(
            GymGoal::from($this->goal),
            GymLevel::from($this->level),
            $this->days,
            $this->minutes,
            GymEquipmentAccess::from($this->access),
            array_map(fn (string $r) => GymRestriction::from($r), array_values(array_intersect($this->restrictions, GymRestriction::values()))),
            $this->variant,
            $evitar,
            $manter,
            $this->focus === 'sport' ? GymSport::tryFrom($this->sport) : null,
            $this->focus === 'sport' ? $this->sportDays : 0,
            $this->focus === 'sport' ? GymSeason::tryFrom($this->season) : null,
        );
    }

    /**
     * Os exercícios da fase anterior que o treino novo evita repetir e os que ele prefere manter (os que evoluíram), conforme
     * a escolha da pessoa. Sem fase anterior, ou com "tanto faz", não há nada a evitar nem a manter.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function previousPhaseNames(GymPlanService $plans): array
    {
        $plano = $plans->activePlan();

        if ($plano === null || $this->variety === 'free') {
            return [[], []];
        }

        $nomes = $plans->exerciseNamesOf($plano);

        if ($this->variety === 'vary') {
            return [$nomes, []];
        }

        // Manter: os que melhoraram na fase ficam; os demais variam.
        $inicio = CarbonImmutable::parse($plano->started_on ?? $plano->created_at)->startOfDay();
        $relatorio = app(GymReportService::class)->report($inicio, CarbonImmutable::today());
        $evoluiram = collect($relatorio['workouts'])->flatMap(fn (array $t) => $t['exercises'])
            ->filter(fn (array $l) => $l['sessions_count'] >= 2 && $l['change_pct'] !== null && bccomp($l['change_pct'], '0', 1) > 0)
            ->map(fn (array $l) => GymExerciseClassifier::normalize($l['exercise']->name))->unique()->values()->all();

        return [array_values(array_diff($nomes, $evoluiram)), $evoluiram];
    }
}
