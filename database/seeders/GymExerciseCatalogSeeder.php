<?php

namespace Database\Seeders;

use App\Enums\GymMeasureType;
use App\Enums\GymMuscleGroup;
use App\Models\GymExerciseCatalog;
use Illuminate\Database\Seeder;

/**
 * Catálogo compartilhado de exercícios (Cerne Saúde › Academia) — a
 * referência que aparece ao montar um plano, pra qualquer cliente.
 *
 * Os dados vêm de database/data/gym-exercise-catalog.json em vez de um
 * array PHP gigante aqui dentro — mais fácil de revisar o diff quando um
 * exercício novo entra. Sempre profile_id nulo (padrão do sistema, ver
 * GymExerciseCatalog). Idempotente: roda de novo sem duplicar, e
 * `updateOrCreate` deixa reimportar corrigir nome/grupo/observação de um
 * já existente sem tocar na foto que já foi vinculada nele.
 */
class GymExerciseCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $linhas = json_decode(
            file_get_contents(database_path('data/gym-exercise-catalog.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        foreach ($linhas as $linha) {
            GymExerciseCatalog::query()->updateOrCreate(
                ['profile_id' => null, 'name' => $linha['name']],
                [
                    'muscle_group' => GymMuscleGroup::from($linha['muscle_group']),
                    'measure_type' => GymMeasureType::from($linha['measure_type']),
                    'equipment_hint' => $linha['equipment_hint'],
                    'notes' => $linha['notes'],
                ],
            );
        }

        $this->command?->info(count($linhas).' exercícios no catálogo compartilhado.');
    }
}
