<?php

namespace App\Services;

use App\Enums\GymMeasureType;
use App\Models\GymExercise;
use App\Models\GymPlan;
use App\Models\GymSession;
use App\Models\GymSetLog;
use App\Models\GymWorkoutExercise;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Relatório de evolução da Academia: o resumo do período (treinos, constância, volume), os destaques (maiores evoluções,
 * recordes, platôs) e, para cada treino do plano, cada exercício com de onde saiu e onde chegou.
 *
 * Só conta sessão FINALIZADA. O agrupamento por treino vem da própria sessão (`workout_id`), não do plano de hoje: trocar um
 * exercício no plano depois não reescreve o que foi feito. Roda sob o ProfileContext da pessoa (escopo de saúde pessoal).
 * Quilos em string e bcmath, como o resto; somas em SQL.
 */
class GymReportService
{
    /** Mais semanas que isso no gráfico de frequência: agrupa por mês. */
    private const MAX_WEEKS_CHART = 26;

    /** Treino "esquecido" aberto não pode puxar a duração média para cima. */
    private const MAX_SESSION_MINUTES = 300;

    public function __construct(private readonly GymProgressService $progress) {}

    /**
     * @return array{
     *     start: CarbonImmutable, end: CarbonImmutable,
     *     summary: array<string, mixed>, previous: array<string, mixed>, weekly: list<array{label: string, count: int}>, weekly_unit: string,
     *     insights: list<string>, highlights: array<string, list<array<string, mixed>>>, workouts: list<array<string, mixed>>
     * }
     */
    public function report(CarbonInterface $inicio, CarbonInterface $fim): array
    {
        $inicio = CarbonImmutable::parse($inicio)->startOfDay();
        $fim = CarbonImmutable::parse($fim)->endOfDay();

        $sessoes = GymSession::query()->whereNotNull('finished_at')
            ->whereBetween('performed_on', [$inicio->toDateString(), $fim->toDateString()])
            ->with('workout.plan')->orderBy('performed_on')->get();

        $logs = $this->logsUntil($fim);
        $exercicios = GymExercise::query()->whereIn('id', $logs->pluck('exercise_id')->unique())->get()->keyBy('id');
        $metas = $this->activeTargets();

        $treinos = $this->workoutSections($sessoes, $logs, $exercicios, $metas, $inicio, $fim);
        $destaques = $this->highlights($treinos, $logs, $exercicios, $metas, $inicio, $fim);
        $resumo = $this->summary($sessoes, $inicio, $fim);

        $dias = (int) $inicio->diffInDays($fim) + 1;
        $anteriorFim = $inicio->subDay()->endOfDay();
        $anteriorInicio = $anteriorFim->subDays($dias - 1)->startOfDay();
        $anterior = $this->summary(
            GymSession::query()->whereNotNull('finished_at')->whereBetween('performed_on', [$anteriorInicio->toDateString(), $anteriorFim->toDateString()])->orderBy('performed_on')->get(),
            $anteriorInicio,
            $anteriorFim,
        );

        [$semanal, $unidade] = $this->weekly($sessoes, $inicio, $fim);

        return [
            'start' => $inicio, 'end' => $fim, 'summary' => $resumo, 'previous' => $anterior, 'weekly' => $semanal, 'weekly_unit' => $unidade,
            'insights' => $this->insights($resumo, $anterior, $destaques),
            'highlights' => $destaques, 'workouts' => $treinos,
        ];
    }

    // --- Resumo ------------------------------------------------------------------------------------------------------

