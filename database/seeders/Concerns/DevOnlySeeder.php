<?php

namespace Database\Seeders\Concerns;

use RuntimeException;

/**
 * Seeders de dado fake (dev/demo/teste) nunca podem rodar em produção — até
 * aqui isso só vivia como comentário no docblock, e comentário não impede
 * ninguém de digitar o --class errado num terminal SSH. O guard derruba o
 * processo antes de escrever qualquer linha na tabela.
 */
trait DevOnlySeeder
{
    private function abortInProduction(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException(
                static::class.' cria dado de teste/demonstração e nunca deve rodar em produção.'
            );
        }
    }
}
