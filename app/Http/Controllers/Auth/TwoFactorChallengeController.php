<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Segunda etapa do login para quem tem verificação em dois passos. POST HTML puro, como o login.
 *
 * A senha já foi conferida (LoginController), mas a sessão AINDA não existe: só o id da pessoa fica guardado na sessão
 * (dez minutos), e a conta só é aberta aqui, com o código do aplicativo ou um código de recuperação. Tentativas erradas
 * têm teto por pessoa E por IP: um código de 6 dígitos não aguenta tentativa ilimitada.
 */
class TwoFactorChallengeController extends Controller
{
    public const SESSION_KEY = 'login.two_factor';

    public function show(Request $request): View|RedirectResponse
    {
        if ($this->pendente($request) === null) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    public function store(Request $request, TwoFactorService $dois): RedirectResponse
    {
        $pendente = $this->pendente($request);

        if ($pendente === null) {
            return redirect()->route('login')->withErrors(['email' => 'A verificação expirou. Entre de novo.']);
        }

        $request->validate(['code' => ['required', 'string', 'max:32']], attributes: ['code' => 'código']);

        $chaves = ['two-factor:'.$pendente['id'], 'two-factor-ip:'.$request->ip()];

        foreach ($chaves as $chave) {
            if (RateLimiter::tooManyAttempts($chave, 5)) {
                throw ValidationException::withMessages(['code' => 'Muitas tentativas. Tente novamente em '.RateLimiter::availableIn($chave).' segundos.']);
            }
        }

        $usuario = User::query()->find($pendente['id']);
        $codigo = (string) $request->input('code');

        $confere = $usuario !== null
            && $usuario->is_active
            && $usuario->hasTwoFactor()
            && ($dois->verify($usuario, $codigo) || $dois->useRecoveryCode($usuario, $codigo));

        if (! $confere) {
            foreach ($chaves as $chave) {
                RateLimiter::hit($chave, 300);
            }

            throw ValidationException::withMessages(['code' => 'O código não confere.']);
        }

        foreach ($chaves as $chave) {
            RateLimiter::clear($chave);
        }

        $request->session()->forget(self::SESSION_KEY);
        Auth::login($usuario, (bool) $pendente['remember']);
        $request->session()->regenerate();

        $usuario->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(route('dashboard'));
    }

    /** @return array{id: string, remember: bool, expires_at: int}|null */
    private function pendente(Request $request): ?array
    {
        $pendente = $request->session()->get(self::SESSION_KEY);

        if (! is_array($pendente) || ($pendente['expires_at'] ?? 0) < now()->timestamp) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        return $pendente;
    }
}
