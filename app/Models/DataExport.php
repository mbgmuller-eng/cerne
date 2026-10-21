<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pedido de "baixar meus dados" (LGPD, art. 18, V e portabilidade): o zip fica em disco privado por 7 dias e só o próprio usuário baixa, por
 * link assinado. Pertence ao usuário (user_id), não a um perfil.
 */
#[Fillable(['user_id', 'status', 'path', 'size_bytes', 'ready_at', 'expires_at', 'downloaded_at'])]
class DataExport extends Model
{
    use HasUuids;

    public const PENDING = 'pending';

    public const READY = 'ready';

    public const FAILED = 'failed';

    protected function casts(): array
    {
        return ['ready_at' => 'datetime', 'expires_at' => 'datetime', 'downloaded_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isAvailable(): bool
    {
        return $this->status === self::READY && $this->path !== null && $this->expires_at !== null && $this->expires_at->isFuture();
    }
}
