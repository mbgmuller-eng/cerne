<?php

namespace App\Http\Controllers\Auth;

use App\Enums\SubscriptionBundle;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\SelfRegistrationService;
use App\Support\ProfessionalPricing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Primeira etapa da compra: a pessoa vê o resumo do pedido e cria a conta na
 * mesma página. É o cadastro de SelfRegistrationController com o plano e a
 * forma de pagamento já escolhidos, guardados na sessão ("checkout") pra
 * SubscriptionIndex mostrar o resumo de novo depois que o e-mail for
 * confirmado, onde ela informa o CPF e confirma.
 *
 * POST HTML puro, sem Livewire, pelo mesmo motivo do cadastro: tela que cria
 * senha precisa funcionar com gerenciador de senha.
 */
class CheckoutController extends Controller
{
    public function show(SubscriptionBundle $pacote): View
    {
        return view('checkout', [
            'tipo' => 'usuario',
            'pacote' => $pacote,
            'precoMensal' => (string) config('billing.prices.'.$pacote->value),
            'tetos' => [],
            'tetoInicial' => null,
            'papel' => null,
            'primeiraCobranca' => now()->addDays(7),
        ]);
    }

    public function showProfessional(Request $request): View
    {
        $tetoPedido = (int) $request->query('clientes', 0);
        $papel = in_array($request->query('papel'), ['consultant', 'broker'], true) ? $request->query('papel') : 'consultant';

        return view('checkout', [
            'tipo' => 'profissional',
            'pacote' => null,
            'precoMensal' => null,
            'tetos' => collect(ProfessionalPricing::validCaps())
                ->mapWithKeys(fn (int $teto) => [$teto => ProfessionalPricing::priceFor($teto)])
                ->all(),
            'tetoInicial' => ProfessionalPricing::isValidCap($tetoPedido) ? $tetoPedido : ProfessionalPricing::validCaps()[0],
            'papel' => $papel,
            'primeiraCobranca' => now()->addDays(7),
        ]);
    }

    public function store(Request $request, SelfRegistrationService $registration): RedirectResponse
    {
        $profissional = $request->input('tipo') === 'profissional';

        $data = $request->validate([
            'tipo' => ['required', Rule::in(['usuario', 'profissional'])],
            'pacote' => [Rule::requiredIf(! $profissional), Rule::enum(SubscriptionBundle::class)],
            'papel' => [Rule::requiredIf($profissional), Rule::in(['consultant', 'broker'])],
            'clientes' => [Rule::requiredIf($profissional), 'integer', Rule::in(ProfessionalPricing::validCaps())],
            'nome' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'nascimento' => ['required', 'date', 'before:today', 'after:1900-01-01'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'termos' => ['accepted'],
        ], attributes: [
            'nome' => 'nome',
            'email' => 'e-mail',
            'nascimento' => 'data de nascimento',
            'password' => 'senha',
            'termos' => 'termos de uso',
            'clientes' => 'quantidade de clientes',
        ]);

        $user = $profissional
            ? $registration->registerProfessional($data['nome'], $data['email'], $data['password'], UserRole::from($data['papel']), $data['nascimento'])
            : $registration->registerClient($data['nome'], $data['email'], $data['password'], $data['nascimento']);

        $user->sendEmailVerificationNotification();

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('checkout', $profissional
            ? ['tipo' => 'profissional', 'clientes' => (int) $data['clientes']]
            : ['tipo' => 'usuario', 'pacote' => $data['pacote']]);

        return redirect()->route('subscription.index');
    }
}
