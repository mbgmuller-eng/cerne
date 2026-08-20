<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Privacidade granular do casal (profile_access_settings).
 *
 * `own_only` significa que o membro secundário só enxerga registros do
 * próprio member_id ou registros da família (member_id nulo). O consultor
 * vinculado ignora esta configuração — ver ProfilePolicy.
 */
enum Visibility: string
{
    use HasOptions;

    case OwnOnly = 'own_only';
    case AllMembers = 'all_members';

    public function label(): string
    {
        return match ($this) {
            self::OwnOnly => 'Somente os meus',
            self::AllMembers => 'De todos os membros',
        };
    }
}
