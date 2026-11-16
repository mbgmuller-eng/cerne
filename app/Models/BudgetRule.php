<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToProfile;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A regra de orçamento de um perfil a partir de um mês (ver a migração e App\Support\Budget\BudgetStructure). Uma por perfil, valendo para o casal
 * inteiro: a regra é configuração, não lançamento, então não tem dono nem marca de privado (o que muda por pessoa são os NÚMEROS, calculados sobre
 * o que quem olha enxerga: BudgetRuleService).
 */
#[Fillable(['profile_id', 'effective_year', 'effective_month', 'structure', 'created_by_user_id'])]
class BudgetRule extends Model
{
    use Auditable, BelongsToProfile, HasUuids;

    protected function casts(): array
    {
        return [
            'effective_year' => 'integer',
            'effective_month' => 'integer',
            'structure' => 'array',
        ];
    }

    /** A regra que vale em um mês: a mais recente com início até ele. */
    public function scopeValidFor(Builder $query, int $year, int $month): Builder
    {
        return $query->where(function (Builder $q) use ($year, $month): void {
            $q->where('effective_year', '<', $year)
                ->orWhere(fn (Builder $q2) => $q2->where('effective_year', $year)->where('effective_month', '<=', $month));
        })->orderByDesc('effective_year')->orderByDesc('effective_month');
    }
}
