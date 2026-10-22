<?php

namespace App\Models;

use App\Enums\ProfileType;
use App\Models\Scopes\CareProfileScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * O perfil financeiro é a unidade de isolamento do sistema: toda query do
 * domínio filtra por profile_id (ver BelongsToProfile).
 *
 * Note que ESTE model não usa BelongsToProfile — ele é o próprio tenant.
 */
#[Fillable(['owner_user_id', 'profile_name', 'profile_type', 'base_currency', 'reference_month', 'care_consent_by_user_id', 'care_consent_at', 'care_consent_basis', 'care_consent_version'])]
class FinancialProfile extends Model
{
    use HasFactory, HasUuids;

    protected static function booted(): void
    {
        static::addGlobalScope(new CareProfileScope);
    }

    /** Consulta que enxerga também os perfis de cuidado (pessoa acompanhada), escondidos por padrão: ver CareProfileScope. */
    public static function withCareProfiles(): Builder
    {
        return static::query()->withoutGlobalScope(CareProfileScope::class);
    }

    public function isCare(): bool
    {
        return $this->profile_type === ProfileType::Care;
    }

    protected function casts(): array
    {
        return [
            'profile_type' => ProfileType::class,
            'reference_month' => 'integer',
            'care_consent_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ProfileMember::class, 'profile_id');
    }

    public function activeMembers(): HasMany
    {
        return $this->members()->where('is_active', true);
    }

    public function caregivers(): HasMany
    {
        return $this->hasMany(ProfileCaregiver::class, 'profile_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'profile_id');
    }

    public function memberFor(User $user): ?ProfileMember
    {
        return $this->members()->where('user_id', $user->id)->first();
    }
}
