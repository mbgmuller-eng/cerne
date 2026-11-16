<?php

namespace App\Services;

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie as CookieFactory;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * "Confiar neste navegador": dispensa o CÓDIGO do segundo passo (nunca a senha) num navegador que a pessoa escolheu, por um prazo curto.
 *
 * - O navegador guarda um token aleatório num cookie (cifrado pelo Laravel, `HttpOnly`, `SameSite=Lax`, `Secure` em HTTPS); o banco guarda só o hash.
 * - Prazo ABSOLUTO a partir de quando a pessoa confiou: 30 dias, ou 14 para quem vê dado de outras pessoas (administrador, consultor, corretor). Usar não
 *   estende o prazo.
 * - O token TROCA a cada uso (`renew`): um cookie copiado e usado depois do dono invalida o do dono no uso seguinte, e o contrário também.
 * - Cada pessoa tem o cookie com nome próprio (vários logins no mesmo navegador não se atropelam) e no máximo 10 navegadores confiáveis.
 * - Cai tudo (`revokeAll`) quando a senha muda ou é redefinida e quando o segundo passo é refeito ou desligado.
 */
class TrustedDeviceService
{
    public const MAX_PER_USER = 10;

    public function days(User $usuario): int
    {
        return $usuario->mustUseTwoFactor()
            ? (int) config('cerne.security.trusted_device_days_professional', 14)
            : (int) config('cerne.security.trusted_device_days', 30);
    }

    public function cookieName(User $usuario): string
    {
        return 'cerne_td_'.substr(hash('sha256', (string) $usuario->id), 0, 16);
    }

    /** Registra o navegador como confiável e devolve o cookie que precisa ir na resposta. */
    public function issue(User $usuario, Request $request): Cookie
    {
        TrustedDevice::query()->where('user_id', $usuario->id)->where('expires_at', '<=', now())->delete();

        // Passou do limite: os mais antigos saem (a pessoa não acumula navegador esquecido).
        $excedentes = TrustedDevice::query()->where('user_id', $usuario->id)->orderByDesc('created_at')->skip(self::MAX_PER_USER - 1)->take(PHP_INT_MAX)->pluck('id');
        TrustedDevice::query()->whereIn('id', $excedentes)->delete();

        $token = Str::random(64);
        $dispositivo = TrustedDevice::create([
            'user_id' => $usuario->id,
            'token_hash' => hash('sha256', $token),
            'label' => $this->labelFor($request->userAgent()),
            'expires_at' => now()->addDays($this->days($usuario)),
            'last_used_at' => now(),
        ]);

        return $this->cookie($usuario, $token, $dispositivo, $request);
    }

    /** O navegador confiável desta pessoa, se o cookie vale (token certo, não vencido). */
    public function recognize(User $usuario, Request $request): ?TrustedDevice
    {
        $token = $request->cookie($this->cookieName($usuario));

        if (! is_string($token) || strlen($token) !== 64) {
            return null;
        }

        return TrustedDevice::query()->where('user_id', $usuario->id)->where('token_hash', hash('sha256', $token))->where('expires_at', '>', now())->first();
    }

    /** Usou: troca o token (mesmo prazo) e devolve o cookie novo. */
    public function renew(TrustedDevice $dispositivo, User $usuario, Request $request): Cookie
    {
        $token = Str::random(64);
        $dispositivo->forceFill(['token_hash' => hash('sha256', $token), 'last_used_at' => now()])->save();

        return $this->cookie($usuario, $token, $dispositivo, $request);
    }

    public function revoke(User $usuario, string $id): void
    {
        TrustedDevice::query()->where('user_id', $usuario->id)->whereKey($id)->delete();
    }

    public function revokeAll(User $usuario): void
    {
        TrustedDevice::query()->where('user_id', $usuario->id)->delete();
    }

    /** O id do navegador de quem pede, para a lista dizer "este navegador". */
    public function currentId(User $usuario, Request $request): ?string
    {
        return $this->recognize($usuario, $request)?->id;
    }

    /** Cookie que apaga o da pessoa (ao revogar o navegador atual). */
    public function forgetCookie(User $usuario): Cookie
    {
        return CookieFactory::forget($this->cookieName($usuario));
    }

    /** "Chrome no Windows": só o que ajuda a pessoa a reconhecer o aparelho na lista, sem guardar o user agent inteiro. */
    public function labelFor(?string $userAgent): string
    {
        $ua = (string) $userAgent;

        $navegador = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Navegador',
        };

        $sistema = match (true) {
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iPhone ou iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'Mac',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        return $sistema !== null ? "{$navegador} no {$sistema}" : $navegador;
    }

    private function cookie(User $usuario, string $token, TrustedDevice $dispositivo, Request $request): Cookie
    {
        $minutos = max(1, (int) ceil(now()->diffInSeconds($dispositivo->expires_at, false) / 60));

        return CookieFactory::make(
            $this->cookieName($usuario), $token, $minutos, '/', null,
            config('session.secure') ?? $request->isSecure(), true, false, 'lax',
        );
    }
}
