<?php

namespace App\Services\Analytics;

use App\Enums\DebtInstallmentStatus;
use App\Models\Debt;
use App\Models\DebtInstallment;
use App\Support\Charts\ChartPalette;
use App\Support\Charts\SvgCharts;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Análises de uma dívida: quanto de cada parcela é juro e quanto é amortização. Os números saem do cronograma (parcelas já pagas e as que faltam),
 * somados em SQL, e sob os escopos do model: a dívida e as parcelas que o cônjuge ocultou não aparecem para quem não as enxerga.
 */
class DebtAnalytics
{
    /** Parcelas futuras desenhadas: um ano é o que cabe no gráfico sem virar um borrão. */
    private const PROXIMAS = 12;

    /**
     * As dívidas que têm cronograma com juros (financiamento e empréstimo). Consórcio e dívida em aberto sem parcela não têm o que decompor.
     *
     * @return Collection<int, Debt>
     */
    public function selecionaveis(): Collection
    {
        $comJuros = DebtInstallment::query()->where('interest_amount', '>', 0)->select('debt_id')->distinct();

        return Debt::query()->whereIn('id', $comJuros)->orderBy('name')->get();
    }

    /** A dívida que abre por padrão: a que ainda tem mais parcelas por pagar, onde o juro pesa mais. */
    public function padrao(Collection $dividas): ?Debt
    {
        if ($dividas->isEmpty()) {
            return null;
        }

        $abertas = DebtInstallment::query()->where('status', DebtInstallmentStatus::Open)->whereIn('debt_id', $dividas->pluck('id'))
            ->selectRaw('debt_id, COUNT(*) as qtd')->groupBy('debt_id')->orderByDesc('qtd')->first();

        return $dividas->firstWhere('id', $abertas?->debt_id) ?? $dividas->first();
    }

    /** @return array<string, mixed> */
    public function report(Debt $divida, ChartPalette $p): array
    {
        $parcelas = DebtInstallment::query()->where('debt_id', $divida->id);

        $pagas = (clone $parcelas)->where('status', DebtInstallmentStatus::Paid)
            ->selectRaw('SUM(amount) as total, SUM(interest_amount) as juros, SUM(principal_amount) as amortizacao, COUNT(*) as qtd')->first();
        // Parcela pulada (diluída) não é cobrada: fica fora da soma do que se paga até o fim.
        $contrato = (clone $parcelas)->whereIn('status', [DebtInstallmentStatus::Paid, DebtInstallmentStatus::Open])
            ->selectRaw('SUM(interest_amount) as juros, SUM(principal_amount) as amortizacao, COUNT(*) as qtd')->first();
        $proximas = (clone $parcelas)->where('status', DebtInstallmentStatus::Open)->orderBy('number')->limit(self::PROXIMAS)->get();

        $jurosPagos = Money::parse($pagas->juros ?? 0);
        $parteDeJuros = Money::percentageOf($jurosPagos, $pagas->total ?? 0);

        $kpis = [
            ['Já pago em juros', Money::format($jurosPagos), (bccomp((string) ($pagas->total ?? 0), '0', 2) > 0 ? number_format($parteDeJuros, 0, ',', '.').'% do que foi pago até aqui' : 'nenhuma parcela paga ainda')],
            ['Juros até o fim do contrato', Money::format($contrato->juros ?? 0), 'sobre '.Money::format($contrato->amortizacao ?? 0).' de amortização'],
        ];

        $secoes = [];
        if ($proximas->isNotEmpty()) {
            $rotulos = $proximas->map(fn (DebtInstallment $x) => (string) $x->number)->all();
            $juros = $proximas->map(fn (DebtInstallment $x) => (string) $x->interest_amount)->all();
            $amort = $proximas->map(fn (DebtInstallment $x) => (string) $x->principal_amount)->all();
            $taxas = $proximas->map(fn (DebtInstallment $x) => (string) $x->fees_amount)->all();
            $temTaxa = array_sum(array_map('floatval', $taxas)) > 0;

            $series = [['Juros', $juros, $p->serie(1)], ['Amortização', $amort, $p->serie(0)]];
            $legenda = [[$p->serie(1), 'Juros'], [$p->serie(0), 'Amortização']];
            if ($temTaxa) {
                $series[] = ['Taxas', $taxas, $p->gray];
                $legenda[] = [$p->gray, 'Taxas'];
            }

            $linhas = $proximas->map(fn (DebtInstallment $x) => [
                'Parcela '.$x->number.' · '.$x->due_date->format('d/m/Y'),
                Money::format($x->amount), Money::format($x->interest_amount), Money::format($x->principal_amount), Money::format($x->fees_amount),
            ])->all();

            $primeira = $proximas->first();
            $nota = null;
            if (bccomp((string) $primeira->amount, '0', 2) > 0) {
                $nota = 'Na próxima parcela, '.number_format(Money::percentageOf($primeira->interest_amount, $primeira->amount), 0, ',', '.').'% é juro e '
                    .number_format(Money::percentageOf($primeira->principal_amount, $primeira->amount), 0, ',', '.').'% amortiza a dívida.';
            }

            $secoes[] = [
                'id' => 'juros',
                'titulo' => 'Quanto da parcela é juro',
                'sub' => $divida->name.' · próximas '.$proximas->count().' parcelas'.($divida->rate ? ', taxa de '.rtrim(rtrim(number_format((float) $divida->rate, 2, ',', '.'), '0'), ',').'% ao ano' : '').'.',
                'svg' => SvgCharts::colunasEmpilhadas($rotulos, $series, $p, 640, 240, 'Colunas empilhadas de juros, amortização e taxas das próximas parcelas'),
                'legenda' => $legenda,
                'tabela' => ['colunas' => ['Parcela', 'Valor', 'Juros', 'Amortização', 'Taxas'], 'linhas' => $linhas],
                'nota' => $nota,
            ];
        }

        return [
            'titulo' => 'Análises de dívidas',
            'periodo' => $divida->name,
            'kpis' => $kpis,
            'secoes' => $secoes,
        ];
    }
}
