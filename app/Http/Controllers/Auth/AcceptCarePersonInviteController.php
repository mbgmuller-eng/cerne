<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetProfileContext;
use App\Models\CarePersonInvite;
use App\Models\User;
use App\Services\CarePersonService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

/**
 * Aceite do convite da PRÓPRIA pessoa acompanhada. POST puro, sem Livewire (gerenciador de senha). Mesmas três situações do convite de
 * cuidador: sem conta (cria a senha), com conta e logada (confirma) e com conta mas deslogada (entra e volta). O link sozinho nunca dá
 * acesso: a conta tem que ser a do e-mail convidado.
 */
class AcceptCarePersonInviteController extends Controller
{
    public function show(Request $request, string $token): View
    {
        $convite = CarePersonInvite::findValid($token)?->load(['invitedBy', 'member', 'profile']);

        if ($convite === null) {
            return view('auth.accept-care-person-invite', ['convite' => null, 'token' => $token, 'modo' => 'invalido']);
        }

        $contaExistente = User::query()->whereRaw('lower(email) = ?', [$convite->email])->exists();
        $logado = $request->user();

        if (! $contaExistente) {
            $modo = 'criar';
        } elseif ($logado !== null && mb_strtolower($logado->email) === $convite->email) {
            $modo = 'confirmar';
        } elseif ($logado !== null) {
            $modo = 'outra_conta';
        } else {
            $request->session()->put('url.intended', $request->fullUrl());
            $modo = 'entrar';
        }

        return view('auth.accept-care-person-invite', ['convite' => $convite, 'token' => $token, 'modo' => $modo]);
    }

    public function store(Request $request, string $token, CarePersonService $service): RedirectResponse
    {
        $convite = CarePersonInvite::findValid($token);

        if ($convite === null) {
            throw ValidationException::withMessages(['password' => 'Este convite expirou ou já foi utilizado. Peça um novo a quem te convidou.']);
        }

        $logado = $request->user();

        if (User::query()->whereRaw('lower(email) = ?', [$convite->email])->exists()) {
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
        $request->session()->put(SetProfileContext::SESSION_KEY, $convite->profile_id);

        return redirect()->route('care.index');
    }
}
