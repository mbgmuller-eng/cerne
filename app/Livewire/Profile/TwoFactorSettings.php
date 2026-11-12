<?php

namespace App\Livewire\Profile;

use App\Services\TwoFactorService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Liga, desliga e renova os códigos do segundo passo do login. Aparece em Minha conta e, para quem a exigência alcança, na
 * tela de configuração que o `RequiresTwoFactor` impõe antes de qualquer outra.
 *
 * O segredo em preparação fica na SESSÃO (servidor), não numa propriedade do componente: propriedade pública vai e volta no
 * navegador a cada requisição. Os códigos de recuperação aparecem uma vez, na hora de gerar.
 */
class TwoFactorSettings extends Component
{
    private const SESSION_SECRET = 'two_factor_setup_secret';

    public string $code = '';

    public string $password = '';

    /** @var list<string> */
    #[Locked]
    public array $recoveryCodes = [];

    #[Locked]
    public bool $standalone = false;

    public function startSetup(TwoFactorService $dois): void
    {
        $this->guard();

        if (auth()->user()->hasTwoFactor()) {
            return;
        }

        session()->put(self::SESSION_SECRET, $dois->generateSecret());
        $this->reset('code', 'password');
    }

    public function cancelSetup(): void
    {
        session()->forget(self::SESSION_SECRET);
        $this->reset('code');
    }

    public function confirmSetup(TwoFactorService $dois): void
    {
        $this->guard();

        $segredo = session()->get(self::SESSION_SECRET);
        $usuario = auth()->user();

        if (! is_string($segredo) || $usuario->hasTwoFactor()) {
            return;
        }

        $this->validate(['code' => ['required', 'string', 'max:12']], [], ['code' => 'código']);

        if ($this->tooMany('two-factor-setup:'.$usuario->id)) {
            return;
        }

        $codigos = $dois->enable($usuario, $segredo, $this->code);

        if ($codigos === null) {
            RateLimiter::hit('two-factor-setup:'.$usuario->id, 300);
            $this->addError('code', 'O código não confere. Confira se o relógio do celular está certo e tente com o código novo.');

            return;
        }

        session()->forget(self::SESSION_SECRET);
        $this->recoveryCodes = $codigos;
        $this->reset('code');
    }

    public function disable(TwoFactorService $dois): void
    {
        $this->guard();

        $usuario = auth()->user();

        if (! $usuario->hasTwoFactor()) {
            return;
        }

        // Onde a exigência está ligada, quem vê dado de outras pessoas não desliga: o app voltaria a pedir a configuração.
        if ($usuario->mustUseTwoFactor() && config('cerne.security.two_factor_required')) {
            $this->addError('password', 'Para a sua função o segundo passo é obrigatório e não pode ser desligado.');

            return;
        }

        if (! $this->passwordOk()) {
            return;
        }

        $dois->disable($usuario);
        $this->reset('password', 'code', 'recoveryCodes');
    }

    public function regenerateCodes(TwoFactorService $dois): void
    {
        $this->guard();

        $usuario = auth()->user();

        if (! $usuario->hasTwoFactor() || ! $this->passwordOk()) {
            return;
        }

        $this->recoveryCodes = $dois->regenerateRecoveryCodes($usuario);
        $this->reset('password');
    }

    public function render()
    {
        $usuario = auth()->user();
        $segredo = session()->get(self::SESSION_SECRET);
        $dois = app(TwoFactorService::class);

        return view('livewire.profile.two-factor-settings', [
            'ativo' => $usuario->hasTwoFactor(),
            'segredo' => is_string($segredo) ? $segredo : null,
            'qr' => is_string($segredo) ? $dois->qrSvg($usuario, $segredo) : null,
            'exigido' => $usuario->mustUseTwoFactor() && config('cerne.security.two_factor_required'),
            'codigosRestantes' => count($usuario->two_factor_recovery_codes ?? []),
        ]);
    }

    /** Quem está vendo como outra pessoa nunca mexe no segundo passo dela. */
    private function guard(): void
    {
        abort_if(session()->has('impersonator_id'), 403);
    }

    private function passwordOk(): bool
    {
        $usuario = auth()->user();
        $chave = 'two-factor-password:'.$usuario->id;

        if ($this->tooMany($chave, 'password')) {
            return false;
        }

        if (! Hash::check($this->password, $usuario->password)) {
            RateLimiter::hit($chave, 900);
            $this->addError('password', 'A senha não confere.');

            return false;
        }

        RateLimiter::clear($chave);

        return true;
    }

    private function tooMany(string $chave, string $campo = 'code'): bool
    {
        if (! RateLimiter::tooManyAttempts($chave, 5)) {
            return false;
        }

        $this->addError($campo, 'Muitas tentativas. Tente novamente em '.ceil(RateLimiter::availableIn($chave) / 60).' minuto(s).');

        return true;
    }
}
