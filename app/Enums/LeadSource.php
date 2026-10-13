<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** De onde o contato veio. Junto com a anotação de quem indicou, mostra quais origens mais fecham. */
enum LeadSource: string
{
    use HasOptions;

    case ClientReferral = 'client_referral';
    case PartnerReferral = 'partner_referral';
    case Event = 'event';
    case Social = 'social';
    case Website = 'website';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::ClientReferral => 'Indicação de cliente',
            self::PartnerReferral => 'Indicação de parceiro',
            self::Event => 'Evento',
            self::Social => 'Redes sociais',
            self::Website => 'Site',
            self::Other => 'Outra',
        };
    }
}
