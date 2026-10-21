<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Campo sensível (CPF/CNPJ, dado de saúde) cifrado no banco com a APP_KEY. Grava sempre cifrado e LÊ OS DOIS: o que ainda está em texto
 * puro (antes de `cerne:encrypt-sensitive` rodar) volta como está, então a troca não derruba nenhuma tela no meio do caminho.
 *
 * Consequência que vale lembrar ao mexer nestes campos: não dá para filtrar nem ordenar por eles em SQL (o texto cifrado muda a cada
 * gravação). Ordene em PHP. E perder a APP_KEY é perder estes dados: ela precisa estar no cofre junto com os backups.
 */
class Sensitive implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return $value === null ? null : '';
        }

        try {
            return Crypt::decryptString((string) $value);
        } catch (DecryptException) {
            return (string) $value;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return $value === null ? null : '';
        }

        return Crypt::encryptString((string) $value);
    }

    /** O texto já está cifrado? (para a rotina que cifra o que sobrou em texto puro) */
    public static function isEncrypted(?string $valor): bool
    {
        if ($valor === null || $valor === '') {
            return true;
        }

        try {
            Crypt::decryptString($valor);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
}
