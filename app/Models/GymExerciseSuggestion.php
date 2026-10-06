<?php

namespace App\Models;

use App\Enums\GymMeasureType;
use App\Enums\GymMuscleGroup;
use App\Models\Concerns\NormalizesNames;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Exercício que alguém digitou no próprio treino e que não existe no catálogo
 * compartilhado. ANÔNIMO: não guarda perfil, membro nem usuário (treino é
 * dado de Saúde) — só nome, grupo, tipo e quantas vezes foi pedido. Ver a
 * migration.
 */
#[Fillable(['name', 'normalized_name', 'muscle_group', 'measure_type', 'times_suggested', 'dismissed_at', 'approved_at'])]
class GymExerciseSuggestion extends Model
{
    use HasUuids, NormalizesNames;

    protected function casts(): array
    {
        return [
            'muscle_group' => GymMuscleGroup::class,
            'measure_type' => GymMeasureType::class,
            'times_suggested' => 'integer',
            'dismissed_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('dismissed_at')->whereNull('approved_at');
    }

    /**
     * Registra o pedido. Nome que já está no catálogo (ignorando caixa e
     * acento), dispensado ou já aprovado não entra de novo na fila. A criação
     * e o contador passam pelo índice único: insere; se já existia, soma.
     */
    public static function record(string $name, GymMuscleGroup $group, GymMeasureType $type): void
    {
        $name = trim($name);
        $normalizado = self::normalizeName($name);

        if ($normalizado === '') {
            return;
        }

        $noCatalogo = GymExerciseCatalog::withoutTaxonomyScope()->pluck('name')
            ->contains(fn (string $existente): bool => self::normalizeName($existente) === $normalizado);

        if ($noCatalogo) {
            return;
        }

        $agora = now();

        $inseriu = DB::table('gym_exercise_suggestions')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'normalized_name' => $normalizado,
            'muscle_group' => $group->value,
            'measure_type' => $type->value,
            'times_suggested' => 1,
            'created_at' => $agora,
            'updated_at' => $agora,
        ]);

        if ($inseriu === 0) {
            DB::table('gym_exercise_suggestions')
                ->where('normalized_name', $normalizado)
                ->whereNull('dismissed_at')
                ->whereNull('approved_at')
                ->update(['times_suggested' => DB::raw('times_suggested + 1'), 'updated_at' => $agora]);
        }
    }

    public function dismiss(): void
    {
        $this->update(['dismissed_at' => now()]);
    }

    public function markApproved(): void
    {
        $this->update(['approved_at' => now()]);
    }
}
