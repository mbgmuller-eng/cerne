<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo de passo a passo do consultor, guardado em texto (ver EngagementPlanTemplateFormat). É a metodologia dele: não
 * pertence a nenhum cliente nem a perfil algum, e só o próprio consultor o enxerga (escopo por consultant_id, falha fechado).
 */
#[Fillable(['consultant_id', 'name', 'body'])]
class EngagementPlanTemplate extends Model
{
    use HasUuids;

    protected static function booted(): void
    {
        static::addGlobalScope('dono', function (Builder $builder): void {
            $usuario = auth()->user();

            $usuario === null
                ? $builder->whereRaw('1 = 0')
                : $builder->where('engagement_plan_templates.consultant_id', $usuario->id);
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
