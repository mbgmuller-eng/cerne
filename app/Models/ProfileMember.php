<?php

namespace App\Models;

use App\Enums\MemberRole;
use App\Support\MemberPalette;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['profile_id', 'user_id', 'name', 'role', 'color_hex', 'is_active', 'birthdate'])]
class ProfileMember extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'role' => MemberRole::class,
            'is_active' => 'boolean',
            'birthdate' => 'date',
        ];
    }

    /**
     * Os membros ativos do perfil ativo que o usuário pode ver. Quem é cuidador só vê as pessoas que o liberaram: num casal, o cuidador de um não
     * enxerga o nome nem os dados do outro (a privacidade do casal vale também para quem acompanha).
     */
    public function scopeVisibleHere(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        $contexto = app(\App\Support\ProfileContext::class);

        return $query
            ->where('profile_id', $contexto->profileId())
            ->where('is_active', true)
            ->when($contexto->isCaregiver(), fn ($q) => $q->whereIn('id', $contexto->grantedMemberIds()));
    }

    /** Cuidadores desta pessoa (quem acompanha a saúde dela). */
    public function followers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProfileCaregiver::class, 'member_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(FinancialProfile::class, 'profile_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function investorProfile(): HasOne
    {
        return $this->hasOne(InvestorProfile::class, 'member_id');
    }

    /** Cor desta pessoa no calendário da Saúde: a escolhida (`color_hex`) ou, sem escolha, a do papel dela. */
    public function calendarColor(): string
    {
        return MemberPalette::isSafe($this->color_hex)
            ? strtoupper($this->color_hex)
            : MemberPalette::defaultFor($this->role);
    }

    public function isPrimary(): bool
    {
        return $this->role === MemberRole::Primary;
    }
}
