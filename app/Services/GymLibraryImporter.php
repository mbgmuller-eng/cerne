<?php

namespace App\Services;

use App\Enums\GymMeasureType;
use App\Enums\GymMuscleGroup;
use App\Models\GymExerciseCatalog;
use Illuminate\Http\UploadedFile;

/**
 * Traz para o catálogo compartilhado a biblioteca pública de exercícios (database/data/gym-exercise-library.json, tradução em
 * português do free-exercise-db, domínio público): o passo a passo dos exercícios que já existem e os que ainda não existem,
 * com as duas fotos.
 *
 * Idempotente: o `source_id` (id na biblioteca) acha o exercício na segunda rodada e o nome, sem caixa nem acento, adota um
 * que já existia com outro cadastro. Nunca apaga nada, nunca troca foto que já está lá e só reescreve o passo a passo com
 * `$sobrescrever`, porque o admin pode ter ajustado o texto.
 */
class GymLibraryImporter
{
    public function __construct(private readonly GymCatalogImageService $imagens) {}

    /**
     * @param  list<array<string, mixed>>  $entradas
     * @param  string|null  $pastaImagens  pasta com {source_id}/0.jpg e {source_id}/1.jpg (quadro inicial e final)
     * @return array{instrucoes: int, criados: int, adotados: int, ja_importados: int, fotos: int, sem_foto: list<string>, sem_par: list<string>}
     */
    public function import(array $entradas, ?string $pastaImagens = null, bool $simular = false, bool $sobrescrever = false): array
    {
        $r = ['instrucoes' => 0, 'criados' => 0, 'adotados' => 0, 'ja_importados' => 0, 'fotos' => 0, 'sem_foto' => [], 'sem_par' => []];
        $compartilhados = GymExerciseCatalog::withoutTaxonomyScope()->shared()->withoutInstructions()->get();
        $porNome = $compartilhados->keyBy(fn (GymExerciseCatalog $c) => GymExerciseCatalog::normalizeName($c->name));
        $porOrigem = $compartilhados->whereNotNull('source_id')->keyBy('source_id');

        foreach ($entradas as $e) {
            $passos = array_values(array_filter(array_map('trim', (array) ($e['instructions'] ?? [])), fn (string $p) => $p !== ''));

            if (isset($e['match'])) {
                $exercicio = $porNome->get(GymExerciseCatalog::normalizeName((string) $e['match']));

                if ($exercicio === null) {
                    $r['sem_par'][] = (string) $e['match'];

                    continue;
                }

                $r['instrucoes'] += $this->applyInstructions($exercicio, $passos, $simular, $sobrescrever) ? 1 : 0;

                continue;
            }

            $exercicio = $porOrigem->get($e['source_id']);
            $jaImportado = $exercicio !== null;
            $exercicio ??= $porNome->get(GymExerciseCatalog::normalizeName((string) $e['name']));

            if ($exercicio === null) {
                $r['criados']++;

                if (! $simular) {
                    $exercicio = GymExerciseCatalog::create([
                        'profile_id' => null, 'name' => $e['name'], 'muscle_group' => GymMuscleGroup::from($e['muscle_group']),
                        'measure_type' => GymMeasureType::from($e['measure_type']), 'equipment_hint' => $e['equipment_hint'] ?? null,
                        'instructions' => $passos ?: null, 'source_id' => $e['source_id'], 'in_auto_workout' => false,
                    ]);
                }
            } else {
                $jaImportado ? $r['ja_importados']++ : $r['adotados']++;
                $r['instrucoes'] += $this->applyInstructions($exercicio, $passos, $simular, $sobrescrever) ? 1 : 0;

                if (! $simular && $exercicio->source_id === null) {
                    $exercicio->update(['source_id' => $e['source_id']]);
                }
            }

            if ($exercicio !== null && ! $simular) {
                $this->attachPhotos($exercicio, (string) $e['source_id'], $pastaImagens, $r);
            }
        }

        return $r;
    }

    /** @param  list<string>  $passos */
    private function applyInstructions(GymExerciseCatalog $exercicio, array $passos, bool $simular, bool $sobrescrever): bool
    {
        if ($passos === []) {
            return false;
        }

        // `withoutInstructions` não carrega a coluna: pergunta direto ao banco se já há texto.
        $jaTem = GymExerciseCatalog::withoutTaxonomyScope()->whereKey($exercicio->getKey())->whereNotNull('instructions')->exists();

        if ($jaTem && ! $sobrescrever) {
            return false;
        }

        if (! $simular) {
            GymExerciseCatalog::withoutTaxonomyScope()->whereKey($exercicio->getKey())->update(['instructions' => json_encode($passos, JSON_UNESCAPED_UNICODE)]);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $r
     */
    private function attachPhotos(GymExerciseCatalog $exercicio, string $origem, ?string $pasta, array &$r): void
    {
        if ($pasta === null || $exercicio->image_path !== null) {
            return;
        }

        $quadro1 = rtrim($pasta, '/\\').'/'.$origem.'/0.jpg';
        $quadro2 = rtrim($pasta, '/\\').'/'.$origem.'/1.jpg';

        if (! is_file($quadro1)) {
            $r['sem_foto'][] = $exercicio->name;

            return;
        }

        $this->imagens->replacePair($exercicio, $this->upload($quadro1), is_file($quadro2) ? $this->upload($quadro2) : null);
        $r['fotos']++;
    }

    private function upload(string $caminho): UploadedFile
    {
        return new UploadedFile($caminho, basename($caminho), 'image/jpeg', null, true);
    }
}
