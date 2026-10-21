<?php

namespace App\Services;

use App\Models\CurrencyRate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Quanto vale 1 USDT, 1 BTC e 1 ETH em reais, uma vez por dia (rotina `cotacoes-moedas`, 03:00, antes da foto mensal das 03:30).
 *
 * Os investimentos nessas moedas guardam a quantidade (`native_amount`) e o valor em reais (`current_amount`) é quantidade vezes a
 * cotação do dia: é o que mantém toda soma em reais do app certa sem nenhum código saber de moeda. A atualização é uma única
 * UPDATE em SQL por moeda (nada de carregar os ativos) e derruba o cache do painel de quem foi afetado.
 *
 * Três fontes, na ordem: CoinGecko, Binance (par direto em reais) e AwesomeAPI. A AwesomeAPI não tem USDT: para ele usa o dólar
 * comercial, que anda colado; por isso é a última. Se todas falharem, ficam as cotações de ontem (o `cerne:check` avisa quando
 * passam de 3 dias).
 */
class CurrencyRateService
{
    /** @var list<string> */
    public const CURRENCIES = ['USDT', 'BTC', 'ETH'];

    /** Mais velha que isto, a cotação já não serve de resposta para "quanto vale hoje". */
    public const STALE_AFTER_DAYS = 3;

    /**
     * Busca, grava e reaplica. Idempotente: rodar de novo no mesmo dia só atualiza a cotação do dia (índice único moeda + data).
     *
     * @return array{source: string, rates: array<string, string>, repriced: int}|null nulo se nenhuma fonte respondeu
     */
    public function update(?CarbonImmutable $hoje = null): ?array
    {
        $hoje ??= CarbonImmutable::today();
        $resultado = $this->fetch();

        if ($resultado === null) {
            return null;
        }

        [$fonte, $cotacoes] = $resultado;

        CurrencyRate::upsert(
            array_map(fn (string $moeda) => [
                'currency' => $moeda, 'rate_brl' => $cotacoes[$moeda], 'rate_date' => $hoje->toDateString(), 'source' => $fonte,
                'created_at' => now(), 'updated_at' => now(),
            ], self::CURRENCIES),
            ['currency', 'rate_date'],
            ['rate_brl', 'source', 'updated_at'],
        );

        return ['source' => $fonte, 'rates' => $cotacoes, 'repriced' => $this->reprice()];
    }

    /** A cotação mais recente da moeda, em reais; nula se nunca houve (antes da primeira rodada). */
    public function latest(string $moeda): ?string
    {
        $valor = CurrencyRate::query()->where('currency', $moeda)->orderByDesc('rate_date')->value('rate_brl');

        return $valor === null ? null : (string) $valor;
    }

    /** Há cotação de todas as moedas e a mais antiga não passou de STALE_AFTER_DAYS? Para o `cerne:check`. */
    public function isFresh(?CarbonImmutable $hoje = null): bool
    {
        $hoje ??= CarbonImmutable::today();

        foreach (self::CURRENCIES as $moeda) {
            $data = CurrencyRate::query()->where('currency', $moeda)->max('rate_date');

            if ($data === null || CarbonImmutable::parse($data)->diffInDays($hoje) > self::STALE_AFTER_DAYS) {
                return false;
            }
        }

        return true;
    }

    /**
     * Refaz o valor em reais de todo investimento em moeda estrangeira com a última cotação.
     *
     * @return int quantos investimentos foram recalculados
     */
    public function reprice(): int
    {
        $total = 0;

        foreach (self::CURRENCIES as $moeda) {
            $cotacao = $this->latest($moeda);

            if ($cotacao === null) {
                continue;
            }

            $perfis = DB::table('investment_records')->where('currency', $moeda)->whereNotNull('native_amount')->distinct()->pluck('profile_id');

            $total += DB::update(
                // A base e o já resgatado também andam com a cotação: o desempenho em reais é o da quantidade, sem o sobe e desce do preço.
                'UPDATE investment_records SET current_amount = ROUND(native_amount * ?, 2),
                    invested_amount = CASE WHEN invested_native IS NULL THEN invested_amount ELSE ROUND(invested_native * ?, 2) END,
                    realized_amount = CASE WHEN realized_native IS NULL THEN realized_amount ELSE ROUND(realized_native * ?, 2) END,
                    updated_at = ? WHERE currency = ? AND native_amount IS NOT NULL',
                [$cotacao, $cotacao, $cotacao, now(), $moeda],
            );

            foreach ($perfis as $perfil) {
                DashboardService::forgetProfile($perfil);
            }
        }

        return $total;
    }

