<?php

namespace App\Services\Activities;

use App\Enums\ActivityRecordStatus;
use App\Enums\ActivityType;
use App\Models\ActivityCommitment;
use App\Models\ActivityRecord;
use App\Models\ActivityWeekMove;
use App\Models\GymWorkout;
use App\Models\RunningProgram;
use App\Services\GymPlanService;
use App\Support\Activities\RunningLadder;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

/**
 * A semana da pessoa: o que está previsto (compromissos fixos, treinos da academia nos dias preferidos, corrida) junto com o que o diário já
 * tem. O previsto NÃO é gravado: é montado na hora, e só vira linha quando a pessoa diz que fez, que não fez ou muda o dia.
 *
 * O Cerne avisa sobre o encaixe e nunca move nada sozinho.
 */
class ActivityWeekService
{
    private const GYM_EFFORT = 6;

    private const RUN_EFFORT = 4;

    /** A carga da semana é "alta" quando passa disto sobre a média das 4 anteriores. É um sinal para observar, não prevê lesão. */
    public const LOAD_ALERT_PERCENT = 30;

    public const DAYS = ['Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado', 'Domingo'];

    public function __construct(
        private readonly ActivityService $activities,
        private readonly GymPlanService $plans,
        private readonly PlanReviewService $review,
    ) {}

    public static function weekStart(CarbonImmutable $data): CarbonImmutable
    {
        return $data->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
    }

    /**
     * @return array{
     *     start: CarbonImmutable,
     *     items: Collection<int, array<string, mixed>>,
     *     flexible: list<array{name: string, expected: int, count: int}>,
     *     warnings: list<string>,
     *     load_done: int,
     *     load_projected: int,
     *     load_average: ?int,
     *     load_above: ?int,
     *     missing_effort: int
     * }
     */
    public function week(CarbonImmutable $data, ?CarbonImmutable $hoje = null): array
    {
        $hoje ??= CarbonImmutable::today();
        $inicio = self::weekStart($data);
        $fim = $inicio->addDays(6);

        $this->activities->syncGymSessions($inicio, $fim);

        $registros = ActivityRecord::query()->whereDate('done_on', '>=', $inicio->toDateString())->whereDate('done_on', '<=', $fim->toDateString())
            ->with('gymSession')->orderBy('created_at')->get();

        $treinos = $this->workouts();
        $itens = $registros->map(fn (ActivityRecord $r) => $this->fromRecord($r, $inicio, $treinos));

        // Semana passada mostra só o que está no diário: um "previsto" antigo não serve para nada.
        if ($inicio->gte(self::weekStart($hoje))) {
            $movidos = ActivityWeekMove::query()->whereDate('week_start', $inicio->toDateString())->pluck('weekday', 'planned_key');
            $compromissos = $this->activities->commitments();

            $itens = $itens
                ->concat($this->plannedCommitments($compromissos, $inicio, $registros, $movidos))
                ->concat($this->plannedGym($inicio, $hoje, $registros, $movidos, $treinos))
                ->concat($this->plannedRunning($inicio, $registros, $movidos));
        }

        $itens = $itens->sortBy([['weekday', 'asc'], ['order', 'asc'], ['name', 'asc']])->values();
        $feito = (int) $itens->where('status', 'done')->sum(fn (array $i) => $i['effort'] === null ? 0 : $i['minutes'] * $i['effort']);
        $previsto = $feito + (int) $itens->where('status', 'planned')->sum(fn (array $i) => $i['minutes'] * (int) $i['effort']);
        $media = $this->averageLoad($inicio);

        return [
            'start' => $inicio,
            'items' => $itens,
            'flexible' => $this->flexible($itens),
            'warnings' => $this->warnings($itens),
            'load_done' => $feito,
            'load_projected' => $previsto,
            'load_average' => $media,
            'load_above' => $media !== null && $previsto * 100 > $media * (100 + self::LOAD_ALERT_PERCENT) ? (int) round(($previsto / $media - 1) * 100) : null,
            'missing_effort' => $itens->where('status', 'done')->whereNull('effort')->count(),
        ];
    }

    /** @return Collection<string, GymWorkout> pelo id, com os exercícios (é por eles que se sabe se o treino é de pernas) */
    private function workouts(): Collection
    {
        return GymWorkout::query()->with('workoutExercises.exercise')->get()->keyBy('id');
    }

