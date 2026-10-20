<?php

namespace App\Services;

use App\Enums\GymEquipmentAccess;
use App\Enums\GymGoal;
use App\Enums\GymLevel;
use App\Models\GymExerciseCatalog;
use App\Support\GymExerciseClassifier as C;
use App\Support\GymGeneratorInput;
use Illuminate\Support\Collection;

/**
 * Monta um rascunho de fase de treino a partir do objetivo, do nível, dos dias por semana, do tempo, do equipamento e das
 * restrições da pessoa, usando só os exercícios do catálogo do Cerne. Regras fixas, sem IA: mesmo pedido, mesmo treino
 * (o "variant" gira para as próximas opções).
 *
 * Só devolve o rascunho: quem salva é a pessoa, depois de revisar e trocar o que quiser (ver GymPlanGenerator). Nada aqui
 * grava no banco. É orientação geral, não prescrição de profissional.
 *
 * Estrutura: divisão pelos dias (corpo inteiro, superior e inferior, empurrar/puxar/pernas), cada treino com seus "espaços"
 * (grupo muscular + composto ou isolado, em ordem de importância), e a prescrição (séries, repetições e descanso) pelo objetivo.
 */
class GymWorkoutGenerator
{
    public const WARM_UP = 'Faça 1 a 2 séries de aquecimento com carga leve antes das séries de trabalho.';

    /** Quantos exercícios cabem em cada treino, pelo tempo. */
    private const EXERCISES_BY_MINUTES = [30 => 4, 45 => 6, 60 => 7, 90 => 9];

    /** @var array<string, list<array{0: string, 1: string}>> espaços de cada tipo de treino: [grupo, tipo] */
    private const SLOTS = [
        'full_a' => [['legs', 'compound'], ['chest', 'compound'], ['back', 'compound'], ['shoulders', 'any'], ['core', 'any'], ['glutes', 'any'], ['arms', 'biceps'], ['arms', 'triceps'], ['legs', 'isolation']],
        'full_b' => [['back', 'compound'], ['legs', 'compound'], ['shoulders', 'compound'], ['chest', 'isolation'], ['core', 'any'], ['glutes', 'any'], ['arms', 'triceps'], ['arms', 'biceps'], ['legs', 'isolation']],
        'full_c' => [['legs', 'compound'], ['chest', 'compound'], ['back', 'compound'], ['shoulders', 'isolation'], ['core', 'any'], ['legs', 'isolation'], ['arms', 'biceps'], ['arms', 'triceps'], ['glutes', 'any']],
        'upper' => [['chest', 'compound'], ['back', 'compound'], ['shoulders', 'compound'], ['back', 'any'], ['chest', 'isolation'], ['arms', 'biceps'], ['arms', 'triceps'], ['shoulders', 'isolation'], ['core', 'any']],
        'lower' => [['legs', 'compound'], ['legs', 'compound'], ['glutes', 'any'], ['legs', 'isolation'], ['legs', 'isolation'], ['core', 'any'], ['legs', 'calf'], ['glutes', 'any'], ['core', 'any']],
        'push' => [['chest', 'compound'], ['shoulders', 'compound'], ['chest', 'compound'], ['shoulders', 'isolation'], ['chest', 'isolation'], ['arms', 'triceps'], ['core', 'any'], ['arms', 'triceps'], ['shoulders', 'isolation']],
        'pull' => [['back', 'compound'], ['back', 'compound'], ['back', 'any'], ['shoulders', 'isolation'], ['arms', 'biceps'], ['core', 'any'], ['arms', 'biceps'], ['back', 'isolation'], ['shoulders', 'isolation']],
        'legs' => [['legs', 'compound'], ['legs', 'compound'], ['glutes', 'any'], ['legs', 'isolation'], ['legs', 'isolation'], ['legs', 'calf'], ['core', 'any'], ['glutes', 'any'], ['core', 'any']],
    ];

    /** @var array<string, string> como o treino é chamado na tela */
    private const FOCUS = [
        'full_a' => 'Corpo inteiro', 'full_b' => 'Corpo inteiro', 'full_c' => 'Corpo inteiro', 'upper' => 'Parte superior',
        'lower' => 'Parte inferior', 'push' => 'Empurrar: peito, ombros e tríceps', 'pull' => 'Puxar: costas e bíceps', 'legs' => 'Pernas e glúteos',
    ];

