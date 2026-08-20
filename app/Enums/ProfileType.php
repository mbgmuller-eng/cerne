<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ProfileType: string
{
    use HasOptions;

    case Single = 'single';
    case Couple = 'couple';
    case Family = 'family';

    public function label(): string
    {
        return match ($this) {
            self::Single => 'Individual',
            self::Couple => 'Casal',
            self::Family => 'Família',
        };
    }

    /**
     * Perfis de casal exigem login próprio para cada membro — é o que
     * torna a privacidade granular (profile_access_settings) aplicável.
     */
    public function requiresMemberLogin(): bool
    {
        return $this === self::Couple;
    }
}