    private function isLegWorkout(?GymWorkout $treino): bool
    {
        if ($treino === null || $treino->workoutExercises->isEmpty()) {
            return false;
        }

        $pernas = $treino->workoutExercises->filter(fn ($i) => in_array($i->exercise?->muscle_group?->value, ['legs', 'glutes'], true))->count();

        return $pernas * 2 >= $treino->workoutExercises->count();
    }

    /**
     * @param  Collection<string, GymWorkout>  $treinos
     * @return array<string, mixed>
     */
    private function fromRecord(ActivityRecord $r, CarbonImmutable $inicio, Collection $treinos): array
    {
        $treino = $r->gymSession !== null ? $treinos->get($r->gymSession->workout_id) : null;

        if ($treino === null && str_starts_with((string) $r->planned_key, 'g:')) {
            $treino = $treinos->get(explode(':', $r->planned_key)[3] ?? '');
        }

        $prefixo = substr((string) $r->planned_key, 0, 2);

        return $this->item([
            'ref' => 'r:'.$r->id,
            'record_id' => $r->id,
            'key' => $r->planned_key,
            'type' => $r->activity,
            'name' => $treino !== null ? 'Academia: '.$treino->name : $r->name(),
            'weekday' => (int) $inicio->diffInDays($r->done_on->startOfDay()),
            'minutes' => $r->minutes,
            'status' => $r->status->value,
            'effort' => $r->effort,
            'pain' => $r->pain,
            'distance' => $r->distance_meters,
            'note' => $r->note,
            'fixed' => $prefixo === 'c:',
            // Só o que ainda é uma marcação avulsa muda de dia.
            'movable' => $r->status === ActivityRecordStatus::Planned,
            'from_gym' => $r->gym_session_id !== null,
            'legs' => $this->isLegWorkout($treino),
            'workout_id' => $treino?->id,
        ]);
    }

    /**
     * @param  Collection<int, ActivityCommitment>  $compromissos
     * @param  Collection<int, ActivityRecord>  $registros
     * @param  Collection<string, int>  $movidos
     * @return Collection<int, array<string, mixed>>
     */
    private function plannedCommitments(Collection $compromissos, CarbonImmutable $inicio, Collection $registros, Collection $movidos): Collection
    {
        $soltos = $registros->whereNull('planned_key')->where('status', ActivityRecordStatus::Done)->values();
        $usados = [];

        return $compromissos->filter(fn (ActivityCommitment $c) => $c->isFixed())->map(function (ActivityCommitment $c) use ($inicio, $registros, $movidos, $soltos, &$usados): ?array {
            $data = $inicio->addDays($c->weekday);
            $chave = 'c:'.$c->id.':'.$data->toDateString();

            if ($registros->contains('planned_key', $chave)) {
                return null;
            }

            // Registrado direto no diário, no mesmo dia: o previsto já foi cumprido, não aparece de novo.
            $cumprido = $soltos->first(fn (ActivityRecord $r) => ! isset($usados[$r->id]) && $r->activity === $c->activity && $r->done_on->isSameDay($data));

            if ($cumprido !== null) {
                $usados[$cumprido->id] = true;

                return null;
            }

            return $this->item([
                'ref' => $chave, 'key' => $chave, 'type' => $c->activity, 'name' => $c->name(), 'weekday' => (int) $movidos->get($chave, $c->weekday),
                'minutes' => $c->minutes, 'effort' => $c->effort, 'fixed' => true, 'movable' => true,
            ]);
        })->filter()->values();
    }