    /** @var array<int, list<string>> a divisão pelos dias por semana */
    private const SPLITS = [
        2 => ['full_a', 'full_b'],
        3 => ['full_a', 'full_b', 'full_c'],
        4 => ['upper', 'lower', 'upper', 'lower'],
        5 => ['push', 'pull', 'legs', 'upper', 'lower'],
        6 => ['push', 'pull', 'legs', 'push', 'pull', 'legs'],
    ];

    /**
     * @return array{
     *     name: string,
     *     summary: string,
     *     warnings: list<string>,
     *     workouts: list<array{name: string, focus: string, exercises: list<array<string, mixed>>}>
     * }
     */
    public function generate(GymGeneratorInput $in): array
    {
        $pool = $this->pool($in);
        $cardio = $this->cardioPool($in);
        $dias = max(2, min(6, $in->days));
        $estrutura = self::SPLITS[$dias];
        $quantos = $this->exerciseCount($in);
        $usadosNoPlano = [];
        $avisos = [];
        $treinos = [];

        foreach ($estrutura as $i => $tipo) {
            $linhas = [];
            $usadosNoTreino = [];

            foreach (array_slice(self::SLOTS[$tipo], 0, $quantos) as [$grupo, $especie]) {
                $escolhido = $this->pick($pool, $grupo, $especie, $usadosNoTreino, $usadosNoPlano, $in->variant);

                if ($escolhido === null) {
                    $avisos[$grupo] = $this->label($grupo);

                    continue;
                }

                $usadosNoTreino[$escolhido['id']] = true;
                $usadosNoPlano[$escolhido['id']] = true;
                $linhas[] = $this->row($escolhido, $in);
            }

            if ($this->wantsCardio($in, $i, count($estrutura)) && ($c = $this->pickCardio($cardio, $usadosNoPlano, $in->variant + $i)) !== null) {
                $usadosNoPlano[$c['id']] = true;
                $linhas[] = $this->cardioRow($c, $in);
            }

            if ($linhas !== []) {
                $linhas[0]['notes'] = trim(($linhas[0]['notes'] ?? '').' '.self::WARM_UP);
            }

            $treinos[] = ['name' => 'Treino '.chr(65 + $i), 'focus' => self::FOCUS[$tipo], 'exercises' => $linhas];
        }

        return [
            'name' => 'Treino automático: '.mb_strtolower($in->goal->label()).' ('.$dias.' dias)',
            'summary' => $this->summary($in, $dias, $quantos),
            'warnings' => array_values(array_map(
                fn (string $grupo) => "Não encontrei exercício de {$grupo} que combine com o que você tem e com as restrições. O treino ficou sem esse grupo em alguns dias.",
                array_unique($avisos),
            )),
            'workouts' => $treinos,
        ];
    }

    /**
     * Opções para trocar um exercício do rascunho: do mesmo grupo, que cabem no que a pessoa tem, sem os que já estão no
     * treino. Mesmo tipo (composto ou isolado) primeiro.
     *
     * @param  array<string, mixed>  $linha  o exercício do rascunho que sai
     * @param  list<string>  $jaNoTreino  ids do catálogo que já estão no treino
     * @return list<array{key: string, id: string, name: string, group: string, equipment: ?string}>
     */
    public function alternatives(array $linha, GymGeneratorInput $in, array $jaNoTreino, string $busca = '', bool $todosGrupos = false, int $limite = 30): array
    {
        $busca = C::normalize($busca);
        $todos = $todosGrupos || $busca !== '';

        return $this->pool($in, incluirCardio: $linha['group_value'] === 'cardio')->reject(fn (array $e) => in_array($e['id'], $jaNoTreino, true))
            ->when(! $todos, fn (Collection $c) => $c->filter(fn (array $e) => $e['group'] === $linha['group_value']))
            ->when($busca !== '', fn (Collection $c) => $c->filter(fn (array $e) => str_contains(C::normalize($e['name'].' '.$e['equipment']), $busca)))
            ->map(fn (array $e) => $e + ['rank' => $e['kind'] === ($linha['kind'] ?? null) ? 0 : 1])
            ->sortBy([['rank', 'asc'], ['score', 'desc'], ['name', 'asc']])
            ->take($limite)
            ->map(fn (array $e) => ['key' => 'catalog:'.$e['id'], 'id' => $e['id'], 'name' => $e['name'], 'group' => $e['label'], 'equipment' => $e['equipment']])
            ->values()
            ->all();
    }

