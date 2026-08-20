<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Login por POST HTML puro, deliberadamente sem Livewire.
 *
 * Um formulário de autenticação não ganha nada com reatividade e perde
 * bastante: gerenciadores de senha preenchem campos sem disparar eventos
 * de input, e o binding do Livewire não enxerga o valor preenchido — o
 * usuário clica em "Entrar" e nada acontece. Com POST nativo, o navegador
 * envia o que está no campo, venha de onde vier.
 */
class LoginController extends Controller
{
    public function show(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ], attributes: [
            'email' => 'e-mail',
            'password' => 'senha',
        ]);

        $this->ensureIsNotRateLimited($request);

        $entrou = Auth::attempt(
            [...$credentials, 'is_active' => true],
            $request->boolean('remember'),
        );

        if (! $entrou) {
            RateLimiter::hit($this->throttleKey($request));

            throw ValidationException::withMessages([
                // Mensagem genérica de propósito: distinguir "e-mail não
                // existe" de "senha errada" entregaria quais endereços
                // têm conta no sistema.
                'email' => 'Essas credenciais não conferem.',
            ]);
        }

        RateLimiter::clear($this->throttleKey($request));
        $request->session()->regenerate();

        Auth::user()->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /** Cinco tentativas por minuto para a dupla e-mail + IP. */
    protected function ensureIsNotRateLimited(Request $request): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($request), maxAttempts: 5)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey($request));

        throw ValidationException::withMessages([
            'email' => "Muitas tentativas. Tente novamente em {$seconds} segundos.",
        ]);
    }

    protected function throttleKey(Request $request): string
    {
        return Str::transliterate(Str::lower((string) $request->input('email')).'|'.$request->ip());
    }
}
