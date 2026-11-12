<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

/**
 * "Esqueci minha senha". POST HTML puro, sem Livewire, pelo mesmo motivo do login: tela que lida com senha precisa funcionar
 * com gerenciador de senha.
 *
 * Regras que não se quebram:
 *  - A resposta ao pedido é SEMPRE a mesma, exista a conta ou não (e esteja ela ativa ou não): a tela não vira consulta de
 *    quem tem conta no Cerne.
 *  - Redefinir derruba TODAS as sessões abertas da conta (quem roubou a sessão perde o acesso) e o "manter conectado".
 *  - Quem recebe o link no próprio e-mail prova que é dono dele: a conta que um profissional criou com senha aleatória
 *    (`password_pending`) passa a ser da pessoa, e o e-mail conta como verificado.
 */
class PasswordResetController extends Controller
{
    public function showRequest(): View
    {
        return view('auth.forgot-password');
    }

    public function sendLink(Request $request): RedirectResponse
    {
        $dados = $request->validate(['email' => ['required', 'email', 'max:255']], attributes: ['email' => 'e-mail']);

        // Conta desativada não recebe link. O broker já responde "não achei" para e-mail sem conta; as duas saídas viram a mesma mensagem.
        $usuario = User::query()->where('email', $dados['email'])->first();

        if ($usuario !== null && $usuario->is_active) {
            Password::sendResetLink(['email' => $dados['email']]);
        }

        return back()->with('status', 'Se este e-mail tiver uma conta no Cerne, enviamos um link para escolher uma nova senha. Ele vale por 60 minutos.');
    }

    public function showReset(string $token): View
    {
        return view('auth.reset-password', ['token' => $token]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ], attributes: ['email' => 'e-mail', 'password' => 'senha']);

        $resultado = Password::reset(
            ['email' => $dados['email'], 'password' => $dados['password'], 'password_confirmation' => $dados['password'], 'token' => $dados['token']],
            function (User $usuario, string $senha): void {
                abort_unless($usuario->is_active, 403);

                $usuario->forceFill([
                    'password' => $senha,
                    'remember_token' => Str::random(60),
                    'password_pending' => false,
                    'email_verified_at' => $usuario->email_verified_at ?? now(),
                ])->save();

                // Sessões ficam no banco: apagar as linhas é o que encerra o acesso de quem já estava dentro.
                DB::table(config('session.table', 'sessions'))->where('user_id', $usuario->id)->delete();

                event(new PasswordReset($usuario));
            },
        );

        if ($resultado !== Password::PASSWORD_RESET) {
            // Token vencido, e-mail trocado ou link já usado: a mesma mensagem para tudo.
            return back()->withInput($request->only('email'))->withErrors(['email' => 'Este link não vale mais. Peça um novo em "Esqueci minha senha".']);
        }

        return redirect()->route('login')->with('status', 'Senha alterada. Entre com a nova senha.');
    }
}