    /**
     * Uma linha de rascunho para um exercício do catálogo (usada ao trocar): a prescrição é refeita para o novo exercício.
     *
     * @return array<string, mixed>|null
     */
    public function rowFor(string $catalogId, GymGeneratorInput $in): ?array
    {
        $e = $this->pool($in, incluirCardio: true)->firstWhere('id', $catalogId);

        if ($e === null) {
            return null;
        }

        return $e['group'] === 'cardio' || C::isConditioning($e['name']) ? $this->cardioRow($e, $in) : $this->row($e, $in);
    }

    // --- Seleção -----------------------------------------------------------------------------------------------------

    /**
     * O catálogo já filtrado pelo que a pessoa tem, pelo nível e pelas restrições, com o que o gerador precisa de cada um.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function pool(GymGeneratorInput $in, bool $incluirCardio = false): Collection
    {
        return GymExerciseCatalog::query()->active()->get()
            ->filter(fn (GymExerciseCatalog $c) => ! C::isNeverAuto($c->name)
                && C::fitsLevel($c->name, $in->level)
                && C::fitsAccess($c->name, $c->equipment_hint, $in->access)
                && ! C::hitsRestriction($c->name, $in->restrictions)
                && ($incluirCardio ? true : ($c->muscle_group->value !== 'cardio' && ! C::isConditioning($c->name))))
            ->map(fn (GymExerciseCatalog $c) => [
                'id' => $c->id, 'name' => $c->name, 'group' => $c->muscle_group->value, 'label' => $c->muscle_group->label(),
                'equipment' => $c->equipment_hint, 'measure' => $c->measure_type->value,
                'kind' => C::kind($c->name, $c->muscle_group->value), 'arm' => C::armPart($c->name), 'calf' => C::isCalf($c->name),
                'pref' => C::preference($c->equipment_hint, $c->name, $in->level), 'family' => C::family($c->name),
                // O básico do grupo manda; o nível puxa para o equipamento que ele prefere (iniciante, aparelho guiado).
                'score' => C::priority($c->name) * 3 + C::preference($c->equipment_hint, $c->name, $in->level) * 4,
            ])
            ->values();
    }

    /** O condicionamento: o grupo de cardio do catálogo mais burpee e salto lateral, já filtrados. */
    private function cardioPool(GymGeneratorInput $in): Collection
    {
        return GymExerciseCatalog::query()->active()->get()
            ->filter(fn (GymExerciseCatalog $c) => ($c->muscle_group->value === 'cardio' || C::isConditioning($c->name))
                && ! str_contains(C::normalize($c->name), 'salto vertical') && ! str_contains(C::normalize($c->name), 'jump rope')
                && C::fitsLevel($c->name, $in->level)
                && C::fitsAccess($c->name, $c->equipment_hint, $in->access)
                && ! C::hitsRestriction($c->name, $in->restrictions)
                && ! str_contains(C::normalize($c->name), 'hiit'))
            ->map(fn (GymExerciseCatalog $c) => [
                'id' => $c->id, 'name' => $c->name, 'group' => 'cardio', 'label' => $c->muscle_group->label(), 'equipment' => $c->equipment_hint,
                'measure' => $c->measure_type->value, 'kind' => 'cardio', 'arm' => null, 'calf' => false, 'pref' => 0,
            ])
            ->values();
    }

