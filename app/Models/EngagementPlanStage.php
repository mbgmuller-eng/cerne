<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProfile;
use App\Models\Scopes\EngagementScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Etapa do passo a passo de um cliente (ex.: "Reserva de emergência"), com seus itens.
 *
 * Visível só dentro da relação de consultoria (EngagementScope), além do isolamento por perfil.
 */
#[Fillable(['profile_id', 'consultant_id', 'position', 'title', 'description'])]
class EngagementPlanStage extends Model
{
    use BelongsToProfile, HasUuids;

    protected static function booted(): void
    {
        static::addGlobalScope(new EngagementScope);
    }

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(EngagementPlanItem::class, 'stage_id')->orderBy('position');
    }

    /** Percentual de itens feitos (0 a 100); etapa sem itens conta como 0. */
    public function progressPercent(): int
    {
        $total = $this->items->count();

        return $total === 0 ? 0 : (int) floor($this->items->whereNotNull('done_at')->count() / $total * 100);
    }

    public function isDone(): bool
    {
        return $this->items->isNotEmpty() && $this->items->every(fn (EngagementPlanItem $i) => $i->done_at !== null);
    }
}
