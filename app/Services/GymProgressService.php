<?php

namespace App\Services;

use App\Enums\GymLoadMode;
use App\Enums\GymMeasureType;
use App\Models\GymExercise;
use App\Models\GymSetLog;
use App\Models\GymWorkoutExercise;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Evolução por exercício: um ponto por sessão FINALIZADA, detecção de
 * platô e sugestão de subir a carga (progressão dupla).
 *
 * Quilos são comparados como string com bcmath (mesma regra do resto do
 * app pra decimal). Só o desenho do gráfico usa float — é geometria de
 * tela, não dado.
 *
 * Roda sob o ProfileContext da pessoa (escopo de saúde pessoal).
 */
class GymProgressService
{
    /** Sessões seguidas sem superar o melhor resultado para chamar de platô. */
    public const PLATEAU_AFTER = 3;

    /** Histórico mínimo (na mesma máquina) antes de falar em platô. */
    public const MIN_SESSIONS = 4;

    /** Degrau sugerido ao subir a carga, na MESMA unidade em que a pessoa digita. */
    private const STEP = [
        'per_dumbbell' => '2',
        'per_side' => '2.5',
        'stack_position' => '1',
        'total' => '2.5',
        'bodyweight_plus' => '2.5',
    ];

    /**
     * Análise completa de um exercício.
     *
     * @return array{
     *   metric: string, sessions: list<array<string, mixed>>, best: ?array<string, mixed>,
     *   plateau: ?int, suggestion: ?array{type: string, message: string, next_load?: string, mode?: string}
     * }
     */
    public function analyze(GymExercise $exercise, ?GymWorkoutExercise $target = null): array
    {
        return $this->analyzeLogs($exercise->measure_type, $this->finishedLogs($exercise->id), $target);
    }

    /**
     * Visão geral: uma linha por exercício com histórico, já com o status
     * (subir carga / platô / evoluindo). Uma consulta só pra todos.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function overview(): Collection
    {
        $logs = $this->finishedLogs()->groupBy('exercise_id');

        if ($logs->isEmpty()) {
            return collect();
        }

        $exercicios = GymExercise::query()->whereIn('id', $logs->keys())->get()->keyBy('id');

        // Meta vem do plano ativo; exercício fora dele só tem platô, não "subir carga".
        $metas = GymWorkoutExercise::query()
            ->whereHas('workout.plan', fn ($q) => $q->where('is_active', true))
            ->get()
            ->unique('exercise_id')
            ->keyBy('exercise_id');

        return $logs->map(function (Collection $linhas, string $exerciseId) use ($exercicios, $metas): ?array {
            $exercicio = $exercicios->get($exerciseId);

            if ($exercicio === null) {
                return null;
            }

            $analise = $this->analyzeLogs($exercicio->measure_type, $linhas, $metas->get($exerciseId));
            $ultima = collect($analise['sessions'])->last();

            return [
                'exercise' => $exercicio,
                'sessions_count' => count($analise['sessions']),
                'last_date' => $ultima['date'] ?? null,
                'best' => $analise['best'],
                'metric' => $analise['metric'],
                'plateau' => $analise['plateau'],
                'suggestion' => $analise['suggestion'],
                'status' => $analise['suggestion']['type'] ?? 'ok',
            ];
        })->filter()->sortByDesc('last_date')->values();
    }

    /**
     * @param  Collection<int, GymSetLog>  $logs  séries de UM exercício, sessões finalizadas
     * @return array{metric: string, sessions: list<array<string, mixed>>, best: ?array<string, mixed>, plateau: ?int, suggestion: ?array<string, string>}
     */
    public function analyzeLogs(GymMeasureType $type, Collection $logs, ?GymWorkoutExercise $target = null): array
    {
        [$metric, $sessions] = $this->summarize($type, $logs);

        $melhor = null;
        foreach ($sessions as $s) {
            if ($melhor === null || $this->compare($s, $melhor) > 0) {
                $melhor = $s;
            }
        }

        $plateau = $this->plateau($sessions);

        // Subir a carga tem prioridade: é a ação concreta; o platô é o diagnóstico.
        $suggestion = $this->raiseSuggestion($metric, $sessions, $target)
            ?? ($plateau !== null ? [
                'type' => 'plateau',
                'message' => "Sem evolução há {$plateau} treinos. Considere subir a carga, mudar a faixa de repetições ou variar o exercício.",
            ] : null);

        return [
            'metric' => $metric,
            'sessions' => $sessions,
            'best' => $melhor,
            'plateau' => $plateau,
            'suggestion' => $suggestion,
        ];
    }