    /**
     * Escolhe o exercício do espaço. Prefere o que ainda não apareceu em nenhum treino da fase (para os dias não repetirem
     * tudo); se faltar, relaxa o tipo dentro do mesmo grupo. `variant` gira entre as opções.
     *
     * @param  Collection<int, array<string, mixed>>  $pool
     * @param  array<string, bool>  $noTreino
     * @param  array<string, bool>  $noPlano
     * @return array<string, mixed>|null
     */
    private function pick(Collection $pool, string $grupo, string $especie, array $noTreino, array $noPlano, int $variant): ?array
    {
        foreach ([$especie, 'any'] as $tentativa) {
            $candidatos = $pool->filter(fn (array $e) => $e['group'] === $grupo && ! isset($noTreino[$e['id']]) && $this->matches($e, $tentativa))
                ->sortBy([['score', 'desc'], ['name', 'asc']])->values();

            if ($candidatos->isEmpty()) {
                continue;
            }

            // Dá preferência ao que ainda não apareceu na fase e a uma família de movimento diferente das já usadas no treino.
            $familias = collect($noTreino)->keys()->map(fn (string $id) => $pool->firstWhere('id', $id)['family'] ?? null)->filter()->all();
            $ideais = $candidatos->reject(fn (array $e) => isset($noPlano[$e['id']]) || in_array($e['family'], $familias, true))->values();
            $novos = $candidatos->reject(fn (array $e) => isset($noPlano[$e['id']]))->values();
            $lista = $ideais->isNotEmpty() ? $ideais : ($novos->isNotEmpty() ? $novos : $candidatos);

            return $lista[$variant % $lista->count()];
        }

        return null;
    }

    /** @param  array<string, mixed>  $e */
    private function matches(array $e, string $especie): bool
    {
        return match ($especie) {
            'compound', 'isolation' => $e['kind'] === $especie && ! $e['calf'],
            'biceps', 'triceps' => $e['arm'] === $especie,
            'calf' => $e['calf'],
            default => true,
        };
    }

    /** @param  Collection<int, array<string, mixed>>  $cardio */
    private function pickCardio(Collection $cardio, array $noPlano, int $variant): ?array
    {
        if ($cardio->isEmpty()) {
            return null;
        }

        $lista = $cardio->sortBy('name')->values();
        $novos = $lista->reject(fn (array $e) => isset($noPlano[$e['id']]))->values();
        $escolha = $novos->isNotEmpty() ? $novos : $lista;

        return $escolha[$variant % $escolha->count()];
    }

    private function wantsCardio(GymGeneratorInput $in, int $indice, int $total): bool
    {
        return match ($in->goal) {
            GymGoal::WeightLoss, GymGoal::Endurance => true,
            GymGoal::GeneralHealth => $indice === $total - 1,
            default => false,
        };
    }

    private function exerciseCount(GymGeneratorInput $in): int
    {
        $base = self::EXERCISES_BY_MINUTES[$in->minutes] ?? 6;

        return $in->level === GymLevel::Beginner ? max(3, $base - 1) : $base;
    }

    // --- Prescrição --------------------------------------------------------------------------------------------------

    /** @param  array<string, mixed>  $e */
    private function row(array $e, GymGeneratorInput $in): array
    {
        $p = $this->prescribe($e, $in);

        return [
            'catalog_id' => $e['id'], 'name' => $e['name'], 'group' => $e['label'], 'group_value' => $e['group'], 'equipment' => $e['equipment'],
            'measure' => $e['measure'], 'kind' => $e['kind'],
            'sets' => $p['sets'], 'reps_min' => $p['reps_min'], 'reps_max' => $p['reps_max'], 'duration' => $p['duration'], 'rest' => $p['rest'],
            'notes' => $in->access === GymEquipmentAccess::Bodyweight && ! in_array('bodyweight', C::equipment($e['equipment'], $e['name']), true)
                ? 'Sem carga (peso do corpo) ou com uma mochila com peso.'
                : null,
        ];
    }

