<?php

namespace App\Services;

use App\Enums\GymLoadMode;
use App\Enums\GymMeasureType;
use App\Enums\GymMuscleGroup;
use App\Models\GymExercise;
use App\Models\GymPlan;
use App\Models\GymSession;
use App\Models\GymSetLog;
use App\Models\GymWorkout;
use App\Models\GymWorkoutExercise;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Importa um histórico de treino ESTRUTURADO (JSON revisado pela própria
 * pessoa) para o membro do ProfileContext: plano, treinos, exercícios e
 * sessões já finalizadas.
 *
 * Não interpreta texto livre: quem transformou o caderno/planilha em JSON
 * foi revisado por um humano antes (mesma regra dos dados extraídos por IA
 * no resto do app). Por isso validate() confere o arquivo INTEIRO antes de
 * gravar uma linha — importação pela metade é pior que nenhuma.
 *
 * Idempotente: rodar duas vezes não duplica. Sessão é reconhecida por
 * (treino, data); plano, treino, exercício e equipamento por nome.
 *
 * Formato (version 1): ver database/examples/gym-history.example.json.
 */
class GymHistoryImporter
{
    public function __construct(private GymPlanService $plans) {}

    /**
     * @param  array<string, mixed>  $data
     * @return list<string> problemas encontrados (vazio = arquivo válido)
     */
    public function validate(array $data): array
    {
        $erros = [];

        if (($data['version'] ?? null) !== 1) {
            return ['Campo "version" deve ser 1.'];
        }

        $plano = $data['plan'] ?? null;
        if (! is_array($plano) || trim((string) ($plano['name'] ?? '')) === '' || ! is_array($plano['workouts'] ?? null)) {
            return ['Bloco "plan" precisa de "name" e "workouts".'];
        }

        // treino => [exercício => tipo de medida]
        $catalogo = [];
        foreach ($plano['workouts'] as $i => $treino) {
            $nomeTreino = trim((string) ($treino['name'] ?? ''));
            if ($nomeTreino === '') {
                $erros[] = "plan.workouts[$i]: falta o nome do treino.";

                continue;
            }
            if (isset($catalogo[$nomeTreino])) {
                $erros[] = "plan.workouts[$i]: treino \"$nomeTreino\" repetido.";
            }

            foreach (($treino['exercises'] ?? []) as $j => $ex) {
                $ref = "plan.workouts[$i].exercises[$j]";
                $nome = trim((string) ($ex['name'] ?? ''));
                if ($nome === '' || mb_strlen($nome) > 120) {
                    $erros[] = "$ref: nome inválido.";

                    continue;
                }
                if (GymMuscleGroup::tryFrom((string) ($ex['muscle_group'] ?? '')) === null) {
                    $erros[] = "$ref ($nome): muscle_group inválido.";
                }
                $tipo = GymMeasureType::tryFrom((string) ($ex['measure_type'] ?? ''));
                if ($tipo === null) {
                    $erros[] = "$ref ($nome): measure_type inválido.";
                }
                if (! is_int($ex['sets'] ?? null) || $ex['sets'] < 1 || $ex['sets'] > 20) {
                    $erros[] = "$ref ($nome): \"sets\" deve ser inteiro de 1 a 20.";
                }
                $catalogo[$nomeTreino][$nome] = $tipo;
            }
            $catalogo[$nomeTreino] ??= [];
        }

        foreach (($data['sessions'] ?? []) as $i => $sessao) {
            $ref = "sessions[$i]";
            $treino = (string) ($sessao['workout'] ?? '');

            if (! isset($catalogo[$treino])) {
                $erros[] = "$ref: treino \"$treino\" não existe no plano.";

                continue;
            }
            if (! $this->validDate($sessao['date'] ?? null)) {
                $erros[] = "$ref ($treino): data inválida (use AAAA-MM-DD).";
            }
            if (($sessao['sets'] ?? []) === [] || ! is_array($sessao['sets'] ?? null)) {
                $erros[] = "$ref ($treino): sem séries.";

                continue;
            }

            $vistas = [];
            foreach ($sessao['sets'] as $j => $serie) {
                $r = "$ref.sets[$j]";
                $nome = (string) ($serie['exercise'] ?? '');
                $tipo = $catalogo[$treino][$nome] ?? null;

                if ($tipo === null) {
                    $erros[] = "$r: exercício \"$nome\" não está no treino \"$treino\".";

                    continue;
                }
                $n = $serie['set'] ?? null;
                if (! is_int($n) || $n < 1) {
                    $erros[] = "$r ($nome): \"set\" deve ser inteiro ≥ 1.";
                } elseif (isset($vistas[$nome][$n])) {
                    $erros[] = "$r ($nome): série $n repetida.";
                }
                $vistas[$nome][$n] = true;

                if ($tipo === GymMeasureType::LoadReps) {
                    if (! is_int($serie['reps'] ?? null) || $serie['reps'] < 1 || $serie['reps'] > 999) {
                        $erros[] = "$r ($nome): \"reps\" deve ser inteiro de 1 a 999.";
                    }
                    if (isset($serie['load']) && ! preg_match('/^\d{1,4}(\.\d{1,2})?$/', (string) $serie['load'])) {
                        $erros[] = "$r ($nome): \"load\" inválido (use ponto decimal, ex.: \"12.5\").";
                    }
                    $modo = $serie['mode'] ?? null;
                    if ($modo !== null && GymLoadMode::tryFrom((string) $modo) === null) {
                        $erros[] = "$r ($nome): \"mode\" inválido.";
                    }
                    if (isset($serie['load']) && $modo === null) {
                        $erros[] = "$r ($nome): carga informada sem \"mode\".";
                    }
                } elseif (! is_int($serie['duration'] ?? null) || $serie['duration'] < 1) {
                    $erros[] = "$r ($nome): exercício em tempo precisa de \"duration\" (segundos).";
                }
            }
        }

        return $erros;
    }

