<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Registro do resumo de leads já enviado a um profissional num dia — só existe para sustentar o índice único
 * (ver LeadAlertService).
 */
#[Fillable(['consultant_id', 'sent_on', 'quotes_expiring', 'stale_leads', 'overdue_actions'])]
class LeadAlertDigest extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['sent_on' => 'date'];
    }
}
