<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ConsultantInvite;
use App\Services\ClientOnboardingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Aceite do convite, também em POST puro — mesma razão do login: é a tela
 * onde o gerenciador de senha do usuário vai sugerir e preencher uma senha
 * nova, e o preenchimento automático precisa funcionar.
 */
class AcceptInviteController extends Controller
{
    public function show(string $token): View
    {
        return view('auth.accept-invite', [
            'invite' => ConsultantInvite::findValid($token),
            'token' => $token,
        ]);
    }

    public function store(Request $request, string $token, ClientOnboardingService $onboarding): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], attributes: [
            'password' => 'senha',
        ]);

        // Reconferido no envio: o convite pode ter expirado enquanto o
        // formulário ficou aberto na tela.
        $invite = ConsultantInvite::findValid($token);

        if ($invite === null) {
            throw ValidationException::withMessages([
                'password' => 'Este convite expirou ou já foi utilizado. Peça um novo ao seu consultor.',
            ]);
        }

        $user = $onboarding->acceptInvite($invite, $data['password']);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