    /**
     * Grava tudo numa transação. `$dryRun` faz o mesmo caminho e desfaz no
     * fim — os números do relatório são os que a importação real produziria.
     *
     * @param  array<string, mixed>  $data  já validado
     * @return array{exercises: int, equipment: int, workouts: int, plan_exercises: int, sessions: int, sets: int, skipped_sessions: int, plan_created: bool}
     */
    public function import(array $data, bool $dryRun = false): array
    {
        $relatorio = [
            'exercises' => 0, 'equipment' => 0, 'workouts' => 0, 'plan_exercises' => 0,
            'sessions' => 0, 'sets' => 0, 'skipped_sessions' => 0, 'plan_created' => false,
        ];

        DB::beginTransaction();

        try {
            $spec = $data['plan'];
            $plano = GymPlan::query()->where('name', trim($spec['name']))->first();

            if ($plano === null) {
                // Não rouba o "ativo" de um plano que a pessoa já montou à mão.
                $plano = GymPlan::create([
                    'name' => trim($spec['name']),
                    'is_active' => ! GymPlan::query()->where('is_active', true)->exists(),
                    'started_on' => $spec['started_on'] ?? null,
                ]);
                $relatorio['plan_created'] = true;
            }

            /** @var array<string, GymExercise> $exercicios */
            $exercicios = [];
            /** @var array<string, GymWorkout> $treinos */
            $treinos = [];

            foreach ($spec['workouts'] as $t) {
                $treino = GymWorkout::query()->where('plan_id', $plano->id)->where('name', trim($t['name']))->first();
                if ($treino === null) {
                    $treino = $this->plans->addWorkout($plano, $t['name'], $t['focus'] ?? null);
                    $relatorio['workouts']++;
                }
                $treinos[trim($t['name'])] = $treino;

                foreach ($t['exercises'] ?? [] as $ex) {
                    $existia = GymExercise::query()->where('name', trim($ex['name']))->exists();
                    $exercicio = $this->plans->findOrCreateExercise(
                        $ex['name'],
                        GymMuscleGroup::from($ex['muscle_group']),
                        GymMeasureType::from($ex['measure_type']),
                    );
                    $relatorio['exercises'] += $existia ? 0 : 1;
                    $exercicios[trim($ex['name'])] = $exercicio;

                    if (GymWorkoutExercise::query()->where('workout_id', $treino->id)->where('exercise_id', $exercicio->id)->exists()) {
                        continue;
                    }

                    $equipamento = $this->equipment($ex['equipment'] ?? null, $relatorio);
                    $this->plans->addExercise($treino, $exercicio, [
                        'target_sets' => $ex['sets'],
                        'target_reps_min' => $ex['reps_min'] ?? null,
                        'target_reps_max' => $ex['reps_max'] ?? null,
                        'target_duration_seconds' => $ex['duration_seconds'] ?? null,
                        'rest_seconds' => $ex['rest_seconds'] ?? null,
                        'default_equipment_id' => $equipamento?->id,
                        'notes' => $ex['notes'] ?? null,
                    ]);
                    $relatorio['plan_exercises']++;
                }
            }

            foreach ($data['sessions'] ?? [] as $s) {
                $treino = $treinos[$s['workout']];
                $dia = Carbon::parse($s['date']);

                if (GymSession::query()->where('workout_id', $treino->id)->whereDate('performed_on', $dia->toDateString())->exists()) {
                    $relatorio['skipped_sessions']++;

                    continue;
                }

                // Sem horário no caderno: meio-dia. Só precisa ordenar as sessões entre si.
                $inicio = $dia->copy()->setTime(12, 0);
                $sessao = GymSession::create([
                    'workout_id' => $treino->id,
                    'performed_on' => $dia->toDateString(),
                    'started_at' => $inicio,
                    'finished_at' => $inicio->copy()->addHour(),
                    'notes' => trim(($s['date_approx'] ?? false ? 'Data aproximada. ' : '').($s['notes'] ?? '')) ?: null,
                ]);
                $relatorio['sessions']++;

                foreach ($s['sets'] as $i => $serie) {
                    $exercicio = $exercicios[$serie['exercise']];
                    $porCarga = $exercicio->measure_type === GymMeasureType::LoadReps;
                    $equipamento = $this->equipment($serie['equipment'] ?? null, $relatorio);

                    GymSetLog::create([
                        'session_id' => $sessao->id,
                        'exercise_id' => $exercicio->id,
                        'set_number' => $serie['set'],
                        'reps' => $porCarga ? $serie['reps'] : null,
                        'load_value' => $porCarga ? ($serie['load'] ?? null) : null,
                        'load_mode' => $porCarga && ($serie['mode'] ?? null) !== null ? GymLoadMode::from($serie['mode']) : null,
                        'duration_seconds' => $porCarga ? null : $serie['duration'],
                        'distance_meters' => $serie['distance'] ?? null,
                        'equipment_id' => $equipamento?->id,
                        'completed_at' => $inicio->copy()->addMinutes($i + 1),
                    ]);
                    $relatorio['sets']++;
                }
            }

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return $relatorio;
    }

    /** @param  array<string, mixed>  $relatorio */
    private function equipment(?string $nome, array &$relatorio)
    {
        if ($nome === null || trim($nome) === '') {
            return null;
        }

        $existia = \App\Models\GymEquipment::query()->where('name', trim($nome))->exists();
        $relatorio['equipment'] += $existia ? 0 : 1;

        return $this->plans->findOrCreateEquipment($nome);
    }

    private function validDate(mixed $valor): bool
    {
        if (! is_string($valor) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            return false;
        }

        [$a, $m, $d] = array_map('intval', explode('-', $valor));

        return checkdate($m, $d, $a);
    }
}
