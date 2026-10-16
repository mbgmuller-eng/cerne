<?php

namespace App\Models;

use App\Enums\EngagementRequestStatus;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Scopes\EngagementScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Demanda aberta pelo cliente ("preciso de ajuda com..."): o consultor responde, resolve ou transforma em tarefa.
 *
 * Visível só dentro da relação de consultoria (EngagementScope), além do isolamento por perfil.
 */
#[Fillable(['profile_id', 'consultant_id', 'task_id', 'title', 'body', 'status', 'created_by_user_id', 'resolved_at'])]
class EngagementRequest extends Model
{
    use BelongsToProfile, HasUuids;

    protected static function booted(): void
    {
        static::addGlobalScope(new EngagementScope);
    }

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
            'status' => EngagementRequestStatus::class,
        ];
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consultant_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(EngagementRequestComment::class, 'request_id')->oldest();
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(EngagementTask::class, 'task_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
