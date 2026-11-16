<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Navegador em que a pessoa escolheu "confiar": ali o código do segundo passo não é pedido até `expires_at`. Só o hash do token fica no banco.
 * Ver `TrustedDeviceService`. É da conta (user_id), não de perfil.
 */
#[Fillable(['user_id', 'token_hash', 'label', 'expires_at', 'last_used_at'])]
class TrustedDevice extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'last_used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
