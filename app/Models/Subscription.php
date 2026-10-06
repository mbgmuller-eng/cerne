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

    public function graceDays(): int
    {
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
     * Ativa/em teste: concede sempre. Em atraso: concede só dentro da
     * carência, contada a partir da última cobrança confirmada. Cancelada:
     * nunca.
     */
    public function isCurrent(): bool
    {
        return match ($this->status) {
            SubscriptionStatus::Active, SubscriptionStatus::Trialing => true,
            SubscriptionStatus::PastDue => $this->accessCutoffDate()?->isFuture() ?? false,
            SubscriptionStatus::Cancelled => false,
        };
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
