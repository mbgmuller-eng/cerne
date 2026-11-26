<?php

namespace App\Services\Activities;

use App\Enums\ActivityRecordStatus;
use App\Enums\ActivityType;
use App\Models\ActivityRecord;
use Carbon\CarbonImmutable;

/** A evolução das atividades: carga por semana e por tipo, vezes e dor. Somado no banco. */
class ActivityProgressService
{
    /** Os grupos do gráfico, na ordem fixa das cores. */
    public const GROUPS = ['sport' => 'Esportes', 'gym' => 'Academia', 'run' => 'Caminhada e corrida', 'other' => 'Outras'];

    public function __construct(private readonly ActivityService $activities) {}

    /**
     * @return array{
     *     weeks: list<array{start: CarbonImmutable, load: array<string, int>, total: int, times: int, minutes: int, pain: ?float}>,
     *     last_four: list<array{name: string, times: int, minutes: int}>,
     *     has_data: bool,
     *     missing_effort: int
     * }
     */
    public function weekly(int $semanas = 8, ?CarbonImmutable $hoje = null): array
    {
        $hoje ??= CarbonImmutable::today();
        $atual = ActivityWeekService::weekStart($hoje);
        $inicio = $atual->subWeeks($semanas - 1);

        $this->activities->syncGymSessions($inicio, $hoje);

        $linhas = ActivityRecord::query()->where('status', ActivityRecordStatus::Done->value)
            ->whereDate('done_on', '>=', $inicio->toDateString())->whereDate('done_on', '<=', $atual->addDays(6)->toDateString())
            ->selectRaw('DATE_SUB(done_on, INTERVAL WEEKDAY(done_on) DAY) as semana, activity as tipo, COUNT(*) as vezes, SUM(minutes) as minutos, SUM(minutes * effort) as carga, SUM(effort IS NULL) as sem_esforco, SUM(pain) as dor, COUNT(pain) as com_dor')
            ->groupBy('semana', 'tipo')->toBase()->get();

        $saida = [];

        for ($i = 0; $i < $semanas; $i++) {
            $semana = $inicio->addWeeks($i);
            $da = $linhas->filter(fn (object $l) => substr((string) $l->semana, 0, 10) === $semana->toDateString());
            $carga = array_fill_keys(array_keys(self::GROUPS), 0);

            foreach ($da as $l) {
                $carga[ActivityType::from($l->tipo)->chartGroup()] += (int) $l->carga;
            }

            $comDor = (int) $da->sum('com_dor');

            $saida[] = [
                'start' => $semana,
                'load' => $carga,
                'total' => array_sum($carga),
                'times' => (int) $da->sum('vezes'),
                'minutes' => (int) $da->sum('minutos'),
                'pain' => $comDor > 0 ? round((int) $da->sum('dor') / $comDor, 1) : null,
            ];
        }

        $desde = $atual->subWeeks(3)->toDateString();
        $ultimas = $linhas->filter(fn (object $l) => substr((string) $l->semana, 0, 10) >= $desde)->groupBy('tipo')
            ->map(fn ($grupo, string $tipo) => [
                'name' => ActivityType::from($tipo)->label(),
                'times' => (int) $grupo->sum('vezes'),
                'minutes' => (int) $grupo->sum('minutos'),
            ])->sortByDesc('times')->values()->all();

        return ['weeks' => $saida, 'last_four' => $ultimas, 'has_data' => $linhas->isNotEmpty(), 'missing_effort' => (int) $linhas->sum('sem_esforco')];
    }
}
