<?php

namespace App\Support;

use App\Enums\LeadQuoteStatus;
use App\Models\LeadQuote;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Monta a comparação lado a lado das cotações de um lead. Compara só o que é comparável: cotações do mesmo tipo de
 * seguro (vida com vida, carro com carro), e só as que ainda estão na mesa (nem recusadas, nem vencidas sem decisão).
 *
 * A "melhor" de cada linha é marcada para guiar o olhar, não para decidir: menor prêmio, maior capital, maior limite
 * por proteção. Cobertura que a seguradora não traz aparece como ausente, que é o que pesa na hora de escolher.
 */
class LeadQuoteComparison
{
    /**
     * @param  Collection<int, LeadQuote>  $quotes
     * @return list<array{
     *     type: string,
     *     quotes: list<LeadQuote>,
     *     best: array{premium: ?string, capital: ?string, cost: ?string} o melhor valor de cada linha (ver isBest),
     *     costPerThousand: array<string, ?string>,
     *     coverages: list<array{name: string, cells: array<string, ?array{value: ?string, note: ?string}>, best: ?string}>
     * }> um grupo por tipo de seguro com ao menos duas cotações
     */
    public static function build(Collection $quotes, ?CarbonImmutable $hoje = null): array
    {
        return $quotes
            ->filter(fn (LeadQuote $q) => $q->status === LeadQuoteStatus::Accepted || $q->isActive($hoje))
            ->groupBy(fn (LeadQuote $q) => $q->insurance_type->value)
            ->filter(fn (Collection $grupo) => $grupo->count() >= 2)
            ->map(fn (Collection $grupo, string $tipo) => self::group($tipo, $grupo))
            ->values()
            ->all();
    }

    /** @param  Collection<int, LeadQuote>  $grupo */
    private static function group(string $tipo, Collection $grupo): array
    {
        // Mais barata primeiro; sem prêmio informado vai para o fim.
        $ordenadas = $grupo
            ->sortBy(fn (LeadQuote $q) => $q->annualPremium() === null ? PHP_INT_MAX : (float) $q->annualPremium())
            ->values();

        $custo = [];

        foreach ($ordenadas as $q) {
            $custo[$q->id] = self::costPerThousand($q);
        }

        return [
            'type' => $tipo,
            'quotes' => $ordenadas->all(),
            'best' => [
                'premium' => self::extreme($ordenadas->mapWithKeys(fn (LeadQuote $q) => [$q->id => $q->annualPremium()])->all(), false),
                'capital' => self::extreme($ordenadas->mapWithKeys(fn (LeadQuote $q) => [$q->id => $q->coverage_amount === null ? null : (string) $q->coverage_amount])->all(), true),
                'cost' => self::extreme($custo, false),
            ],
            'costPerThousand' => $custo,
            'coverages' => self::coverageRows($ordenadas),
        ];
    }

    /** Esta célula tem o melhor valor da linha? */
    public static function isBest(?string $valor, ?string $melhor): bool
    {
        return $valor !== null && $melhor !== null && bccomp($valor, $melhor, 2) === 0;
    }

    /** Prêmio anual para cada R$ 1.000 de capital: o preço de cada real de proteção, útil quando os capitais diferem. */
    private static function costPerThousand(LeadQuote $q): ?string
    {
        $anual = $q->annualPremium();

        if ($anual === null || $q->coverage_amount === null || bccomp((string) $q->coverage_amount, '0', 2) <= 0) {
            return null;
        }

        return bcdiv(bcmul($anual, '1000', 6), (string) $q->coverage_amount, 2);
    }

    /**
     * Uma linha por proteção, juntando nomes que só diferem por maiúscula ou acento. Segue a ordem em que cada nome
     * aparece pela primeira vez (a lista padrão de vida já vem ordenada por displayCoverages()).
     *
     * @param  Collection<int, LeadQuote>  $quotes
     */
    private static function coverageRows(Collection $quotes): array
    {
        $linhas = [];

        foreach ($quotes as $q) {
            foreach ($q->displayCoverages() as $c) {
                $chave = Str::lower(Str::ascii(trim($c['name'])));

                if ($chave === '') {
                    continue;
                }

                $linhas[$chave] ??= ['name' => $c['name'], 'cells' => []];
                $linhas[$chave]['cells'][$q->id] = ['value' => $c['value'] === null ? null : (string) $c['value'], 'note' => $c['note']];
            }
        }

        return array_values(array_map(function (array $linha) use ($quotes) {
            $celulas = [];

            foreach ($quotes as $q) {
                $celulas[$q->id] = $linha['cells'][$q->id] ?? null;
            }

            $valores = array_map(fn (?array $c) => $c['value'] ?? null, $celulas);

            return ['name' => $linha['name'], 'cells' => $celulas, 'best' => self::extreme($valores, true)];
        }, $linhas));
    }

    /**
     * O menor (ou maior) valor da linha, para a tela destacar quem o tem (empates incluídos). Nulo quando há menos de
     * dois valores comparáveis ou quando todos empatam: destacar um "melhor" que não é melhor que ninguém só confunde.
     *
     * @param  array<string, ?string>  $valores  id => valor
     */
    private static function extreme(array $valores, bool $maior): ?string
    {
        $comValor = array_filter($valores, fn ($v) => $v !== null);

        if (count($comValor) < 2 || count(array_unique($comValor)) === 1) {
            return null;
        }

        $melhor = null;

        foreach ($comValor as $valor) {
            $cmp = $melhor === null ? 0 : bccomp((string) $valor, (string) $melhor, 2);

            if ($melhor === null || ($maior ? $cmp > 0 : $cmp < 0)) {
                $melhor = $valor;
            }
        }

        return $melhor === null ? null : (string) $melhor;
    }
}
