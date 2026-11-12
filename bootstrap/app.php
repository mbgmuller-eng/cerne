<?php

use App\Http\Middleware\RequiresActiveSubscription;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetProfileContext;
use App\Services\ErrorTracking\ErrorRecorder;
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
                // Trocar ou redefinir a senha derruba as outras sessões e o "manter conectado": a sessão guarda o hash da senha
                // com que foi aberta e deixa de valer quando ele muda.
                \Illuminate\Session\Middleware\AuthenticateSession::class,
                SetProfileContext::class,
                SubstituteBindings::class,
            ],
        );

        // Só responde pelo domínio do APP_URL (e subdomínios): um Host forjado não chega nem a gerar link. Fora de produção o
        // Laravel não aplica (desenvolvimento e testes usam localhost).
        $middleware->trustHosts();

        $middleware->alias([
            'assinatura' => RequiresActiveSubscription::class,
            'termos' => \App\Http\Middleware\RequiresCurrentTerms::class,
            'dois-passos' => \App\Http\Middleware\RequiresTwoFactor::class,
            'consentimento-saude' => \App\Http\Middleware\RequiresHealthConsent::class,
            'cuidado' => \App\Http\Middleware\RestrictCareContext::class,
        ]);

        // Em toda resposta, inclusive as de erro. A hospedagem compartilhada
        // não dá acesso à configuração do Nginx, então os cabeçalhos saem
        // daqui — ver SecurityHeaders.
        $middleware->append(SecurityHeaders::class);

        // Webhook da Asaas não carrega token CSRF (não é um formulário do
        // Cerne) — a autenticação dele é outra (header asaas-access-token,
        // conferido no controller), não o CSRF do Laravel.
        $middleware->validateCsrfTokens(except: ['webhooks/asaas', 'csp-report']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Todo erro que o Laravel reporta (tela, ação do Livewire, fila, rotina agendada) é anotado e entra no relatório diário. Sem
        // `stop()`: o log em arquivo continua como sempre. O registro nunca lança (ver ErrorRecorder).
        $exceptions->reportable(function (Throwable $e): void {
            app(ErrorRecorder::class)->record($e);
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
