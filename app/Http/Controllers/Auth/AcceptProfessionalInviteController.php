<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ProfessionalInvite;
use App\Services\ProfessionalOnboardingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Aceite do convite profissional — mesmo padrão POST puro de
 * AcceptInviteController (gerenciador de senha precisa funcionar).
 * Depois de criar a conta, cai em /painel: Dashboard::mount() já sabe
 * mandar Consultor pra /carteira e Corretor pra /carteira/seguros quando
 * não há perfil ativo nenhum.
 */
class AcceptProfessionalInviteController extends Controller
{
    public function show(string $token): View
    {
        return view('auth.accept-professional-invite', [
            'invite' => ProfessionalInvite::findValid($token),
            'token' => $token,
        ]);
    }

    public function store(Request $request, string $token, ProfessionalOnboardingService $onboarding): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], attributes: [
            'password' => 'senha',
        ]);

        $invite = ProfessionalInvite::findValid($token);

        if ($invite === null) {
            throw ValidationException::withMessages([
                'password' => 'Este convite expirou ou já foi utilizado. Peça um novo ao administrador.',
            ]);
        }

        $user = $onboarding->acceptInvite($invite, $data['password']);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