    /**
     * Os treinos que faltam na semana vão para os dias preferidos, na ordem do rodízio.
     *
     * @param  Collection<int, ActivityRecord>  $registros
     * @param  Collection<string, int>  $movidos
     * @param  Collection<string, GymWorkout>  $treinos
     * @return Collection<int, array<string, mixed>>
     */
    private function plannedGym(CarbonImmutable $inicio, CarbonImmutable $hoje, Collection $registros, Collection $movidos, Collection $treinos): Collection
    {
        $dias = array_values((array) $this->activities->profile()->gym_days);
        $plano = $this->plans->activePlan();

        if ($dias === [] || $plano === null) {
            return collect();
        }

        $rodizio = $treinos->where('plan_id', $plano->id)->sortBy('position')->values();

        if ($rodizio->isEmpty()) {
            return collect();
        }

        $proximo = $this->plans->nextWorkout($plano);
        $primeiro = max(0, (int) $rodizio->search(fn (GymWorkout $t) => $t->id === $proximo?->id));
        $atual = self::weekStart($hoje);

        // Semana futura: o rodízio continua de onde a atual vai parar.
        $adiante = 0;

        if ($inicio->gt($atual)) {
            $nesta = ActivityRecord::query()->where('activity', ActivityType::Gym->value)
                ->whereDate('done_on', '>=', $atual->toDateString())->whereDate('done_on', '<=', $atual->addDays(6)->toDateString())->count();
            $semanas = (int) round($atual->diffInDays($inicio) / 7);
            $adiante = max(0, count($dias) - $nesta) + ($semanas - 1) * count($dias);
        }

        $livres = array_fill_keys(array_keys($dias), true);
        $semChave = 0;

        foreach ($registros->where('activity', ActivityType::Gym) as $r) {
            $partes = explode(':', (string) $r->planned_key);

            if (($partes[0] ?? '') === 'g') {
                unset($livres[(int) ($partes[2] ?? -1)]);
            } else {
                $semChave++;
            }
        }

        // Um treino feito direto na Academia ocupa a primeira vaga livre.
        for ($i = 0; $i < $semChave && $livres !== []; $i++) {
            unset($livres[array_key_first($livres)]);
        }

        $saida = collect();
        $ordem = 0;

        foreach (array_keys($livres) as $vaga) {
            $treino = $rodizio[($primeiro + $adiante + $ordem++) % $rodizio->count()];
            $base = 'g:'.$inicio->toDateString().':'.$vaga;
            $chave = $base.':'.$treino->id;
            // A mudança de dia vale para a vaga, qualquer que seja o treino que caia nela.
            $dia = (int) $movidos->get($base, $dias[$vaga]);

            $saida->push($this->item([
                'ref' => $base, 'key' => $chave, 'move_key' => $base, 'type' => ActivityType::Gym, 'name' => 'Academia: '.$treino->name, 'weekday' => $dia,
                'minutes' => $this->review->estimateMinutes($treino->workoutExercises), 'effort' => self::GYM_EFFORT, 'movable' => true,
                'legs' => $this->isLegWorkout($treino), 'workout_id' => $treino->id,
            ]));
        }

        return $saida;
    }

