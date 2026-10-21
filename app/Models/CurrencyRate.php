<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Cotação diária de uma moeda em reais (USDT, BTC, ETH). Dado público de mercado, igual para todo mundo: não pertence a perfil nenhum.
 * Quem escreve é a rotina `cotacoes-moedas` (CurrencyRateService); quem lê é o preço do dia dos investimentos nessas moedas.
 */
#[Fillable(['currency', 'rate_brl', 'rate_date', 'source'])]
class CurrencyRate extends Model
{
    protected function casts(): array
    {
        return [
            'rate_brl' => 'decimal:8',
            'rate_date' => 'date',
        ];
    }
}
