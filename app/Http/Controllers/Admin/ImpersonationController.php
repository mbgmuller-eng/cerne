<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ImpersonationLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * "Entrar como" — só o admin da plataforma vê a conta de alguém sem
 * consultor vinculado, e a única forma de conferir uma tela (não só o
 * banco de dados) é logar como a pessoa. Reversível: a sessão guarda
 * quem começou a personificação, e stop() sempre volta pra ele — nunca
 * fica preso na conta de outra pessoa. Ver AdminUsers::entrarComo() para
 * o início; o banner de retorno fica em components.layouts.app.
 */
class ImpersonationController extends Controller
{
    /** Máximo que uma personificação dura sem ser renovada: depois disso a sessão volta sozinha para o administrador. */
    public const MAX_MINUTES = 60;

    public function stop(): RedirectResponse
    {
        static::end();

        return redirect()->route('admin.users');
    }

    /** Encerra a personificação em curso: fecha o registro do acesso e devolve a sessão ao administrador, com sessão nova. */
    public static function end(): void
    {
        $adminId = session('impersonator_id');
        $registroId = session('impersonation_log_id');
        session()->forget(['impersonator_id', 'impersonation_log_id', 'impersonation_expires_at']);

        // Fecha o registro do acesso (se a sessão acabar sem passar por aqui, ele fica aberto e a tela de acessos mostra "em andamento").
        if ($registroId !== null) {
            ImpersonationLog::query()->whereKey($registroId)->whereNull('ended_at')->update(['ended_at' => now()]);
        }

        if ($adminId !== null && ($admin = User::find($adminId)) !== null) {
            Auth::login($admin);
            session()->regenerate();
        }
    }
}
