<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum LeadStage: string
{
    use HasOptions;

    case NewContact = 'new_contact';
    case MeetingScheduled = 'meeting_scheduled';
    case GatheringData = 'gathering_data';
    case Quoting = 'quoting';
    case QuoteSent = 'quote_sent';
    case Negotiating = 'negotiating';
    case ProposalSent = 'proposal_sent';
    case Converted = 'converted';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::NewContact => 'Novo contato',
            self::MeetingScheduled => 'Reunião marcada',
            self::GatheringData => 'Coletando dados',
            self::Quoting => 'Cotando',
            self::QuoteSent => 'Cotação enviada',
            self::Negotiating => 'Negociação',
            self::ProposalSent => 'Proposta enviada',
            self::Converted => 'Convertido',
            self::Lost => 'Perdido',
        };
    }

    /** O nome da etapa no funil de seguros, quando difere do da consultoria (ex.: converter = apólice emitida). */
    public function labelFor(LeadKind $kind): string
    {
        return $kind === LeadKind::Insurance && $this === self::Converted ? 'Emitida' : $this->label();
    }

    /**
     * As etapas em andamento de cada funil, na ordem do quadro. Convertido e perdido ficam de fora (caso encerrado).
     *
     * @return list<self>
     */
    public static function openFor(LeadKind $kind): array
    {
        return match ($kind) {
            LeadKind::Consulting => [self::NewContact, self::MeetingScheduled, self::ProposalSent],
            LeadKind::Insurance => [self::NewContact, self::GatheringData, self::Quoting, self::QuoteSent, self::Negotiating, self::ProposalSent],
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
            self::GatheringData => '#6366f1',
            self::Quoting => '#8b5cf6',
            self::QuoteSent => '#0ea5e9',
            self::Negotiating => '#f59e0b',
            self::ProposalSent => '#d97706',
            self::Converted => '#16a34a',
            self::Lost => '#dc2626',
        };
    }
}
