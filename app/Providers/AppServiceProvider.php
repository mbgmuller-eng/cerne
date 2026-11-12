<?php

namespace App\Providers;

use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Um contexto por requisição: é o que o escopo global de tenancy lê.
        $this->app->singleton(ProfileContext::class);
    }

    public function boot(): void
    {
        \Livewire\Livewire::componentHook(\App\Livewire\Hooks\BindToActiveProfile::class);

        // As regras de acesso das rotas valem também para as AÇÕES das telas (`/livewire/update`). Sem isto, só a abertura da página era
        // conferida: quem perdeu o acesso com a tela já aberta (consentimento de saúde revogado, termos novos, plano vencido, segundo passo
        // desligado, acesso de cuidador encerrado) seguia agindo até recarregar. O Livewire reaplica estas regras contra a rota ORIGINAL
        // da tela e, se uma delas redireciona, devolve o redirecionamento. Ver LivewirePersistentMiddlewareTest.
        \Livewire\Livewire::addPersistentMiddleware([
            \App\Http\Middleware\RequiresCurrentTerms::class,
            \App\Http\Middleware\RequiresTwoFactor::class,
            \App\Http\Middleware\RequiresActiveSubscription::class,
            \App\Http\Middleware\RequiresHealthConsent::class,
            \App\Http\Middleware\RestrictCareContext::class,
        ]);

        $this->configureRateLimiting();

        // Regra única de senha (cadastro, compra, convites, redefinição, troca). Em produção também recusa senha que já
        // apareceu em vazamento público (consulta por prefixo do hash, a senha nunca sai do servidor; sem rede, a regra passa).
        \Illuminate\Validation\Rules\Password::defaults(function () {
            $regra = \Illuminate\Validation\Rules\Password::min(8)->letters()->numbers();

            return $this->app->environment('production') ? $regra->uncompromised() : $regra;
        });

        // Todo link que o Cerne GERA (e-mail de convite, de redefinição, URL assinada) usa o endereço configurado, nunca o
        // cabeçalho Host da requisição: sem isto, um pedido com Host forjado faria o e-mail levar a pessoa a outro domínio.
        if ($this->app->environment('production') && filled(config('app.url'))) {
            \Illuminate\Support\Facades\URL::forceRootUrl(config('app.url'));
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        // Falha alto em desenvolvimento: acessar um atributo que não foi
        // carregado, ou atribuir em massa um campo não declarado, vira erro
        // em vez de virar bug silencioso em relatório financeiro.
        Model::shouldBeStrict($this->app->isLocal());

        // Datas imutáveis: a geração de parcelas avança um mês por iteração,
        // e um Carbon mutável alterado dentro do laço é um bug clássico.
        Date::use(CarbonImmutable::class);

        // Gasto novo que chega ao limite combinado com a família: avisa quem acompanha as finanças. Nunca derruba quem estava lançando.
        \App\Models\ExpenseRecord::created(function (\App\Models\ExpenseRecord $despesa): void {
            try {
                app(\App\Services\FamilyNotifier::class)->expenseAlert($despesa);
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }

    /**
     * Tetos por IP das telas públicas. Valem por IP (e não por conta) porque quem chega aqui ainda não tem conta; a
     * hospedagem compartilhada tem CPU contada, e cadastro/convite em massa é o jeito mais barato de gastá-la.
     */
    private function configureRateLimiting(): void
    {
        // Segundo limitador do login, por IP: o de e-mail+IP (LoginController) não barra quem testa muitos e-mails do mesmo IP.
        RateLimiter::for('login-ip', fn (Request $request) => Limit::perMinute(20)->by('login-ip:'.$request->ip()));

        // Criar conta (cadastro e compra): cada uma manda e-mail de verificação.
        RateLimiter::for('registration', fn (Request $request) => [
            Limit::perMinute(5)->by('reg-min:'.$request->ip()),
            Limit::perHour(20)->by('reg-hour:'.$request->ip()),
        ]);

        // Abrir e aceitar convite pelo token: impede adivinhar token por força bruta.
        RateLimiter::for('invite-token', fn (Request $request) => Limit::perMinute(30)->by('invite:'.$request->ip()));

        // Pedir redefinição de senha: por IP e por e-mail digitado (o e-mail de quem é alvo não vira caixa de spam).
        RateLimiter::for('password-reset', fn (Request $request) => [
            Limit::perMinute(5)->by('pwr-ip:'.$request->ip()),
            Limit::perHour(5)->by('pwr-mail:'.sha1(mb_strtolower((string) $request->input('email')))),
        ]);
    }
}
