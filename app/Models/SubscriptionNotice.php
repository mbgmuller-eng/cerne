<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Registro de aviso de cobrança já enviado — só existe pra sustentar o
 * índice único (ver SubscriptionReminderService).
 */
#[Fillable(['subscription_id', 'kind', 'reference_date', 'sent_at'])]
class SubscriptionNotice extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'reference_date' => 'date',
            'sent_at' => 'datetime',
        ];
    }
}
