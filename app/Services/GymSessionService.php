<?php

namespace App\Services;

use App\Enums\GymLoadMode;
use App\Enums\GymMeasureType;
use App\Models\GymExercise;
use App\Models\GymSession;
use App\Models\GymSetLog;
use App\Models\GymWorkout;
use App\Models\GymWorkoutExercise;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Registro de uma ida à academia: abrir/retomar a sessão, gravar uma linha
 * por série e fechar. Roda sob o ProfileContext da pessoa (escopo de
 * saúde pessoal), como GymPlanService.
 */
class GymSessionService
{
    public function inProgress(): ?GymSession
    {
        return GymSession::query()->inProgress()->latest('started_at')->first();
    }

    /**
     * Uma sessão aberta por vez: iniciar com uma já em andamento retoma a
     * existente em vez de empilhar outra — duas sessões abertas dividiriam
     * as séries entre si e o "próximo treino" da rotação perderia o rumo.
     */
    public function start(GymWorkout $workout): GymSession
    {
        return $this->inProgress() ?? GymSession::create([
            'workout_id' => $workout->id,
            'performed_on' => now()->toDateString(),
            'started_at' => now(),
        ]);
    }

    /**
     * Grava a série `$setNumber` do exercício, ou devolve null se ela já
     * existe (toque duplo, tela desatualizada). O número vem da tela e
     * tem que ser exatamente o próximo: assim o segundo toque de um
     * duplo-toque não vira uma série a mais. O índice único
     * (sessão, exercício, série) cobre a corrida entre duas abas.
     *
     * @param  array{load?: ?string, mode?: ?string, reps?: ?int, duration?: ?int, distance?: ?int, equipment_id?: ?string}  $data
     */
    public function recordSet(GymSession $session, GymWorkoutExercise $item, int $setNumber, array $data): ?GymSetLog
    {
        $this->assertEditable($session, $item);

        $proxima = (int) GymSetLog::query()
            ->where('session_id', $session->id)
            ->where('exercise_id', $item->exercise_id)
            ->max('set_number') + 1;

        if ($setNumber !== $proxima) {
            return null;
        }

        $tipo = $item->exercise->measure_type;
        $porCarga = $tipo === GymMeasureType::LoadReps;

        try {
            return GymSetLog::create([
                'session_id' => $session->id,
                'exercise_id' => $item->exercise_id,
                'set_number' => $setNumber,
                'reps' => $porCarga ? ($data['reps'] ?? null) : null,
                'load_value' => $porCarga ? $this->decimal($data['load'] ?? null) : null,
                'load_mode' => $porCarga ? $this->mode($data['mode'] ?? null, $data['load'] ?? null) : null,
                'duration_seconds' => $porCarga ? null : ($data['duration'] ?? null),
                'distance_meters' => $tipo === GymMeasureType::DistanceDuration ? ($data['distance'] ?? null) : null,
                'equipment_id' => $data['equipment_id'] ?? null,
                'completed_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    public function undoLastSet(GymSession $session, GymWorkoutExercise $item): void
    {
        $this->assertEditable($session, $item);

        GymSetLog::query()
            ->where('session_id', $session->id)
            ->where('exercise_id', $item->exercise_id)
            ->orderByDesc('set_number')
            ->first()
            ?->delete();
    }

    public function finish(GymSession $session): void
    {
        if (! $session->isInProgress()) {
            return;
        }

        // Sessão sem nenhuma série não vira histórico: só polui a rotação.
        if (! GymSetLog::query()->where('session_id', $session->id)->exists()) {
            throw new DomainException('Registre ao menos uma série antes de finalizar, ou descarte o treino.');
        }

        $session->update(['finished_at' => now()]);
    }

    /** Descartar apaga a sessão e as séries dela (cascata) — só enquanto em andamento. */
    public function discard(GymSession $session): void
    {
        if ($session->isInProgress()) {
            $session->delete();
        }
    }

    /**
     * Resumo da última vez que o exercício foi feito, pra prefill e pra
     * a linha "última vez" da tela. Ignora a sessão `$exclude`; com `$only`,
     * olha só aquela sessão (retomar um treino recarregado no meio).
     *
     * @return array{load: string, mode: string, equipment_id: ?string, reps: list<int>, duration: list<int>, date: string, label: string}|null
     */
    public function lastPerformance(GymExercise $exercise, ?GymSession $exclude = null, ?GymSession $only = null): ?array
    {
        $ultima = GymSetLog::query()
            ->where('exercise_id', $exercise->id)
            ->when($exclude, fn ($q) => $q->where('session_id', '!=', $exclude->id))
            ->when($only, fn ($q) => $q->where('session_id', $only->id))
            ->orderByDesc('completed_at')
            ->first();

        if ($ultima === null) {
            return null;
        }

        $series = GymSetLog::query()
            ->where('session_id', $ultima->session_id)
            ->where('exercise_id', $exercise->id)
            ->orderBy('set_number')
            ->get();

        // Carga e equipamento de referência: a última série (a mais pesada
        // costuma ser a de trabalho; a aquecimento vem antes).
        $ref = $series->last();
        $reps = $series->pluck('reps')->filter()->values()->all();
        $tempos = $series->pluck('duration_seconds')->filter()->values()->all();

        $partes = [];
        if ($ref->load_value !== null) {
            $partes[] = rtrim(rtrim($ref->load_value, '0'), '.').' kg'.($ref->load_mode ? ' ('.mb_strtolower($ref->load_mode->label()).')' : '');
        }
        if ($reps !== []) {
            $partes[] = implode(', ', $reps).' reps';
        }
        if ($tempos !== []) {
            $partes[] = implode(', ', $tempos).' s';
        }

        return [
            'load' => $ref->load_value === null ? '' : rtrim(rtrim($ref->load_value, '0'), '.'),
            'mode' => $ref->load_mode?->value ?? GymLoadMode::Total->value,
            'equipment_id' => $ref->equipment_id,
            'reps' => $reps,
            'duration' => $tempos,
            'date' => $ultima->completed_at?->format('d/m') ?? '',
            'label' => implode(' · ', $partes),
        ];
    }

    private function assertEditable(GymSession $session, GymWorkoutExercise $item): void
    {
        if (! $session->isInProgress()) {
            throw new DomainException('Este treino já foi finalizado.');
        }

        // O exercício tem que ser do treino da sessão, senão dava pra
        // gravar série de um treino noutro.
        if ($item->workout_id !== $session->workout_id) {
            throw new DomainException('Exercício não pertence a este treino.');
        }
    }

    private function decimal(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return bcadd(str_replace(',', '.', trim($value)), '0', 2);
    }

    private function mode(?string $mode, ?string $load): ?GymLoadMode
    {
        $modo = $mode !== null && $mode !== '' ? GymLoadMode::tryFrom($mode) : null;

        // Sem carga digitada e sem modo é uma série sem peso (só reps);
        // com carga, o padrão é "total".
        if ($modo === null && $load !== null && trim($load) !== '') {
            return GymLoadMode::Total;
        }

        return $modo;
    }
}
