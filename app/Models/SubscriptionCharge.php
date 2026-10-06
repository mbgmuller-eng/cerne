<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cobrança mensal do Pix Automático (modo MANUAL) já criada na Asaas — existe
 * pra sustentar o índice único por ciclo e guardar o estado das retentativas
 * (ver a migration e PixAutomaticBillingService).
 */
#[Fillable([
    'subscription_id', 'year', 'month', 'due_date', 'value', 'asaas_payment_id', 'asaas_instruction_id',
    'claimed_at', 'retry_attempts', 'retry_due_date', 'retry_requested_at', 'paid_at',
])]
class SubscriptionCharge extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'value' => 'decimal:2',
            'claimed_at' => 'datetime',
            'retry_attempts' => 'integer',
            'retry_due_date' => 'date',
            'retry_requested_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
