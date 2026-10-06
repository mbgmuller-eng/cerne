<?php

namespace App\Models;

use App\Enums\GymMeasureType;
use App\Enums\GymMuscleGroup;
use App\Models\Concerns\BelongsToProfileOrShared;
use App\Models\Concerns\NormalizesNames;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo compartilhado de exercícios — referência genérica (nome, grupo
 * muscular, foto), não dado de saúde de ninguém. Ver a migration para o
 * porquê de usar BelongsToProfileOrShared em vez de IsPersonalHealthData.
 *
 * `profile_id` sempre nulo por enquanto (só entra pela seed/importação
 * do time) — a trait já suporta uma entrada "custom" de um perfil no
 * futuro, mas essa tela não oferece esse caminho ainda.
 */
#[Fillable([
    'profile_id', 'name', 'muscle_group', 'measure_type', 'equipment_hint',
    'notes', 'image_path', 'image_path_2', 'is_active',
])]
class GymExerciseCatalog extends Model
{
    use BelongsToProfileOrShared, HasUuids, NormalizesNames;

    // Eloquent pluralizaria pra "gym_exercise_catalogs" — é UM catálogo só,
    // não vários; mesmo raciocínio de GymEquipment::$table.
    protected $table = 'gym_exercise_catalog';

    protected function casts(): array
    {
        return [
            'muscle_group' => GymMuscleGroup::class,
            'measure_type' => GymMeasureType::class,
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Mesma regra de GymExercise::imageUrl() — ver lá o porquê de cada detalhe. */
    public function imageUrl(int $frame = 1): ?string
    {
        $existe = $frame === 2 ? $this->image_path_2 !== null : $this->image_path !== null;

        if (! $existe) {
            return null;
        }

        $url = route('health.gym.catalog-image', $this).'?v='.$this->updated_at?->timestamp;

        return $frame === 2 ? $url.'&f=2' : $url;
    }

    public function hasAnimatedImage(): bool
    {
        return $this->image_path !== null && $this->image_path_2 !== null;
    }
}
