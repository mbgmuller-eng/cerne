<?php

namespace App\Livewire\Profile;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;

/**
 * Troca de senha em Minha conta. Pede a senha atual (sessão aberta num computador emprestado não basta para trocar a senha) e,
 * ao trocar, encerra TODAS as outras sessões da conta e o "manter conectado": quem tinha a sessão roubada perde o acesso.
 *
 * Não roda durante o "Entrar como": a senha da conta personificada nunca é do administrador.
 */
class ChangePassword extends Component
{
    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    public function save(): void
    {
        abort_if(session()->has('impersonator_id'), 403);

        $usuario = auth()->user();

        $this->validate([
            'currentPassword' => ['required', 'string'],
            'newPassword' => ['required', 'same:newPasswordConfirmation', Password::defaults()],
            'newPasswordConfirmation' => ['required'],
        ], [
            'newPassword.same' => 'A confirmação não é igual à nova senha.',
        ], [
            'currentPassword' => 'senha atual',
            'newPassword' => 'nova senha',
            'newPasswordConfirmation' => 'confirmação da nova senha',
        ]);

        $chave = 'change-password:'.$usuario->id;

        if (RateLimiter::tooManyAttempts($chave, 5)) {
            $this->addError('currentPassword', 'Muitas tentativas. Tente novamente em '.ceil(RateLimiter::availableIn($chave) / 60).' minuto(s).');

            return;
        }

        if (! Hash::check($this->currentPassword, $usuario->password)) {
            RateLimiter::hit($chave, 900);
            $this->addError('currentPassword', 'A senha atual não confere.');

            return;
        }

        RateLimiter::clear($chave);

        $usuario->forceFill(['password' => $this->newPassword, 'remember_token' => Str::random(60)])->save();

        // Sessões ficam no banco: apagar as outras linhas encerra o acesso dos outros aparelhos; esta continua.
        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $usuario->id)
            ->where('id', '!=', session()->getId())
            ->delete();

        // Mantém ESTA sessão válida com o novo hash (o AuthenticateSession compara o hash da sessão com o da conta).
        session()->put('password_hash_'.auth()->getDefaultDriver(), $usuario->fresh()->getAuthPassword());

        $this->reset('currentPassword', 'newPassword', 'newPasswordConfirmation');
        session()->flash('status', 'Senha alterada. Os outros aparelhos foram desconectados.');
    }

    public function render()
    {
        return view('livewire.profile.change-password');
    }
}
