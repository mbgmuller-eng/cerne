<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * `notifications.data` com os TEXTOS cifrados e os identificadores à vista.
 *
 * A notificação de consulta, de item de cuidado e de treino leva título, mensagem e nome da pessoa ("Consulta: oncologista, Maria") e ficava
 * em texto puro numa tabela que o backup copia. Cifrar a coluna inteira quebraria a deduplicação, que consulta por identificador
 * (`whereJsonContains('data->health_appointment_id', ...)`): por isso só os campos de TEXTO entram em `PROTECTED`, e `kind`, os `*_id`,
 * datas e contagens ficam como estão.
 *
 * Lê os dois jeitos (o que ainda está em texto puro volta como está), então a troca não derruba o sino de ninguém antes de
 * `cerne:encrypt-sensitive` cifrar o que sobrou. Perder a APP_KEY é perder estes textos.
 */
class SensitiveNotificationData implements CastsAttributes
{
    /** @var list<string> */
    public const PROTECTED = ['title', 'message', 'member_name', 'device_name'];

    /** @return array<string, mixed> */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        $dados = json_decode((string) $value, true);

        return is_array($dados) ? self::decryptFields($dados) : [];
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return json_encode(self::encryptFields(is_array($value) ? $value : []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    public static function encryptFields(array $dados): array
    {
        foreach (self::PROTECTED as $campo) {
            if (isset($dados[$campo]) && is_string($dados[$campo]) && $dados[$campo] !== '' && ! self::isEncrypted($dados[$campo])) {
                $dados[$campo] = Crypt::encryptString($dados[$campo]);
            }
        }

        return $dados;
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    public static function decryptFields(array $dados): array
    {
        foreach (self::PROTECTED as $campo) {
            if (isset($dados[$campo]) && is_string($dados[$campo]) && $dados[$campo] !== '') {
                try {
                    $dados[$campo] = Crypt::decryptString($dados[$campo]);
                } catch (DecryptException) {
                    // texto puro de antes da cifragem: volta como está
                }
            }
        }

        return $dados;
    }

    /** A linha crua (JSON em texto) ainda tem algum campo de texto sem cifrar? */
    public static function rowHasPlaintext(string $json): bool
    {
        $dados = json_decode($json, true);

        if (! is_array($dados)) {
            return false;
        }

        foreach (self::PROTECTED as $campo) {
            if (isset($dados[$campo]) && is_string($dados[$campo]) && $dados[$campo] !== '' && ! self::isEncrypted($dados[$campo])) {
                return true;
            }
        }

        return false;
    }

    private static function isEncrypted(string $texto): bool
    {
        try {
            Crypt::decryptString($texto);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
}