    /**
     * Geometria do gráfico (SVG desenhado no Blade). A linha QUEBRA quando
     * o equipamento muda entre duas sessões: 20 kg na polia e 20 kg na
     * barra não são a mesma evolução.
     *
     * @param  list<array<string, mixed>>  $sessions
     * @return array{width: int, height: int, segments: list<list<array<string, mixed>>>, breaks: list<float>, yTicks: list<array{y: float, label: string}>, xLabels: list<array{x: float, label: string}>}|null
     */
    public function chart(array $sessions): ?array
    {
        $n = count($sessions);

        if ($n === 0) {
            return null;
        }

        $w = 640;
        $h = 240;
        [$esq, $dir, $topo, $base] = [44, 16, 16, 32];

        $valores = array_map(fn (array $s) => (float) $s['value'], $sessions);
        $min = min($valores);
        $max = max($valores);

        if ($min === $max) {
            $min -= 1;
            $max += 1;
        } else {
            $folga = ($max - $min) * 0.08;
            $min -= $folga;
            $max += $folga;
        }

        $x = fn (int $i): float => $n === 1 ? $esq + ($w - $esq - $dir) / 2 : $esq + $i * ($w - $esq - $dir) / ($n - 1);
        $y = fn (float $v): float => $topo + ($max - $v) / ($max - $min) * ($h - $topo - $base);

        $segmentos = [];
        $quebras = [];
        $atual = [];

        foreach ($sessions as $i => $s) {
            if ($i > 0 && $this->equipmentChanged($sessions[$i - 1], $s)) {
                $segmentos[] = $atual;
                $atual = [];
                $quebras[] = ($x($i - 1) + $x($i)) / 2;
            }

            $atual[] = [
                'x' => $x($i),
                'y' => $y($valores[$i]),
                'title' => $s['date']->format('d/m/Y').' — '.$this->fmt($s['value']).($s['equipment_name'] ? ' ('.$s['equipment_name'].')' : ''),
            ];
        }
        $segmentos[] = $atual;

        // Faixa larga: rótulos inteiros; faixa curta (ex.: 9 a 12 reps): uma casa.
        $casas = ($max - $min) >= 10 ? 0 : 1;
        $ticks = [];
        foreach ([0, 1, 2, 3] as $t) {
            $v = $min + ($max - $min) * $t / 3;
            $ticks[] = ['y' => $y($v), 'label' => $this->fmt(number_format($v, $casas, '.', ''))];
        }

        $passo = max(1, (int) ceil($n / 5));
        $rotulos = [];
        foreach ($sessions as $i => $s) {
            if ($i % $passo === 0 || $i === $n - 1) {
                $rotulos[] = ['x' => $x($i), 'label' => $s['date']->format('d/m')];
            }
        }

        return [
            'width' => $w,
            'height' => $h,
            'segments' => $segmentos,
            'breaks' => $quebras,
            'yTicks' => $ticks,
            'xLabels' => $rotulos,
        ];
    }

    /** Número com vírgula e sem zeros à direita ("14.00" → "14", "12.50" → "12,5"). */
    public function fmt(string $n): string
    {
        return str_replace('.', ',', str_contains($n, '.') ? rtrim(rtrim($n, '0'), '.') : $n);
    }

    /** @return Collection<int, GymSetLog> */
    private function finishedLogs(?string $exerciseId = null): Collection
    {
        return GymSetLog::query()
            ->when($exerciseId, fn ($q) => $q->where('exercise_id', $exerciseId))
            ->whereHas('session', fn ($q) => $q->whereNotNull('finished_at'))
            ->with('session:id,performed_on,finished_at', 'equipment:id,name')
            ->orderBy('completed_at')
            ->get();
    }

