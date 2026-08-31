<?php

namespace App\Models;

use App\Enums\ThemePreference;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use NotificationChannels\WebPush\HasPushSubscriptions;

#[Fillable([
    'name', 'email', 'password', 'role', 'phone', 'avatar_url', 'is_active', 'theme',
    'notify_email_enabled', 'notify_push_enabled',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPushSubscriptions, HasUuids, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'theme' => ThemePreference::class,
            'notify_email_enabled' => 'boolean',
            'notify_push_enabled' => 'boolean',
        ];
    }

    // ---------------------------------------------------------------------
    // Papéis
    // ---------------------------------------------------------------------

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isConsultant(): bool
    {
        return $this->role === UserRole::Consultant;
    }

    public function isClient(): bool
    {
        return $this->role === UserRole::Client;
    }

    // ---------------------------------------------------------------------
    // Relacionamentos
    // ---------------------------------------------------------------------

    /** Perfis dos quais este usuário é o dono. */
    public function ownedProfiles(): HasMany
    {
        return $this->hasMany(FinancialProfile::class, 'owner_user_id');
    }

    /** Cadeiras que este usuário ocupa em perfis (inclusive de terceiros). */
    public function memberships(): HasMany
    {
        return $this->hasMany(ProfileMember::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** Vínculos em que este usuário é o CONSULTOR. */
    public function clientLinks(): HasMany
    {
        return $this->hasMany(ConsultantClient::class, 'consultant_id');
    }

    /** Vínculos em que este usuário é o CLIENTE. */
    public function consultantLinks(): HasMany
    {
        return $this->hasMany(ConsultantClient::class, 'client_id');
    }

    /**
     * Perfis que este usuário consegue abrir: os que possui, os em que é
     * membro, e — se for consultor — os dos clientes vinculados.
     *
     * @return \Illuminate\Support\Collection<int, FinancialProfile>
     */
    public function accessibleProfiles(): \Illuminate\Support\Collection
    {
        $owned = FinancialProfile::query()
            ->where('owner_user_id', $this->id);

        $memberOf = FinancialProfile::query()
            ->whereIn('id', ProfileMember::query()
                ->where('user_id', $this->id)
                ->where('is_active', true)
                ->select('profile_id'));

        $profiles = $owned->union($memberOf);

        if ($this->isConsultant()) {
            $clientIds = ConsultantClient::query()
                ->where('consultant_id', $this->id)
                ->where('status', \App\Enums\ConsultantClientStatus::Active)
                ->pluck('client_id');

            $profiles = $profiles->union(
                FinancialProfile::query()->whereIn('owner_user_id', $clientIds)
            );
        }

        return $profiles->get();
    }
}
