<?php

namespace App\Models;

use App\Enums\LeadStage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Contato que ainda não é cliente — a etapa que falta antes de
 * ConsultantInvite/ConsultantClient (ver LeadService::convert()). Não
 * pertence a um perfil (não existe perfil nenhum ainda), só ao consultor
 * dono do relacionamento — ver TenancyCoverageTest::EXEMPT.
 */
#[Fillable(['consultant_id', 'name', 'email', 'phone', 'stage', 'notes', 'lost_reason', 'next_action_at'])]
class Lead extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'stage' => LeadStage::class,
            'next_action_at' => 'datetime',
        ];
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consultant_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class);
    }

    /** Ainda em andamento — não convertido nem perdido. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('stage', [LeadStage::Converted->value, LeadStage::Lost->value]);
    }
}
