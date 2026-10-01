<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\SelfRegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Cadastro sem convite — POST puro, mesma razão de LoginController e
 * AcceptInviteController: é tela que cria senha nova, autofill do
 * gerenciador de senha precisa funcionar.
 *
 * CPF/CNPJ não é pedido aqui — fica só em SubscriptionIndex, bem antes de
 * cobrar, sem duplicar campo nem validação (ver App\Rules\CpfCnpj, usado
 * nos dois lugares).
 */
class SelfRegistrationController extends Controller
{
    public function show(): View
    {
        return view('auth.register');
    }

    public function store(Request $request, SelfRegistrationService $registration): RedirectResponse
    {
        $data = $request->validate([
            'tipo_conta' => ['required', Rule::in(['cliente', 'profissional'])],
            'nome' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'papel' => ['required_if:tipo_conta,profissional', Rule::in(['consultant', 'broker'])],
            'nascimento' => ['required', 'date', 'before:today', 'after:1900-01-01'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'termos' => ['accepted'],
        ], attributes: [
            'nome' => 'nome',
            'email' => 'e-mail',
            'nascimento' => 'data de nascimento',
            'password' => 'senha',
            'termos' => 'termos de uso',
        ]);

        $user = $data['tipo_conta'] === 'profissional'
            ? $registration->registerProfessional($data['nome'], $data['email'], $data['password'], UserRole::from($data['papel']), $data['nascimento'])
            : $registration->registerClient($data['nome'], $data['email'], $data['password'], $data['nascimento']);

        $user->sendEmailVerificationNotification();

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('subscription.index');
    }
}
