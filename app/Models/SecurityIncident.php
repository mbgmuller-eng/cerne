<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Incidente de segurança com dado pessoal (vazamento, acesso indevido, perda). A LGPD (art. 48) e a regulamentação da ANPD exigem comunicar
 * a autoridade e os titulares quando o risco é relevante, em até 3 dias úteis do conhecimento, e manter o registro. Ver INCIDENTES.md.
 * Só o administrador lê. Não guarda dado do afetado: só o que foi atingido e quantas pessoas.
 */
#[Fillable([
    'title', 'description', 'occurred_at', 'detected_at', 'data_affected', 'people_affected', 'risk', 'measures', 'anpd_notified_at', 'people_notified_at',
    'status', 'registered_by',
])]
class SecurityIncident extends Model
{
    public const RISKS = ['unknown' => 'Ainda não avaliado', 'low' => 'Baixo', 'medium' => 'Médio', 'high' => 'Alto'];

    public const STATUSES = ['open' => 'Aberto', 'contained' => 'Contido', 'closed' => 'Encerrado'];

    /** Prazo de comunicação à ANPD, em dias úteis, contados do conhecimento do incidente. */
    public const NOTIFICATION_BUSINESS_DAYS = 3;

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'detected_at' => 'datetime',
            'anpd_notified_at' => 'datetime',
            'people_notified_at' => 'datetime',
        ];
    }

    /** Até quando comunicar a ANPD: 3 dias úteis depois de detectado. */
    public function notificationDeadline(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->detected_at)->addWeekdays(self::NOTIFICATION_BUSINESS_DAYS);
    }

    /** Risco relevante (médio ou alto, ou ainda não avaliado) e a ANPD ainda não foi comunicada. */
    public function needsAnpdNotice(): bool
    {
        return $this->anpd_notified_at === null && $this->risk !== 'low' && $this->status !== 'closed';
    }

    public function isOverdue(): bool
    {
        return $this->needsAnpdNotice() && now()->greaterThan($this->notificationDeadline()->endOfDay());
    }
}
