<?php

use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetProfileContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // A ORDEM aqui é o que faz o tenancy funcionar.
        //
        // O SetProfileContext precisa rodar depois da sessão e da
        // autenticação (das quais depende) e ANTES do SubstituteBindings,
        // que é quem resolve os models da URL. Se o binding acontecesse
        // primeiro, ele consultaria o banco sem perfil ativo e o escopo
        // global devolveria zero linhas — toda rota com {model} daria 404.
        //
        // Como o SubstituteBindings vem no grupo `web` por padrão, ele é
        // removido e recolocado logo depois do nosso middleware.
        $middleware->web(
            remove: [SubstituteBindings::class],
            append: [
                SetProfileContext::class,
                SubstituteBindings::class,
            ],
        );

        // Em toda resposta, inclusive as de erro. A hospedagem compartilhada
        // não dá acesso à configuração do Nginx, então os cabeçalhos saem
        // daqui — ver SecurityHeaders.
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
