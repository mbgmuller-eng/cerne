<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum LeadStage: string
{
    use HasOptions;

    case NewContact = 'new_contact';
    case MeetingScheduled = 'meeting_scheduled';
    case ProposalSent = 'proposal_sent';
    case Converted = 'converted';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::NewContact => 'Novo contato',
            self::MeetingScheduled => 'Reunião marcada',
            self::ProposalSent => 'Proposta enviada',
            self::Converted => 'Convertido',
            self::Lost => 'Perdido',
        };
    }

    /** Fora do pipeline ativo — não aparece mais na lista de "em andamento". */
    public function isClosed(): bool
    {
        return $this === self::Converted || $this === self::Lost;
    }

    public function color(): string
    {
        return match ($this) {
            self::NewContact => '#64748b',
            self::MeetingScheduled => '#0ea5e9',
            self::ProposalSent => '#d97706',
            self::Converted => '#16a34a',
            self::Lost => '#dc2626',
        };
    }
}
