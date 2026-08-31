<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PartnerInvite;
use App\Services\ClientOnboardingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Aceite do convite de cônjuge — mesmo raciocínio de AcceptInviteController
 * (POST puro, pra gerenciador de senha funcionar). Diferença: não cria
 * perfil novo, só acrescenta a pessoa a um perfil que já existe (ver
 * ClientOnboardingService::addPartner()).
 */
class AcceptPartnerInviteController extends Controller
{
    public function show(string $token): View
    {
        return view('auth.accept-partner-invite', [
            'invite' => PartnerInvite::findValid($token)?->load('invitedBy'),
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
        $invite = PartnerInvite::findValid($token)?->load('profile');

        if ($invite === null) {
            throw ValidationException::withMessages([
                'password' => 'Este convite expirou ou já foi utilizado. Peça um novo a quem te convidou.',
            ]);
        }

        $user = $onboarding->acceptPartnerInvite($invite, $data['password']);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
