<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
    public function stop(): RedirectResponse
    {
        $adminId = session('impersonator_id');
        session()->forget('impersonator_id');

        if ($adminId !== null && ($admin = User::find($adminId)) !== null) {
            Auth::login($admin);
        }

        return redirect()->route('admin.users');
    }
}
