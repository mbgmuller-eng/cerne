<?php

namespace App\Models;

use App\Enums\EngagementTaskOwner;
use App\Enums\EngagementTaskStatus;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Scopes\EngagementScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tarefa do acompanhamento, com dono (cliente ou consultor), prazo e situação.
 *
 * Visível só dentro da relação de consultoria (EngagementScope), além do isolamento por perfil.
 */
#[Fillable(['profile_id', 'consultant_id', 'meeting_id', 'title', 'description', 'owner', 'due_date', 'status', 'completed_at', 'created_by_user_id'])]
class EngagementTask extends Model
{
    use BelongsToProfile, HasUuids;

    protected static function booted(): void
    {
        static::addGlobalScope(new EngagementScope);
    }

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'owner' => EngagementTaskOwner::class,
            'status' => EngagementTaskStatus::class,
        ];
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consultant_id');
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(EngagementMeeting::class, 'meeting_id');
    }

    public function isOpen(): bool
    {
        return $this->status !== EngagementTaskStatus::Done;
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_date !== null && $this->due_date->lt(today());
    }
}
