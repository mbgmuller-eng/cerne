<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Coluna JSON (lista de lançamentos lida de um extrato, simulação de seguro) guardada CIFRADA, como texto. Substitui o cast `array` nos
 * campos que carregam dado financeiro linha a linha: a leitura por IA grava descrição, valor e data de cada movimento da conta.
 *
 * Mesma regra do `Sensitive`: grava sempre cifrado e LÊ OS DOIS (JSON em texto puro de antes da cifragem volta como está). A coluna passa a
 * TEXT; não dá para filtrar por dentro dela em SQL (nenhuma tela faz isso).
 */
class SensitiveArray implements CastsAttributes
{
    /** @return array<mixed>|null */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $texto = Crypt::decryptString((string) $value);
        } catch (DecryptException) {
            $texto = (string) $value;
        }

        $dados = json_decode($texto, true);

        return is_array($dados) ? $dados : null;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return Crypt::encryptString(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
