<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Quem ainda não aceitou a versão atual dos Termos de Uso e da Política de Privacidade passa por uma tela de aceite antes de usar o app.
 * Vale para todo mundo (cliente, consultor, corretor, administrador) e para todo caminho de entrada (cadastro, compra, convite): como a
 * checagem é no uso e não no cadastro, nenhuma porta nova esquece de pedir o aceite. Quando a versão muda (config cerne.legal), todos
 * aceitam de novo no próximo acesso.
 *
 * Fica de fora quem é um administrador "entrando como" outra conta: o aceite é da pessoa, não de quem a está atendendo.
 */
class RequiresCurrentTerms
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $request->session()->has('impersonator_id') || $user->hasAcceptedCurrentTerms()) {
            return $next($request);
        }

        if ($request->isMethod('GET')) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return redirect()->route('legal.accept');
    }
}