    /**
     * @param  Collection<int, ActivityRecord>  $registros
     * @param  Collection<string, int>  $movidos
     * @return Collection<int, array<string, mixed>>
     */
    private function plannedRunning(CarbonImmutable $inicio, Collection $registros, Collection $movidos): Collection
    {
        $programa = RunningProgram::query()->where('is_active', true)->latest('created_at')->first();

        if ($programa === null || $inicio->lt(self::weekStart($programa->started_on->toImmutable()))) {
            return collect();
        }

        $soCaminhada = RunningLadder::isWalkingOnly($programa->step);
        $soltos = $registros->whereNull('planned_key')->where('status', ActivityRecordStatus::Done)
            ->filter(fn (ActivityRecord $r) => $r->activity === ActivityType::Running)->values();
        $usados = [];

        return collect(array_values((array) $programa->weekdays))->map(function (int|string $dia, int $i) use ($inicio, $programa, $registros, $movidos, $soltos, $soCaminhada, &$usados): ?array {
            $chave = 'k:'.$programa->id.':'.$inicio->toDateString().':'.$i;

            if ($registros->contains('planned_key', $chave)) {
                return null;
            }

            $diaFinal = (int) $movidos->get($chave, (int) $dia);
            $cumprido = $soltos->first(fn (ActivityRecord $r) => ! isset($usados[$r->id]) && $r->done_on->isSameDay($inicio->addDays($diaFinal)));

            if ($cumprido !== null) {
                $usados[$cumprido->id] = true;

                return null;
            }

            return $this->item([
                'ref' => $chave, 'key' => $chave, 'type' => $soCaminhada ? ActivityType::Walking : ActivityType::Running,
                'name' => $soCaminhada ? 'Caminhada' : 'Caminhada e trote', 'weekday' => $diaFinal,
                'minutes' => RunningLadder::minutes($programa->step), 'effort' => self::RUN_EFFORT, 'movable' => true, 'running' => true,
                'detail' => RunningLadder::describe($programa->step),
            ]);
        })->filter()->values();
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function item(array $dados): array
    {
        $item = $dados + [
            'ref' => '', 'record_id' => null, 'key' => null, 'move_key' => $dados['key'] ?? null, 'type' => ActivityType::Other, 'name' => '', 'weekday' => 0,
            'minutes' => 0, 'status' => 'planned', 'effort' => null, 'pain' => null, 'distance' => null, 'note' => null, 'detail' => null,
            'fixed' => false, 'movable' => false, 'from_gym' => false, 'legs' => false, 'workout_id' => null, 'running' => false,
        ];

        $item['running'] = $item['running'] || str_starts_with((string) $item['key'], 'k:') || ($item['type'] === ActivityType::Running);
        $item['order'] = $item['fixed'] ? 0 : 1;

        return $item;
    }

    /**
     * As práticas sem dia certo: quantas a pessoa costuma fazer e quantas já estão na semana (feitas ou marcadas).
     *
     * @param  Collection<int, array<string, mixed>>  $itens
     * @return list<array{name: string, expected: int, count: int}>
     */
    private function flexible(Collection $itens): array
    {
        return $this->activities->commitments()->reject(fn (ActivityCommitment $c) => $c->isFixed())->map(fn (ActivityCommitment $c) => [
            'name' => $c->name(),
            'expected' => (int) $c->times_per_week,
            'count' => $itens->filter(fn (array $i) => $i['type'] === $c->activity && ! $i['fixed'] && $i['status'] !== 'skipped')->count(),
        ])->values()->all();
    }

    /**
     * As três regras de encaixe, visíveis na tela.
     *
     * @param  Collection<int, array<string, mixed>>  $itens
     * @return list<string>
     */
    public function warnings(Collection $itens): array
    {
        $ativos = $itens->where('status', '!=', 'skipped');
        $avisos = [];

        foreach ($ativos->where('legs', true) as $pernas) {
            if ($ativos->contains(fn (array $i) => $i['type']->isSport() && $i['weekday'] === $pernas['weekday'] + 1)) {
                $avisos[] = 'Treino de pernas na véspera de um esporte ('.mb_strtolower(self::DAYS[$pernas['weekday']]).').';
            }
        }

        if ($ativos->isNotEmpty() && $ativos->pluck('weekday')->unique()->count() >= 7) {
            $avisos[] = 'A semana ficou sem nenhum dia de descanso.';
        }

        $corridas = $ativos->filter(fn (array $i) => $i['running']);

        foreach ($corridas as $corrida) {
            if ($ativos->contains(fn (array $i) => $i['legs'] && $i['weekday'] === $corrida['weekday'] - 1)) {
                $avisos[] = 'Corrida no dia seguinte ao treino de pernas ('.mb_strtolower(self::DAYS[$corrida['weekday']]).').';
            }

            if ($corridas->contains(fn (array $o) => $o['weekday'] === $corrida['weekday'] + 1)) {
                $avisos[] = 'Duas corridas em dias seguidos ('.mb_strtolower(self::DAYS[$corrida['weekday']]).' e o dia seguinte).';
            }
        }

        return array_values(array_unique($avisos));
    }

    /** A média da carga das 4 semanas anteriores, contando só as que têm registro; com menos de duas, não há com o que comparar. */
    public function averageLoad(CarbonImmutable $inicio): ?int
    {
        $semanas = ActivityRecord::query()->where('status', ActivityRecordStatus::Done->value)->whereNotNull('effort')
            ->whereDate('done_on', '>=', $inicio->subWeeks(4)->toDateString())->whereDate('done_on', '<', $inicio->toDateString())
            ->selectRaw('DATE_SUB(done_on, INTERVAL WEEKDAY(done_on) DAY) as semana, SUM(minutes * effort) as carga')
            ->groupBy('semana')->toBase()->get()
            ->pluck('carga')->map(fn ($c) => (int) $c)->filter(fn (int $c) => $c > 0);

        return $semanas->count() >= 2 ? (int) round($semanas->avg()) : null;
    }

    // --- Ações: o item é sempre procurado de novo na semana (a referência vem do navegador) -------------------------

    /** @return array<string, mixed> */
    public function find(CarbonImmutable $inicio, string $ref): array
    {
        $item = $this->week($inicio)['items']->firstWhere('ref', $ref);
        abort_if($item === null, 404);

        return $item;
    }

    /** "Fiz": o previsto vira uma linha feita no diário. */
    public function confirm(CarbonImmutable $inicio, string $ref, int $minutos, ?int $esforco, ?int $dor, ?CarbonImmutable $hoje = null): ActivityRecord
    {
        $hoje ??= CarbonImmutable::today();
        $inicio = self::weekStart($inicio);
        $item = $this->find($inicio, $ref);
        $data = $inicio->addDays($item['weekday']);

        if ($data->gt($hoje)) {
            throw new DomainException('Este dia ainda não chegou. Se a atividade foi antes, mude o dia dela.');
        }

        $dados = [
            'status' => ActivityRecordStatus::Done,
            'done_on' => $data->toDateString(),
            'minutes' => max(1, min(ActivityService::MAX_MINUTES, $minutos)),
            'effort' => $esforco === null ? null : max(0, min(10, $esforco)),
            'pain' => $dor === null ? null : max(0, min(10, $dor)),
        ];

        if ($item['record_id'] !== null) {
            $registro = ActivityRecord::query()->findOrFail($item['record_id']);
            $registro->update($registro->gym_session_id !== null ? collect($dados)->except('done_on')->all() : $dados);

            return $registro;
        }

        return $this->createForSlot($item, $dados);
    }

    /** "Não fiz": guarda a resposta para o item não continuar pendente. */
    public function skip(CarbonImmutable $inicio, string $ref): void
    {
        $inicio = self::weekStart($inicio);
        $item = $this->find($inicio, $ref);

        if ($item['record_id'] !== null) {
            ActivityRecord::query()->where('status', ActivityRecordStatus::Planned->value)->findOrFail($item['record_id'])
                ->update(['status' => ActivityRecordStatus::Skipped]);

            return;
        }

        $this->createForSlot($item, [
            'status' => ActivityRecordStatus::Skipped, 'done_on' => $inicio->addDays($item['weekday'])->toDateString(), 'minutes' => $item['minutes'],
        ]);
    }

    /** Desfaz um "não fiz": o previsto volta; uma marcação avulsa volta a ser prevista. */
    public function undoSkip(string $recordId): void
    {
        $registro = ActivityRecord::query()->where('status', ActivityRecordStatus::Skipped->value)->findOrFail($recordId);

        if ($registro->planned_key !== null) {
            $registro->delete();

            return;
        }

        $registro->update(['status' => ActivityRecordStatus::Planned]);
    }

    /** Muda o dia de um item só nesta semana. */
    public function move(CarbonImmutable $inicio, string $ref, int $diaDaSemana): void
    {
        $inicio = self::weekStart($inicio);
        $item = $this->find($inicio, $ref);

        if (! $item['movable'] || $diaDaSemana < 0 || $diaDaSemana > 6) {
            throw new DomainException('Este item não muda de dia.');
        }

        if ($item['record_id'] !== null) {
            ActivityRecord::query()->findOrFail($item['record_id'])->update(['done_on' => $inicio->addDays($diaDaSemana)->toDateString()]);

            return;
        }

        ActivityWeekMove::query()->updateOrCreate(
            ['planned_key' => $item['move_key']],
            ['week_start' => $inicio->toDateString(), 'weekday' => $diaDaSemana],
        );
    }

    /**
     * Uma marcação avulsa na semana ("jogo combinado no sábado").
     *
     * @param  array{activity: string, custom_name?: ?string, minutes: int, effort: int}  $d
     */
    public function plan(CarbonImmutable $data, array $d): ActivityRecord
    {
        $tipo = ActivityType::from($d['activity']);

        return ActivityRecord::create([
            'activity' => $tipo,
            'custom_name' => $tipo === ActivityType::Other ? (trim((string) ($d['custom_name'] ?? '')) ?: null) : null,
            'done_on' => $data->toDateString(),
            'status' => ActivityRecordStatus::Planned,
            'minutes' => max(5, min(ActivityService::MAX_MINUTES, (int) $d['minutes'])),
            'effort' => max(0, min(10, (int) $d['effort'])),
        ]);
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $dados
     */
    private function createForSlot(array $item, array $dados): ActivityRecord
    {
        try {
            return ActivityRecord::create($dados + [
                'activity' => $item['type'],
                'custom_name' => $item['type'] === ActivityType::Other ? $item['name'] : null,
                'planned_key' => $item['key'],
            ]);
        } catch (UniqueConstraintViolationException) {
            // Toque duplo ou outra aba: vale a linha que já está lá.
            return ActivityRecord::query()->where('planned_key', $item['key'])->firstOrFail();
        }
    }
}
