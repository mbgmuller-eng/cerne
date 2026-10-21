<?php

namespace App\Console\Commands;

use App\Services\CurrencyRateService;
use Illuminate\Console\Command;

/** Cotação de USDT, BTC e ETH em reais e novo valor dos investimentos nessas moedas. Roda todo dia às 03:00 (rotina `cotacoes-moedas`). */
class UpdateCurrencyRates extends Command
{
    protected $signature = 'cerne:update-currency-rates';

    protected $description = 'Busca a cotação de USDT, BTC e ETH em reais e atualiza o valor dos investimentos nessas moedas';

    public function handle(CurrencyRateService $cotacoes): int
    {
        $r = $cotacoes->update();

        if ($r === null) {
            $this->components->error('Nenhuma fonte de cotação respondeu. As cotações anteriores continuam valendo.');

            return self::FAILURE;
        }

        $this->components->info("Cotações de {$r['source']}: ".implode(', ', array_map(fn ($m, $v) => "$m R$ ".number_format((float) $v, 2, ',', '.'), array_keys($r['rates']), $r['rates'])));
        $this->line("  Investimentos recalculados: {$r['repriced']}");

        return self::SUCCESS;
    }
}
