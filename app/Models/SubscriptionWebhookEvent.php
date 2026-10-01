<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Log de idempotência dos webhooks da Asaas — só existe pra sustentar o
 * índice único em `asaas_event_id` (ver SubscriptionWebhookService).
 */
#[Fillable(['asaas_event_id', 'event_type', 'processed_at'])]
class SubscriptionWebhookEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }
}