    /** O valor em reais de uma quantidade, com a cotação mais recente. Nulo sem cotação. */
    public function toBrl(string $quantidade, string $moeda): ?string
    {
        $cotacao = $this->latest($moeda);

        // Meio centavo a mais antes de cortar: o mesmo arredondamento do ROUND() da atualização diária em SQL, para o valor não mudar
        // de um centavo só por ter sido recalculado.
        return $cotacao === null ? null : bcadd(bcmul($quantidade, $cotacao, 10), '0.005', 2);
    }

    /**
     * @return array{0: string, 1: array<string, string>}|null
     */
    private function fetch(): ?array
    {
        foreach (['coingecko' => fn () => $this->fromCoinGecko(), 'binance' => fn () => $this->fromBinance(), 'awesomeapi' => fn () => $this->fromAwesomeApi()] as $nome => $busca) {
            try {
                $cotacoes = $busca();
            } catch (Throwable $e) {
                logger()->warning('Cotação de moedas: fonte falhou', ['fonte' => $nome, 'erro' => $e->getMessage()]);

                continue;
            }

            if ($this->valid($cotacoes)) {
                return [$nome, $cotacoes];
            }

            logger()->warning('Cotação de moedas: resposta incompleta', ['fonte' => $nome]);
        }

        return null;
    }

    /** @return array<string, string> */
    private function fromBinance(): array
    {
        $resposta = Http::timeout(15)->acceptJson()->get('https://api.binance.com/api/v3/ticker/price', ['symbols' => '["USDTBRL","BTCBRL","ETHBRL"]'])->throw()->json();
        $preco = collect($resposta)->mapWithKeys(fn ($linha) => [(string) ($linha['symbol'] ?? '') => (string) ($linha['price'] ?? '')]);

        return ['USDT' => $preco['USDTBRL'] ?? '', 'BTC' => $preco['BTCBRL'] ?? '', 'ETH' => $preco['ETHBRL'] ?? ''];
    }

    /** USDT não existe na AwesomeAPI: vale o dólar comercial (USD-BRL), que anda colado nele. */
    private function fromAwesomeApi(): array
    {
        $resposta = Http::timeout(15)->acceptJson()->get('https://economia.awesomeapi.com.br/json/last/USD-BRL,BTC-BRL,ETH-BRL')->throw()->json();

        return [
            'USDT' => (string) ($resposta['USDBRL']['bid'] ?? ''),
            'BTC' => (string) ($resposta['BTCBRL']['bid'] ?? ''),
            'ETH' => (string) ($resposta['ETHBRL']['bid'] ?? ''),
        ];
    }

    /** @return array<string, string> */
    private function fromCoinGecko(): array
    {
        $resposta = Http::timeout(15)->acceptJson()
            ->get('https://api.coingecko.com/api/v3/simple/price', ['ids' => 'tether,bitcoin,ethereum', 'vs_currencies' => 'brl'])->throw()->json();

        return [
            'USDT' => (string) ($resposta['tether']['brl'] ?? ''),
            'BTC' => (string) ($resposta['bitcoin']['brl'] ?? ''),
            'ETH' => (string) ($resposta['ethereum']['brl'] ?? ''),
        ];
    }

    /** @param  array<string, string>  $cotacoes */
    private function valid(array $cotacoes): bool
    {
        foreach (self::CURRENCIES as $moeda) {
            if (! isset($cotacoes[$moeda]) || ! is_numeric($cotacoes[$moeda]) || (float) $cotacoes[$moeda] <= 0) {
                return false;
            }
        }

        return true;
    }
}
