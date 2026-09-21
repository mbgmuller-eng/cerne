<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum LeadActivityType: string
{
    use HasOptions;

    case Call = 'call';
    case Meeting = 'meeting';
    case Email = 'email';
    case Proposal = 'proposal';
    case Note = 'note';

    public function label(): string
    {
        return match ($this) {
            self::Call => 'Ligação',
            self::Meeting => 'Reunião',
            self::Email => 'E-mail',
            self::Proposal => 'Proposta',
            self::Note => 'Nota',
        };
    }
}