    /**
     * @param  Collection<int, GymSession>  $sessoes
     * @return array<string, mixed>
     */
    private function summary(Collection $sessoes, CarbonImmutable $inicio, CarbonImmutable $fim): array
    {
        $ids = $sessoes->pluck('id');
        $semanas = $this->weekStarts($inicio, $fim);
        $comTreino = $sessoes->map(fn (GymSession $s) => CarbonImmutable::parse($s->performed_on)->startOfWeek(CarbonInterface::MONDAY)->toDateString())->unique();

        $totais = $ids->isEmpty() ? null : GymSetLog::query()->whereIn('session_id', $ids)
            ->selectRaw('COUNT(*) AS series, COALESCE(SUM(load_total_kg * reps), 0) AS volume')->first();

        $minutos = $sessoes->filter(fn (GymSession $s) => $s->started_at !== null && $s->finished_at !== null)
            ->map(fn (GymSession $s) => (int) round($s->started_at->diffInMinutes($s->finished_at, true)))
            ->filter(fn (int $m) => $m > 0 && $m <= self::MAX_SESSION_MINUTES);

        $datas = $sessoes->map(fn (GymSession $s) => CarbonImmutable::parse($s->performed_on))->sort()->values();
        $maiorPausa = 0;
        for ($i = 1; $i < $datas->count(); $i++) {
            $maiorPausa = max($maiorPausa, (int) $datas[$i - 1]->diffInDays($datas[$i], true));
        }

        $totalSemanas = max(1, count($semanas));

        return [
            'sessions' => $sessoes->count(),
            'per_week' => number_format($sessoes->count() / $totalSemanas, 1, ',', ''),
            'weeks_total' => $totalSemanas,
            'weeks_trained' => $comTreino->count(),
            'consistency' => (int) round($comTreino->count() / $totalSemanas * 100),
            'avg_minutes' => $minutos->isEmpty() ? null : (int) round($minutos->avg()),
            'sets' => (int) ($totais->series ?? 0),
            'volume_kg' => number_format((float) ($totais->volume ?? 0), 0, ',', '.'),
            'volume_raw' => (float) ($totais->volume ?? 0),
            'longest_gap_days' => $maiorPausa > 0 ? $maiorPausa : null,
            'days_since_last' => $datas->isEmpty() ? null : (int) $datas->last()->diffInDays(CarbonImmutable::today(), true),
        ];
    }

    /**
     * Treinos por semana (ou por mês, se o período for longo).
     *
     * @param  Collection<int, GymSession>  $sessoes
     * @return array{0: list<array{label: string, count: int}>, 1: string}
     */
    private function weekly(Collection $sessoes, CarbonImmutable $inicio, CarbonImmutable $fim): array
    {
        $semanas = $this->weekStarts($inicio, $fim);

        if (count($semanas) > self::MAX_WEEKS_CHART) {
            $meses = [];
            for ($m = $inicio->startOfMonth(); $m->lte($fim); $m = $m->addMonthNoOverflow()) {
                $meses[$m->format('Y-m')] = ['label' => mb_strtolower($m->translatedFormat('M')).'/'.$m->format('y'), 'count' => 0];
            }
            foreach ($sessoes as $s) {
                $chave = CarbonImmutable::parse($s->performed_on)->format('Y-m');
                if (isset($meses[$chave])) {
                    $meses[$chave]['count']++;
                }
            }

            return [array_values($meses), 'mês'];
        }

        $contagem = $sessoes->countBy(fn (GymSession $s) => CarbonImmutable::parse($s->performed_on)->startOfWeek(CarbonInterface::MONDAY)->toDateString());

        return [array_map(fn (CarbonImmutable $semana) => ['label' => $semana->format('d/m'), 'count' => (int) ($contagem[$semana->toDateString()] ?? 0)], $semanas), 'semana'];
    }

    /** @return list<CarbonImmutable> a segunda-feira de cada semana que toca o período */
    private function weekStarts(CarbonImmutable $inicio, CarbonImmutable $fim): array
    {
        $lista = [];
        for ($s = $inicio->startOfWeek(CarbonInterface::MONDAY); $s->lte($fim); $s = $s->addWeek()) {
            $lista[] = $s;
        }

        return $lista;
    }

    // --- Treinos e exercícios --------------------------------------------------------------------------------------------

    /**
     * Uma seção por treino do plano que teve sessão no período; dentro, um exercício por linha, na ordem do plano.
     *
     * @param  Collection<int, GymSession>  $sessoes
     * @param  Collection<int, GymSetLog>  $logs
     * @param  Collection<string, GymExercise>  $exercicios
     * @param  Collection<string, GymWorkoutExercise>  $metas
     * @return list<array<string, mixed>>
     */
    private function workoutSections(Collection $sessoes, Collection $logs, Collection $exercicios, Collection $metas, CarbonImmutable $inicio, CarbonImmutable $fim): array
    {
        $secoes = [];

        foreach ($sessoes->groupBy('workout_id') as $workoutId => $doTreino) {
            $treino = $doTreino->first()->workout;
            $sessoesDoTreino = $logs->filter(fn (GymSetLog $l) => $l->session->workout_id === $workoutId);
            $posicoes = GymWorkoutExercise::query()->where('workout_id', $workoutId)->pluck('position', 'exercise_id');
            $linhas = [];

            foreach ($sessoesDoTreino->groupBy('exercise_id') as $exerciseId => $registros) {
                $exercicio = $exercicios->get($exerciseId);

                if ($exercicio === null) {
                    continue;
                }

                $linha = $this->exerciseRow($exercicio, $registros, $metas->get($exerciseId), $inicio, $fim);

                if ($linha !== null) {
                    $linha['position'] = $posicoes[$exerciseId] ?? null;
                    $linha['first_seen'] = $registros->min('completed_at');
                    $linhas[] = $linha;
                }
            }

            usort($linhas, fn (array $a, array $b) => [$a['position'] ?? PHP_INT_MAX, $a['first_seen']] <=> [$b['position'] ?? PHP_INT_MAX, $b['first_seen']]);

            $secoes[] = [
                'name' => $treino?->name ?? 'Treino',
                'focus' => $treino?->focus,
                'plan' => $treino?->plan?->name,
                'sessions' => $doTreino->count(),
                'last_done' => CarbonImmutable::parse($doTreino->max('performed_on')),
                'exercises' => $linhas,
            ];
        }

        usort($secoes, fn (array $a, array $b) => [$a['plan'], $a['name']] <=> [$b['plan'], $b['name']]);

        return $secoes;
    }

