<?php

namespace App\Models;

use App\Casts\Sensitive;
use App\Enums\ThemePreference;
use App\Enums\UserRole;
use App\Notifications\VerifyEmailAddress;
use Database\Factories\UserFactory;
use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use NotificationChannels\WebPush\HasPushSubscriptions;

#[Fillable([
    'name', 'email', 'password', 'role', 'phone', 'birthdate', 'cpf_cnpj', 'asaas_customer_id', 'avatar_url', 'is_active', 'is_platform_admin', 'theme',
    'agenda_layout', 'calendar_view', 'portfolio_prefs', 'notify_email_enabled', 'notify_push_enabled', 'gym_keep_awake', 'gym_vibrate', 'gym_sound', 'terms_accepted_at', 'terms_version', 'health_consent_at', 'health_consent_version', 'deletion_requested_at', 'deletion_due_at', 'renewal_lead_types', 'renewal_lead_days',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPushSubscriptions, HasUuids, MustVerifyEmailTrait, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'health_consent_at' => 'datetime',
            'deletion_requested_at' => 'datetime',
            'deletion_due_at' => 'datetime',
            'cpf_cnpj' => Sensitive::class,
            'birthdate' => 'date',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'is_platform_admin' => 'boolean',
            'theme' => ThemePreference::class,
            'agenda_layout' => \App\Enums\AgendaLayout::class,
            'calendar_view' => \App\Enums\CalendarView::class,
            'renewal_lead_types' => 'array',
            'portfolio_prefs' => 'array',
            'notify_email_enabled' => 'boolean',
            'notify_push_enabled' => 'boolean',
            'gym_keep_awake' => 'boolean',
            'gym_vibrate' => 'boolean',
            'gym_sound' => 'boolean',
        ];
    }

    /**
     * Sobrescreve a notification padrão do framework pela nossa (mesmo
     * link assinado por baixo, só o template com a cara do Cerne) — ver
     * App\Notifications\VerifyEmailAddress.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailAddress);
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

    public function isBroker(): bool
    {
        return $this->role === UserRole::Broker;
    }

    /**
     * Consultor financeiro OU corretor de seguros — qualquer profissional
     * vinculado a clientes, por oposição a um membro da família (dono ou
     * cônjuge). Existe porque várias regras (privacidade do casal, redirect
     * sem perfil ativo) precisam tratar os dois papéis do mesmo jeito: "é
     * alguém de fora olhando", sem se importar com QUAL módulo cada um pode
     * ver — isso quem decide é FinancialProfilePolicy.
     */
    public function isLinkedProfessional(): bool
    {
        return $this->isConsultant() || $this->isBroker();
    }

    public function isClient(): bool
    {
        return $this->role === UserRole::Client;
    }

    /**
     * Acesso ao painel /admin — independente de `role`. Um consultor
     * continua consultor (isConsultant() acima não muda); isto só soma a
     * tela de gestão de toda a plataforma por cima. Nasce sempre false;
     * vira true só via `php artisan cerne:tornar-admin`.
     */
    /** Aceitou a versão atual dos Termos e da Política (ver config cerne.legal)? */
    public function hasAcceptedCurrentTerms(): bool
    {
        return $this->terms_version === config('cerne.legal.terms_version') && $this->terms_accepted_at !== null;
    }

    public function acceptTerms(): void
    {
        $this->forceFill(['terms_accepted_at' => now(), 'terms_version' => config('cerne.legal.terms_version')])->save();
    }

    /** Deu o consentimento específico para dado de saúde (versão atual) e não o revogou? */
    public function hasCurrentHealthConsent(): bool
    {
        return $this->health_consent_at !== null && $this->health_consent_version === config('cerne.legal.health_consent_version');
    }

    public function grantHealthConsent(): void
    {
        $this->forceFill(['health_consent_at' => now(), 'health_consent_version' => config('cerne.legal.health_consent_version')])->save();
    }

    public function revokeHealthConsent(): void
    {
        $this->forceFill(['health_consent_at' => null, 'health_consent_version' => null])->save();
    }

    public function isPlatformAdmin(): bool
    {
        return (bool) $this->is_platform_admin;
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

    /** Dados fiscais de quem paga (nota fiscal). */
    public function billingDetail(): HasOne
    {
        return $this->hasOne(BillingDetail::class);
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

        if ($this->isLinkedProfessional()) {
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
