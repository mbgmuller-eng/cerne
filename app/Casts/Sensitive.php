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
        } catch (DecryptException $e) {
            // Texto cifrado que a chave atual NÃO abre (APP_KEY trocada sem APP_PREVIOUS_KEYS, ou linha corrompida): nunca mostra o
            // texto cifrado na tela como se fosse o dado, e avisa o acompanhamento de erros. O que não é cifrado (texto puro de antes
            // da cifragem) volta como está.
            if (self::looksEncrypted((string) $value)) {
                report($e);

                return '[ilegível]';
            }

            return (string) $value;
        }
    }

    /** Parece um pacote cifrado do Laravel (base64 de um JSON com iv, value e mac)? */
    private static function looksEncrypted(string $valor): bool
    {
        $json = base64_decode($valor, true);

        return $json !== false && str_starts_with($json, '{"iv"');
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
            // Cifrado com outra chave: o comando NÃO pode cifrar de novo por cima (viraria lixo sem volta).
            return self::looksEncrypted($valor);
        }
    }
}