    /**
     * A linha de um exercício dentro do período: de onde saiu, onde chegou, melhor marca, treinos e o que fazer.
     *
     * @param  Collection<int, GymSetLog>  $registros  séries do exercício, do início do histórico até o fim do período
     * @return array<string, mixed>|null
     */
    private function exerciseRow(GymExercise $exercicio, Collection $registros, ?GymWorkoutExercise $meta, CarbonImmutable $inicio, CarbonImmutable $fim): ?array
    {
        $analise = $this->progress->analyzeLogs($exercicio->measure_type, $registros, $meta);
        $todas = $analise['sessions'];
        $noPeriodo = array_values(array_filter($todas, fn (array $s) => $s['date']->between($inicio, $fim)));

        if ($noPeriodo === []) {
            return null;
        }

        $anteriores = array_values(array_filter($todas, fn (array $s) => $s['date']->lt($inicio)));
        $primeira = $noPeriodo[0];
        $ultima = end($noPeriodo);
        $melhor = $noPeriodo[0];
        foreach ($noPeriodo as $s) {
            if ($this->compare($s, $melhor) > 0) {
                $melhor = $s;
            }
        }

        $melhorAntes = null;
        foreach ($anteriores as $s) {
            if ($melhorAntes === null || $this->compare($s, $melhorAntes) > 0) {
                $melhorAntes = $s;
            }
        }

        $variacao = $this->percent($primeira['value'], $ultima['value']);

        return [
            'exercise' => $exercicio,
            'metric' => $analise['metric'],
            'sessions_count' => count($noPeriodo),
            'first' => $primeira,
            'last' => $ultima,
            'best' => $melhor,
            // Recorde: superou tudo o que a pessoa tinha feito antes do período (e já treinava o exercício).
            'record' => $melhorAntes !== null && $this->compare($melhor, $melhorAntes) > 0,
            'change_pct' => $variacao,
            'values' => array_map(fn (array $s) => (float) $s['value'], $noPeriodo),
            'dates' => array_map(fn (array $s) => $s['date']->format('d/m'), $noPeriodo),
            'equipment' => collect($noPeriodo)->pluck('equipment_name')->filter()->unique()->values()->all(),
            'status' => $analise['suggestion']['type'] ?? 'ok',
            'plateau' => $analise['plateau'],
            'advice' => $analise['suggestion']['message'] ?? null,
        ];
    }

    // --- Destaques ---------------------------------------------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $treinos
     * @return array{evolution: list<array<string, mixed>>, records: list<array<string, mixed>>, attention: list<array<string, mixed>>}
     */
    private function highlights(array $treinos, Collection $logs, Collection $exercicios, Collection $metas, CarbonImmutable $inicio, CarbonImmutable $fim): array
    {
        // Um exercício feito em dois treinos entra uma vez, pela melhor variação.
        $porExercicio = [];
        foreach ($treinos as $t) {
            foreach ($t['exercises'] as $linha) {
                $id = $linha['exercise']->id;
                if (! isset($porExercicio[$id]) || ($linha['change_pct'] ?? -INF) > ($porExercicio[$id]['change_pct'] ?? -INF)) {
                    $porExercicio[$id] = $linha;
                }
            }
        }
        $linhas = collect($porExercicio);

        $evolucao = $linhas->filter(fn (array $l) => $l['sessions_count'] >= 2 && $l['change_pct'] !== null && bccomp((string) $l['change_pct'], '0', 1) > 0)
            ->sortByDesc(fn (array $l) => (float) $l['change_pct'])->take(3)->values()->all();

        // Recorde olha o histórico do exercício inteiro (em qualquer treino), não só do treino da seção.
        $recordes = [];
        foreach ($logs->groupBy('exercise_id') as $exerciseId => $registros) {
            $exercicio = $exercicios->get($exerciseId);
            $linha = $exercicio ? $this->exerciseRow($exercicio, $registros, $metas->get($exerciseId), $inicio, $fim) : null;

            if ($linha !== null && $linha['record']) {
                $recordes[] = $linha;
            }
        }
        usort($recordes, fn (array $a, array $b) => $b['best']['date'] <=> $a['best']['date']);

        $atencao = $linhas->filter(fn (array $l) => in_array($l['status'], ['raise', 'plateau'], true))
            ->sortBy(fn (array $l) => $l['status'] === 'raise' ? 0 : 1)->values()->all();

        return ['evolution' => $evolucao, 'records' => array_slice($recordes, 0, 5), 'attention' => $atencao];
    }

