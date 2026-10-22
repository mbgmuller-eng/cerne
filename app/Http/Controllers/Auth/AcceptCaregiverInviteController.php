<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetProfileContext;
use App\Models\ProfileCaregiver;
use App\Models\User;
use App\Services\CaregiverService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

/**
 * Aceite do convite para acompanhar uma pessoa. POST puro, sem Livewire (mesmo motivo do login: gerenciador de senha).
 *
 * Três situações, conforme o e-mail convidado: (1) ainda não tem conta, cria a senha e nasce já dentro da pessoa, sem perfil nem assinatura
 * próprios; (2) tem conta e já está logado com ela, só confirma; (3) tem conta mas não está logado, entra primeiro e volta para este link.
 * O link sozinho nunca dá acesso: a conta tem que ser a do e-mail convidado.
 */
class AcceptCaregiverInviteController extends Controller
{
    public function show(Request $request, string $token): View|RedirectResponse
    {
        $convite = ProfileCaregiver::findValidByToken($token)?->load(['invitedBy', 'profile', 'member']);

        if ($convite === null) {
            return view('auth.accept-caregiver-invite', ['convite' => null, 'token' => $token, 'modo' => 'invalido']);
        }

        $contaExistente = User::query()->whereRaw('lower(email) = ?', [mb_strtolower($convite->invited_email)])->exists();
        $logado = $request->user();

        if (! $contaExistente) {
            $modo = 'criar';
        } elseif ($logado !== null && mb_strtolower($logado->email) === mb_strtolower($convite->invited_email)) {
            $modo = 'confirmar';
        } elseif ($logado !== null) {
            $modo = 'outra_conta';
        } else {
            // Volta para este link depois do login.
            $request->session()->put('url.intended', $request->fullUrl());
            $modo = 'entrar';
        }

        return view('auth.accept-caregiver-invite', ['convite' => $convite, 'token' => $token, 'modo' => $modo]);
    }

    public function store(Request $request, string $token, CaregiverService $service): RedirectResponse
    {
        $convite = ProfileCaregiver::findValidByToken($token)?->load('profile');

        if ($convite === null) {
            throw ValidationException::withMessages(['password' => 'Este convite expirou ou já foi utilizado. Peça um novo a quem te convidou.']);
        }

        $logado = $request->user();

        // Conta existente: precisa estar logada com o e-mail convidado.
        if (User::query()->whereRaw('lower(email) = ?', [mb_strtolower($convite->invited_email)])->exists()) {
            if ($logado === null) {
                return redirect()->route('login')->with('status', 'Entre com a sua conta para aceitar o convite.');
            }

            try {
                $service->accept($convite, $logado);
            } catch (RuntimeException $e) {
                throw ValidationException::withMessages(['password' => $e->getMessage()]);
            }

            $request->session()->put(SetProfileContext::SESSION_KEY, $convite->profile_id);

            return redirect()->route('care.index');
        }

        $dados = $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'terms' => ['accepted'],
        ], ['terms.accepted' => 'Para continuar, aceite os Termos de Uso e a Política de Privacidade.'], ['password' => 'senha']);

        $usuario = $service->acceptWithNewAccount($convite, $dados['password']);

        Auth::login($usuario);
        $request->session()->regenerate();

        return redirect()->route('care.index');
    }
}
