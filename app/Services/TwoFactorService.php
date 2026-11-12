<?php

namespace App\Services;

use App\Models\User;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Support\Str;

/**
 * Verificação em dois passos por código do aplicativo autenticador (TOTP, RFC 6238: HMAC-SHA1, 6 dígitos, janela de 30 s).
 *
 * Implementação própria e curta de propósito: é um algoritmo fechado, e uma biblioteca a mais seria superfície de ataque.
 * Regras que não se quebram:
 *  - O segredo e os códigos de recuperação ficam cifrados no banco (cast `encrypted`) e nunca saem em serialização (`#[Hidden]`).
 *  - Um código aceito não vale de novo (`two_factor_last_step`): quem espiou o código não o reutiliza.
 *  - O segundo passo só passa a existir depois de a pessoa confirmar um código de verdade (`two_factor_confirmed_at`);
 *    configurar e abandonar no meio não tranca ninguém para fora.
 *  - Código de recuperação vale uma vez só.
 */
class TwoFactorService
{
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const PERIOD = 30;

    private const DIGITS = 6;

    /** Quantos passos para cada lado o relógio do celular pode estar errado. */
    private const WINDOW = 1;

    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    /** @return list<string> */
    public function generateRecoveryCodes(): array
    {
        return collect(range(1, 8))->map(fn () => Str::lower(Str::random(5)).'-'.Str::lower(Str::random(5)))->all();
    }

    public function otpauthUri(User $usuario, string $segredo): string
    {
        $emissor = 'Cerne';

        return 'otpauth://totp/'.rawurlencode($emissor.':'.$usuario->email).'?secret='.$segredo.'&issuer='.rawurlencode($emissor).'&algorithm=SHA1&digits='.self::DIGITS.'&period='.self::PERIOD;
    }

    /** QR do aplicativo autenticador, em SVG embutido na tela (o segredo nunca vira URL nem arquivo). */
    public function qrSvg(User $usuario, string $segredo): string
    {
        return (new Builder(writer: new SvgWriter, data: $this->otpauthUri($usuario, $segredo), size: 220, margin: 8))->build()->getString();
    }

    /** Código atual para um segredo (usado pelos testes e pela conferência da configuração). */
    public function codeAt(string $segredo, ?int $momento = null): string
    {
        return $this->hotp($segredo, intdiv($momento ?? time(), self::PERIOD));
    }

    /**
     * @return int|null o passo do código aceito, ou nulo se o código não confere (ou já foi usado)
     */
    public function matchStep(string $segredo, string $codigo, ?int $ultimoPasso = null, ?int $momento = null): ?int
    {
        $codigo = preg_replace('/\s+/', '', $codigo) ?? '';

        if (! preg_match('/^\d{'.self::DIGITS.'}$/', $codigo)) {
            return null;
        }

        $passoAtual = intdiv($momento ?? time(), self::PERIOD);

        for ($passo = $passoAtual - self::WINDOW; $passo <= $passoAtual + self::WINDOW; $passo++) {
            if (hash_equals($this->hotp($segredo, $passo), $codigo)) {
                // Mesmo passo (ou anterior) ao do último código aceito: é repetição.
                return $ultimoPasso !== null && $passo <= $ultimoPasso ? null : $passo;
            }
        }

        return null;
    }

    /** Confere o código do aplicativo e, se bateu, grava o passo usado. */
    public function verify(User $usuario, string $codigo): bool
    {
        if ($usuario->two_factor_secret === null) {
            return false;
        }

        $passo = $this->matchStep($usuario->two_factor_secret, $codigo, $usuario->two_factor_last_step);

        if ($passo === null) {
            return false;
        }

        $usuario->forceFill(['two_factor_last_step' => $passo])->save();

        return true;
    }

    /** Aceita e CONSOME um código de recuperação (vale uma vez). */
    public function useRecoveryCode(User $usuario, string $codigo): bool
    {
        $codigo = Str::lower(trim($codigo));
        $codigos = $usuario->two_factor_recovery_codes ?? [];

        foreach ($codigos as $i => $guardado) {
            if (hash_equals((string) $guardado, $codigo)) {
                unset($codigos[$i]);
                $usuario->forceFill(['two_factor_recovery_codes' => array_values($codigos)])->save();

                return true;
            }
        }

        return false;
    }

    /** Liga o segundo passo depois de a pessoa provar que o aplicativo está lendo o segredo certo. Devolve os códigos de recuperação (aparecem uma vez só). */
    public function enable(User $usuario, string $segredo, string $codigo): ?array
    {
        $passo = $this->matchStep($segredo, $codigo);

        if ($passo === null) {
            return null;
        }

        $codigos = $this->generateRecoveryCodes();

        $usuario->forceFill([
            'two_factor_secret' => $segredo,
            'two_factor_recovery_codes' => $codigos,
            'two_factor_confirmed_at' => now(),
            'two_factor_last_step' => $passo,
        ])->save();

        return $codigos;
    }

    public function disable(User $usuario): void
    {
        $usuario->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();
    }

    /** @return list<string> os novos códigos (os antigos deixam de valer) */
    public function regenerateRecoveryCodes(User $usuario): array
    {
        $codigos = $this->generateRecoveryCodes();
        $usuario->forceFill(['two_factor_recovery_codes' => $codigos])->save();

        return $codigos;
    }

    private function hotp(string $segredo, int $contador): string
    {
        $chave = $this->base32Decode($segredo);
        $hash = hash_hmac('sha1', pack('N*', 0, $contador), $chave, true);
        $offset = ord($hash[19]) & 0x0F;
        $binario = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($binario % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $dados): string
    {
        $bits = '';
        foreach (str_split($dados) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $saida = '';
        foreach (str_split($bits, 5) as $grupo) {
            $saida .= self::BASE32[bindec(str_pad($grupo, 5, '0', STR_PAD_RIGHT))];
        }

        return $saida;
    }

    private function base32Decode(string $texto): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($texto, '='))) as $caractere) {
            $posicao = strpos(self::BASE32, $caractere);
            if ($posicao === false) {
                continue;
            }
            $bits .= str_pad(decbin($posicao), 5, '0', STR_PAD_LEFT);
        }

        $saida = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $saida .= chr(bindec($byte));
            }
        }

        return $saida;
    }
}