    /**
     * Frases curtas, só com o que os números dizem.
     *
     * @param  array<string, mixed>  $resumo
     * @param  array<string, mixed>  $anterior
     * @param  array<string, list<array<string, mixed>>>  $destaques
     * @return list<string>
     */
    private function insights(array $resumo, array $anterior, array $destaques): array
    {
        if ($resumo['sessions'] === 0) {
            return [];
        }

        $frases = ["Você treinou {$resumo['per_week']} vez(es) por semana em média, em {$resumo['weeks_trained']} das {$resumo['weeks_total']} semanas do período."];

        if ($resumo['longest_gap_days'] !== null && $resumo['longest_gap_days'] >= 8) {
            $frases[] = "A maior pausa entre dois treinos foi de {$resumo['longest_gap_days']} dias.";
        }

        if ($anterior['sessions'] > 0) {
            $diferenca = $resumo['sessions'] - $anterior['sessions'];
            $frases[] = match (true) {
                $diferenca > 0 => "Foram {$diferenca} treino(s) a mais que no período anterior ({$anterior['sessions']}).",
                $diferenca < 0 => 'Foram '.abs($diferenca)." treino(s) a menos que no período anterior ({$anterior['sessions']}).",
                default => "O mesmo número de treinos do período anterior ({$anterior['sessions']}).",
            };
        }

        if ($destaques['records'] !== []) {
            $frases[] = count($destaques['records']).' recorde(s) pessoal(is) no período.';
        }

        return $frases;
    }

    // --- Apoio -------------------------------------------------------------------------------------------------------------------

    /** @return Collection<int, GymSetLog> todas as séries de sessões finalizadas até o fim do período */
    private function logsUntil(CarbonImmutable $fim): Collection
    {
        return GymSetLog::query()
            ->whereHas('session', fn ($q) => $q->whereNotNull('finished_at')->where('performed_on', '<=', $fim->toDateString()))
            ->with('session:id,workout_id,performed_on,started_at,finished_at', 'equipment:id,name')
            ->orderBy('completed_at')
            ->get();
    }

    /** @return Collection<string, GymWorkoutExercise> a meta de cada exercício no plano ativo (para o "subir a carga") */
    private function activeTargets(): Collection
    {
        return GymWorkoutExercise::query()
            ->whereHas('workout.plan', fn ($q) => $q->where('is_active', true))
            ->get()->unique('exercise_id')->keyBy('exercise_id');
    }

    /** Variação percentual de `de` para `para`, com uma casa; nulo se não houver base. */
    private function percent(string $de, string $para): ?string
    {
        if (bccomp($de, '0', 2) <= 0) {
            return null;
        }

        $bruto = bcdiv(bcmul(bcsub($para, $de, 4), '100', 4), $de, 4);

        // Meio ponto para longe do zero: bcdiv trunca, e 31,25% tem que aparecer como 31,3%.
        return bcadd($bruto, bccomp($bruto, '0', 4) >= 0 ? '0.05' : '-0.05', 1);
    }

    /** @param  array<string, mixed>  $a @param  array<string, mixed>  $b */
    private function compare(array $a, array $b): int
    {
        return bccomp($a['value'], $b['value'], 2) ?: ($a['tiebreak'] <=> $b['tiebreak']);
    }

    /** O plano que a pessoa está seguindo: o período "desta fase" começa onde ele começou. */
    public function activePlan(): ?GymPlan
    {
        return GymPlan::query()->where('is_active', true)->latest('created_at')->first();
    }
}
