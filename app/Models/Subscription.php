<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Support\ProfessionalPricing;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\CarbonInterface;

/** Carência de PastDue antes de travar o acesso — ver isCurrent(). */
#[Fillable([
    'user_id', 'kind', 'bundle', 'client_cap', 'status', 'billing_type', 'current_period_ends_at',
    'asaas_subscription_id', 'asaas_pix_authorization_id', 'pix_authorization_status', 'started_at', 'cancelled_at',
])]
class Subscription extends Model
{
    public const PAST_DUE_GRACE_DAYS = 5;

    /**
     * Pix Automático: a Asaas aceita até 3 retentativas em datas diferentes
     * dentro de 7 dias do vencimento. Com 5 dias de carência o acesso seria
     * cortado antes de a última tentativa poder dar certo.
     */
    public const PIX_AUTOMATIC_GRACE_DAYS = 7;

    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'kind' => SubscriptionKind::class,
            'bundle' => SubscriptionBundle::class,
            'client_cap' => 'integer',
            'status' => SubscriptionStatus::class,
            'billing_type' => PaymentMethod::class,
            'current_period_ends_at' => 'date',
            'started_at' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(SubscriptionCharge::class);
    }

    /**
     * Teste grátis: SEM carência. O acesso termina junto com o último dia, e a
     * pessoa cai na tela de assinatura para pagar, sem dias extras de espera.
     * Nos ciclos pagos, a carência de atraso (5 dias, 7 no Pix Automático)
     * continua valendo para quem só esqueceu de pagar.
     */
    public function graceDays(): int
    {
        if ($this->status === SubscriptionStatus::Trialing) {
            return 0;
        }

        return $this->billing_type === PaymentMethod::PixAutomatic ? self::PIX_AUTOMATIC_GRACE_DAYS : self::PAST_DUE_GRACE_DAYS;
    }

    public function hasActivePixAuthorization(): bool
    {
        return $this->pix_authorization_status === 'ACTIVE';
    }

    /** Valor mensal desta assinatura ("29.90"), do teto de clientes (profissional) ou do pacote. */
    public function monthlyPrice(): string
    {
        return $this->client_cap !== null
            ? ProfessionalPricing::priceFor($this->client_cap)
            : number_format((float) config("billing.prices.{$this->bundle->value}"), 2, '.', '');
    }

    /**
     * Ativa: concede sempre. Em teste: até o fim dos 7 dias, sem carência. Em
     * atraso: dentro da carência, contada do vencimento. Cancelada: nunca.
     *
     * O teste também expira: a cobrança na Asaas só nasce perto do fim dele, e
     * no Pix Automático nada avisa o Cerne se a pessoa não autorizar o débito.
     * Antes, `Trialing` concedia acesso para sempre e dependia do webhook de
     * atraso para virar `PastDue`.
     */
    public function isCurrent(): bool
    {
        return match ($this->status) {
            SubscriptionStatus::Active => true,
            SubscriptionStatus::Trialing => $this->accessCutoffDate()?->isFuture() ?? true,
            SubscriptionStatus::PastDue => $this->accessCutoffDate()?->isFuture() ?? false,
            SubscriptionStatus::Cancelled => false,
        };
    }

    /** Último dia de acesso do teste grátis (o acesso trava na virada para `current_period_ends_at`). */
    public function trialLastDay(): ?CarbonInterface
    {
        return $this->current_period_ends_at?->copy()->subDay();
    }

    /** Dia em que o acesso de uma assinatura em atraso é cortado (carência contada do vencimento). */
    public function accessCutoffDate(): ?CarbonInterface
    {
        return $this->current_period_ends_at?->copy()->addDays($this->graceDays());
    }

    public function scopeOfKind(Builder $query, SubscriptionKind $kind): Builder
    {
        return $query->where('kind', $kind);
    }
}