    /**
     * Uma linha por sessão, em ordem cronológica.
     *
     * @param  Collection<int, GymSetLog>  $logs
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    private function summarize(GymMeasureType $type, Collection $logs): array
    {
        $sessoes = [];

        foreach ($logs->groupBy('session_id') as $series) {
            /** @var Collection<int, GymSetLog> $series */
            $series = $series->sortBy('set_number')->values();
            $topo = null;

            foreach ($series as $serie) {
                $candidata = [
                    'load' => $this->effectiveLoad($serie),
                    'reps' => (int) $serie->reps,
                    'seconds' => (int) $serie->duration_seconds,
                    'distance' => (int) $serie->distance_meters,
                    'speed' => $this->speed($serie),
                ];

                if ($topo === null || $this->betterSet($type, $candidata, $topo['key'])) {
                    $topo = ['key' => $candidata, 'log' => $serie];
                }
            }

            $volume = '0.00';
            foreach ($series as $serie) {
                if ($serie->load_total_kg !== null && $serie->reps) {
                    $volume = bcadd($volume, bcmul($serie->load_total_kg, (string) $serie->reps, 2), 2);
                }
            }

            /** @var GymSetLog $log */
            $log = $topo['log'];

            $sessoes[] = [
                'session_id' => $log->session_id,
                'date' => $this->dateOf($log),
                'sets' => $series,
                'top_load' => $topo['key']['load'],
                'top_reps' => $topo['key']['reps'],
                'top_seconds' => $topo['key']['seconds'],
                'top_speed' => $topo['key']['speed'],
                'top_distance' => $topo['key']['distance'],
                'top_mode' => $log->load_mode,
                'top_typed' => $log->load_value,
                'volume' => $volume,
                'equipment_id' => $log->equipment_id,
                'equipment_name' => $log->equipment?->name,
            ];
        }

        usort($sessoes, fn ($a, $b) => $a['date'] <=> $b['date']);

        $metric = 'duration';
        if ($type === GymMeasureType::DistanceDuration && $sessoes !== [] && collect($sessoes)->every(fn ($x) => $x['top_distance'] > 0)) {
            // Corrida: mais tempo é PIOR. Compara a velocidade média (km/h).
            $metric = 'speed';
        } elseif ($type === GymMeasureType::LoadReps) {
            $temCarga = collect($sessoes)->contains(fn ($s) => bccomp($s['top_load'], '0', 2) > 0);
            $metric = ! $temCarga ? 'reps' : (end($sessoes)['top_mode'] === GymLoadMode::StackPosition ? 'stack' : 'kg');
        }

        foreach ($sessoes as &$s) {
            [$s['value'], $s['tiebreak']] = match ($metric) {
                'kg', 'stack' => [$s['top_load'], $s['top_reps']],
                'reps' => [(string) $s['top_reps'], 0],
                'speed' => [$s['top_speed'], 0],
                default => [(string) $s['top_seconds'], 0],
            };
        }
        unset($s);

        return [$metric, $sessoes];
    }

    /**
     * Carga comparável de uma série: total em kg; na máquina de pilha
     * (sem conversão) a posição digitada; sem carga, zero.
     */
    private function effectiveLoad(GymSetLog $serie): string
    {
        if ($serie->load_total_kg !== null) {
            return $serie->load_total_kg;
        }

        return $serie->load_mode === GymLoadMode::StackPosition && $serie->load_value !== null
            ? $serie->load_value
            : '0.00';
    }

    /**
     * @param  array{load: string, reps: int, seconds: int, distance: int, speed: string}  $a
     * @param  array{load: string, reps: int, seconds: int, distance: int, speed: string}  $b
     */
    private function betterSet(GymMeasureType $type, array $a, array $b): bool
    {
        if ($type === GymMeasureType::DistanceDuration && ($a['distance'] > 0 || $b['distance'] > 0)) {
            return bccomp($a['speed'], $b['speed'], 2) > 0;
        }

        if ($type !== GymMeasureType::LoadReps) {
            return $a['seconds'] > $b['seconds'];
        }

        $c = bccomp($a['load'], $b['load'], 2);

        return $c > 0 || ($c === 0 && $a['reps'] > $b['reps']);
    }

    /** @param  array<string, mixed>  $a @param  array<string, mixed>  $b */
    private function compare(array $a, array $b): int
    {
        return bccomp($a['value'], $b['value'], 2) ?: ($a['tiebreak'] <=> $b['tiebreak']);
    }

    /** Equipamento só "muda" quando os DOIS são conhecidos e diferentes. */
    private function equipmentChanged(array $anterior, array $atual): bool
    {
        return $anterior['equipment_id'] !== null
            && $atual['equipment_id'] !== null
            && $anterior['equipment_id'] !== $atual['equipment_id'];
    }

    /** Velocidade média em km/h (metros × 3,6 ÷ segundos), bcmath escala 2. */
    private function speed(GymSetLog $serie): string
    {
        if (! $serie->distance_meters || ! $serie->duration_seconds) {
            return '0.00';
        }

        return bcdiv(bcmul((string) $serie->distance_meters, '3.6', 4), (string) $serie->duration_seconds, 2);
    }

    private function dateOf(GymSetLog $log): CarbonInterface
    {
        return $log->session->performed_on;
    }

    /**
     * Quantas sessões seguidas, no trecho atual (mesma máquina), sem
     * superar o melhor resultado — ou null se ainda não é platô.
     *
     * @param  list<array<string, mixed>>  $sessoes
     */
    private function plateau(array $sessoes): ?int
    {
        $trecho = [];
        foreach ($sessoes as $i => $s) {
            if ($i > 0 && $this->equipmentChanged($sessoes[$i - 1], $s)) {
                $trecho = [];
            }
            $trecho[] = $s;
        }

        if (count($trecho) < self::MIN_SESSIONS) {
            return null;
        }

        $idxMelhor = 0;
        foreach ($trecho as $i => $s) {
            if ($this->compare($s, $trecho[$idxMelhor]) > 0) {
                $idxMelhor = $i;
            }
        }

        $semEvoluir = count($trecho) - 1 - $idxMelhor;

        return $semEvoluir >= self::PLATEAU_AFTER ? $semEvoluir : null;
    }

    /**
     * Progressão dupla: bateu o teto da faixa de repetições em TODAS as
     * séries da meta, com a mesma carga, na última sessão → hora de subir.
     *
     * @param  list<array<string, mixed>>  $sessoes
     * @return array{type: string, message: string, next_load?: string, mode?: string}|null
     */
    private function raiseSuggestion(string $metric, array $sessoes, ?GymWorkoutExercise $target): ?array
    {
        if (! in_array($metric, ['kg', 'stack'], true) || $target === null || ! $target->target_reps_max || $sessoes === []) {
            return null;
        }

        $ultima = end($sessoes);
        $modo = $ultima['top_mode'];

        if ($modo === null || $modo === GymLoadMode::Bodyweight) {
            return null;
        }

        $noTeto = $ultima['sets']->filter(fn (GymSetLog $s) => bccomp($this->effectiveLoad($s), $ultima['top_load'], 2) === 0
            && (int) $s->reps >= $target->target_reps_max)->count();

        if ($noTeto < $target->target_sets) {
            return null;
        }

        $atual = $this->fmt((string) $ultima['top_typed']);
        $proxima = $this->fmt(bcadd((string) $ultima['top_typed'], self::STEP[$modo->value] ?? '2.5', 2));

        return [
            'type' => 'raise',
            'message' => "Você fez {$target->target_reps_max} repetições ou mais nas {$target->target_sets} séries com {$atual} kg ({$this->modeText($modo)}). Hora de subir a carga — sugestão: {$proxima} kg.",
            'next_load' => $proxima,
            'mode' => $modo->value,
        ];
    }

    private function modeText(GymLoadMode $modo): string
    {
        return mb_strtolower($modo->label());
    }
}
