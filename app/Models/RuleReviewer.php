<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um profissional de educação física convidado a revisar as regras. Não é uma conta do Cerne: entra por um link que expira e pode ser
 * revogado, e não enxerga dado de ninguém, só as regras e o catálogo de exercícios.
 */
#[Fillable(['name', 'registry', 'email', 'token_hash', 'invited_by_user_id', 'expires_at', 'revoked_at', 'last_access_at'])]
class RuleReviewer extends Model
{
    use HasUuids;

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime', 'last_access_at' => 'datetime'];
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(RuleReview::class, 'reviewer_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
