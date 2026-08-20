<?php

namespace App\Models\Concerns;

use App\Services\DashboardService;
use Illuminate\Database\Eloquent\Model;

/**
 * Derruba o cache da Visão Geral quando o model muda.
 *
 * Sem isto o cliente lançaria uma despesa e o painel continuaria mostrando
 * o número antigo por até 15 minutos — o tipo de coisa que faz a pessoa
 * perder a confiança no sistema inteiro.
 */
trait InvalidatesDashboard
{
    public static function bootInvalidatesDashboard(): void
    {
        $invalidar = function (Model $model): void {
            DashboardService::forgetProfile($model->getAttribute('profile_id'));
        };

        static::created($invalidar);
        static::updated($invalidar);
        static::deleted($invalidar);
    }
}
