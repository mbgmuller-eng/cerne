<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Preferência de tema da conta — não do navegador. Guardada no usuário
 * (não em cookie/localStorage só) para que o consultor veja o mesmo tema
 * ao abrir o app no celular e no notebook.
 */
enum ThemePreference: string
{
    use HasOptions;

    case System = 'system';
    case Light = 'light';
    case Dark = 'dark';

    public function label(): string
    {
        return match ($this) {
            self::System => 'Padrão do sistema',
            self::Light => 'Claro',
            self::Dark => 'Escuro',
        };
    }
}
