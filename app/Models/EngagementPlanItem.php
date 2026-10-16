<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProfile;
use App\Models\Scopes\EngagementScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item de uma etapa. Com `auto_check`, é marcado sozinho pelo que o app sabe do cliente (done_source = auto); sem ele, só o
 * consultor marca (done_source = manual).
 *
 * Visível só dentro da relação de consultoria (EngagementScope), além do isolamento por perfil.
 */
#[Fillable(['profile_id', 'consultant_id', 'stage_id', 'position', 'title', 'auto_check', 'done_at', 'done_source'])]
class EngagementPlanItem extends Model
{
    use BelongsToProfile, HasUuids;

    protected static function booted(): void
    {
        static::addGlobalScope(new EngagementScope);
    }

    protected function casts(): array
    {
        return ['position' => 'integer', 'done_at' => 'datetime'];
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(EngagementPlanStage::class, 'stage_id');
    }

    public function isAuto(): bool
    {
        return $this->auto_check !== null;
    }
}
