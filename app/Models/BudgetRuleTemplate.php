<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo de regra de orçamento do consultor: a metodologia dele, que não pertence a nenhum cliente nem a perfil algum. Só o próprio consultor o
 * enxerga (escopo por consultant_id, falha fechado), como o modelo de passo a passo. Aplicar a um cliente COPIA a estrutura para uma `BudgetRule`;
 * mudar o modelo depois não altera a regra do cliente.
 */
#[Fillable(['consultant_id', 'name', 'description', 'structure'])]
class BudgetRuleTemplate extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['structure' => 'array'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('dono', function (Builder $builder): void {
            $usuario = auth()->user();

            $usuario === null
                ? $builder->whereRaw('1 = 0')
                : $builder->where('budget_rule_templates.consultant_id', $usuario->id);
        });

        static::creating(function (self $modelo): void {
            $modelo->consultant_id ??= auth()->id();
        });
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consultant_id');
    }
}