    /** @param  array<string, mixed>  $e */
    private function cardioRow(array $e, GymGeneratorInput $in): array
    {
        $segundos = $in->minutes <= 30 ? 600 : ($in->minutes <= 45 ? 900 : 1200);

        // Burpee e salto lateral se contam em repetições, não em minutos.
        if ($e['measure'] === 'load_reps') {
            return [
                'catalog_id' => $e['id'], 'name' => $e['name'], 'group' => $e['label'], 'group_value' => $e['group'], 'equipment' => $e['equipment'],
                'measure' => $e['measure'], 'kind' => 'cardio', 'sets' => 3, 'reps_min' => $in->level === GymLevel::Beginner ? 8 : 12, 'reps_max' => $in->level === GymLevel::Beginner ? 12 : 15,
                'duration' => null, 'rest' => 45, 'notes' => 'Condicionamento: ritmo forte, com técnica.',
            ];
        }

        return [
            'catalog_id' => $e['id'], 'name' => $e['name'], 'group' => $e['label'], 'group_value' => 'cardio', 'equipment' => $e['equipment'],
            'measure' => $e['measure'], 'kind' => 'cardio', 'sets' => 1, 'reps_min' => null, 'reps_max' => null, 'duration' => $segundos, 'rest' => null,
            'notes' => 'Ritmo em que dê para conversar com um pouco de esforço.',
        ];
    }

    /**
     * Séries, repetições e descanso pelo objetivo, nível e tipo do exercício.
     *
     * @param  array<string, mixed>  $e
     * @return array{sets: int, reps_min: ?int, reps_max: ?int, duration: ?int, rest: ?int}
     */
    private function prescribe(array $e, GymGeneratorInput $in): array
    {
        $iniciante = $in->level === GymLevel::Beginner;
        $avancado = $in->level === GymLevel::Advanced;

        if ($e['measure'] !== 'load_reps') {
            return ['sets' => $iniciante ? 2 : 3, 'reps_min' => null, 'reps_max' => null, 'duration' => $iniciante ? 30 : ($avancado ? 50 : 40), 'rest' => null];
        }

        if ($e['group'] === 'core') {
            return ['sets' => $iniciante ? 2 : 3, 'reps_min' => 15, 'reps_max' => 20, 'duration' => null, 'rest' => 45];
        }

        if ($e['calf']) {
            return ['sets' => $iniciante ? 2 : 3, 'reps_min' => $in->goal === GymGoal::Strength ? 12 : 15, 'reps_max' => $in->goal === GymGoal::Strength ? 15 : 20, 'duration' => null, 'rest' => 45];
        }

        $composto = $e['kind'] === C::COMPOUND;

        [$series, $min, $max, $descanso] = match ($in->goal) {
            GymGoal::Hypertrophy => $composto
                ? [$avancado ? 4 : 3, $iniciante ? 8 : 6, $iniciante ? 12 : 10, 90]
                : [$iniciante ? 2 : 3, 10, 15, 60],
            GymGoal::Strength => $composto
                ? [$iniciante ? 3 : ($avancado ? 5 : 4), $iniciante ? 5 : 3, $iniciante ? 8 : 6, $iniciante ? 120 : 180]
                : [3, 8, 12, 90],
            GymGoal::WeightLoss => $composto
                ? [$iniciante ? 2 : 3, 12, 15, 45]
                : [$iniciante ? 2 : 3, 12, 15, 30],
            GymGoal::GeneralHealth => $composto
                ? [$iniciante ? 2 : 3, 10, 12, 60]
                : [2, 12, 15, 45],
            GymGoal::Endurance => $composto
                ? [$iniciante ? 2 : 3, 15, 20, 40]
                : [$avancado ? 3 : 2, 15, 20, 30],
        };

        return ['sets' => $series, 'reps_min' => $min, 'reps_max' => $max, 'duration' => null, 'rest' => $descanso];
    }

    private function summary(GymGeneratorInput $in, int $dias, int $quantos): string
    {
        return "{$dias} treinos por semana, com cerca de {$quantos} exercícios cada. ".$in->goal->hint()
            .($in->level === GymLevel::Beginner ? ' Para quem está começando, o treino prefere aparelhos guiados e menos exercícios por dia.' : '')
            .($in->restrictions !== [] ? ' Ficam de fora os exercícios que mais exigem: '.implode(', ', array_map(fn ($r) => mb_strtolower($r->label()), $in->restrictions)).'.' : '');
    }

    private function label(string $grupo): string
    {
        return mb_strtolower(\App\Enums\GymMuscleGroup::tryFrom($grupo)?->label() ?? $grupo);
    }
}
