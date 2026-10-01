<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

/**
 * EmailVerificationRequest (do framework) já confere que o id/hash da URL
 * assinada batem com quem está logado — 403 se não bater, sem precisar
 * reimplementar essa checagem aqui.
 */
class VerifyEmailController extends Controller
{
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->markEmailAsVerified();
        }

        return redirect()->route('subscription.index')->with('status', 'E-mail confirmado! Agora escolha seu pacote.');
    }
}
