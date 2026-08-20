<?php

namespace App\Models;

use App\Enums\AuditAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only por contrato: a especificação exige que o log não possa ser
 * alterado nem apagado pela aplicação. As sobrescritas abaixo transformam
 * uma tentativa em erro em vez de deixá-la passar silenciosamente.
 */
#[Fillable([
    'profile_id', 'user_id', 'action', 'entity_type', 'entity_id',
    'old_value', 'new_value', 'ip_address', 'user_agent',
])]
class AuditLog extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'old_value' => 'array',
            'new_value' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(FinancialProfile::class, 'profile_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new \LogicException('O log de auditoria é append-only e não pode ser alterado.');
        });

        static::deleting(function (): never {
            throw new \LogicException('O log de auditoria é append-only e não pode ser excluído.');
        });
    }
}
