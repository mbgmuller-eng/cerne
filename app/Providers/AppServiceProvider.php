<?php

namespace App\Providers;

use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
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
        // Falha alto em desenvolvimento: acessar um atributo que não foi
        // carregado, ou atribuir em massa um campo não declarado, vira erro
        // em vez de virar bug silencioso em relatório financeiro.
        Model::shouldBeStrict($this->app->isLocal());

        // Datas imutáveis: a geração de parcelas avança um mês por iteração,
        // e um Carbon mutável alterado dentro do laço é um bug clássico.
        Date::use(CarbonImmutable::class);
    }
}
