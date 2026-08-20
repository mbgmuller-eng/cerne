<?php

namespace App\Enums\Concerns;

/**
 * Traduz um enum para as estruturas que a UI consome.
 *
 * Todo enum do domínio expõe label() em pt-BR; este trait deriva dele as
 * listas usadas em <select>, filtros e validação, para que o rótulo exista
 * num lugar só.
 */
trait HasOptions
{
    /** @return array<string, string> valor => rótulo */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Regra `in:` do validator, já com os valores válidos. */
    public static function rule(): string
    {
        return 'in:'.implode(',', self::values());
    }
}
