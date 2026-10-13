<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Em que pé está uma cotação. "Vencida" não é uma situação gravada: vem da validade (ver LeadQuote::isExpired()). */
enum LeadQuoteStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Declined = 'declined';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Em elaboração',
            self::Sent => 'Enviada ao cliente',
            self::Accepted => 'Aceita',
            self::Declined => 'Recusada',
        };
    }

    /** Ainda pode virar venda: nem aceita nem recusada. */
    public function isOpen(): bool
    {
        return $this === self::Draft || $this === self::Sent;
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => '#64748b',
            self::Sent => '#0ea5e9',
            self::Accepted => '#16a34a',
            self::Declined => '#dc2626',
        };
    }
}
