<?php

namespace App\Services;

use App\Enums\Necessity;
use App\Models\BankAccount;
use App\Models\CreditCardInvoice;
use App\Models\ExpenseRecord;
use App\Models\FixedBillPayment;
use App\Models\Goal;
use App\Models\IncomeRecord;
use App\Models\InsurancePolicy;
use App\Models\InvestmentRecord;
use App\Support\Money;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Agregações da Visão Geral (tela 2).
 *
 * Duas regras de construção, ambas por motivo de correção e não de estilo:
 *
 * 1. Somas em SQL, nunca em coleção. `->get()->sum()` traz milhares de
 *    linhas para a memória do PHP só para reduzi-las a um número — na
 *    hospedagem compartilhada isso estoura o memory_limit antes de ficar
 *    lento. Os campos year/month desnormalizados existem exatamente para
 *    que o WHERE use índice.
 *
 * 2. A chave de cache inclui QUEM está perguntando. Os escopos globais
 *    filtram por perfil e por privacidade do casal; um total calculado
 *    para a Ana não pode ser servido ao Bruno, que talvez não enxergue
 *    metade dos lançamentos que o compõem.
 */
class DashboardService
{
    public function __construct(private ProfileContext $context) {}

    /** Tudo que a tela precisa, numa chamada. */
    public function overview(?int $year = null, ?int $month = null): array
    {
        $hoje = CarbonImmutable::now();
        $year ??= $hoje->year;
        $month ??= $hoje->month;

        return $this->remember("overview:{$year}-{$month}", fn (): array => [
            'patrimonio' => $this->netWorth(),
            'mes' => $this->monthSummary($year, $month),
            'evolucao' => $this->evolution(),
            'alertas' => $this->upcomingBills(),
            'objetivos' => $this->goalsSummary(),
            'protecao' => $this->insuranceSummary(),
        ]);
    }

    // -----------------------------------------------------------------
    // Patrimônio
    // -----------------------------------------------------------------

    /**
     * Patrimônio líquido: investimentos + saldos em conta − faturas em
     * aberto. As faturas entram como dívida porque já foram gastas, ainda
     * que não pagas — ignorá-las mostraria o cliente mais rico do que é.
     */
    public function netWorth(): array
    {
        $investimentos = Money::parse(
            InvestmentRecord::query()->active()->sum('current_amount')
        );

        $contas = Money::parse(
            BankAccount::query()->active()->consolidated()->sum('current_balance')
        );

        $faturas = Money::parse(
            CreditCardInvoice::query()->outstanding()->sum('total_amount')
        );

        $bruto = bcadd($investimentos, $contas, 2);

        return [
            'investimentos' => $investimentos,
            'contas' => $contas,
            'faturas' => $faturas,
            'liquido' => bcsub($bruto, $faturas, 2),
        ];
    }

    // -----------------------------------------------------------------
    // Mês corrente
    // -----------------------------------------------------------------

    public function monthSummary(int $year, int $month): array
    {
        $receitas = Money::parse(
            IncomeRecord::query()->forPeriod($year, $month)->sum('amount')
        );

        $despesas = Money::parse(
            ExpenseRecord::query()->forPeriod($year, $month)->sum('amount')
        );

        // GROUP BY em SQL: uma query devolve os três totais.
        $porNecessidade = ExpenseRecord::query()
            ->forPeriod($year, $month)
            ->selectRaw('necessity, SUM(amount) as total')
            ->groupBy('necessity')
            ->pluck('total', 'necessity');

        $composicao = [];
        foreach (Necessity::cases() as $caso) {
            $composicao[$caso->value] = Money::parse($porNecessidade[$caso->value] ?? 0);
        }

        return [
            'receitas' => $receitas,
            'despesas' => $despesas,
            'sobra' => bcsub($receitas, $despesas, 2),
            'composicao' => $composicao,
            'taxa_poupanca' => Money::percentageOf(bcsub($receitas, $despesas, 2), $receitas),
        ];
    }

    // -----------------------------------------------------------------
    // Evolução
    // -----------------------------------------------------------------

    /**
     * Receitas e despesas dos últimos N meses.
     *
     * Duas queries agrupadas cobrem o período inteiro — não uma por mês.
     *
     * @return Collection<int, array{rotulo: string, receitas: string, despesas: string}>
     */
    public function evolution(?int $meses = null): Collection
    {
        $meses ??= config('cerne.dashboard.evolution_months');
        $inicio = CarbonImmutable::now()->startOfMonth()->subMonths($meses - 1);

        $receitas = $this->monthlyTotals(IncomeRecord::query(), $inicio);
        $despesas = $this->monthlyTotals(ExpenseRecord::query(), $inicio);

        return collect(range(0, $meses - 1))->map(function (int $i) use ($inicio, $receitas, $despesas): array {
            $mes = $inicio->addMonths($i);
            $chave = $mes->year.'-'.$mes->month;

            return [
                'rotulo' => $mes->translatedFormat('M/y'),
                'receitas' => Money::parse($receitas[$chave] ?? 0),
                'despesas' => Money::parse($despesas[$chave] ?? 0),
            ];
        });
    }

    /** @return \Illuminate\Support\Collection<string, string> */
    private function monthlyTotals($query, CarbonImmutable $desde): \Illuminate\Support\Collection
    {
        return $query
            ->selectRaw('year, month, SUM(amount) as total')
            ->where(function ($q) use ($desde): void {
                $q->where('year', '>', $desde->year)
                    ->orWhere(function ($q2) use ($desde): void {
                        $q2->where('year', $desde->year)->where('month', '>=', $desde->month);
                    });
            })
            ->groupBy('year', 'month')
            ->get()
            ->mapWithKeys(fn ($linha) => [$linha->year.'-'.$linha->month => $linha->total]);
    }

    // -----------------------------------------------------------------
    // Alertas
    // -----------------------------------------------------------------

    /** Contas fixas e faturas vencendo nos próximos dias. */
    public function upcomingBills(): array
    {
        $dias = config('cerne.dashboard.upcoming_bills_days');
        $limite = CarbonImmutable::now()->addDays($dias);

        $contas = FixedBillPayment::query()
            ->dueWithin($dias)
            ->with('fixedBill')
            ->orderBy('due_date')
            ->get()
            ->map(fn (FixedBillPayment $p) => [
                'nome' => $p->fixedBill->name,
                'valor' => $p->effectiveAmount(),
                'vencimento' => $p->due_date,
                'tipo' => 'conta',
            ]);

        $faturas = CreditCardInvoice::query()
            ->outstanding()
            ->whereDate('due_date', '>=', now()->toDateString())
            ->whereDate('due_date', '<=', $limite->toDateString())
            ->with('creditCard')
            ->orderBy('due_date')
            ->get()
            ->map(fn (CreditCardInvoice $f) => [
                'nome' => 'Fatura '.$f->creditCard->card_name,
                'valor' => $f->total_amount,
                'vencimento' => $f->due_date,
                'tipo' => 'fatura',
            ]);

        $todos = $contas->concat($faturas)->sortBy('vencimento')->values();

        return [
            'itens' => $todos,
            'total' => Money::sum($todos->pluck('valor')),
            'dias' => $dias,
        ];
    }

    // -----------------------------------------------------------------
    // Resumos
    // -----------------------------------------------------------------

    public function goalsSummary(): array
    {
        $objetivos = Goal::query()->active()->byPriority()->with('linkedInvestment')->get();

        return [
            'quantidade' => $objetivos->count(),
            'meta' => Money::sum($objetivos->pluck('estimated_value')),
            'acumulado' => Money::sum($objetivos->map(fn (Goal $g) => $g->accumulated())),
            'proximo' => $objetivos->first(),
        ];
    }

    public function insuranceSummary(): array
    {
        $apolices = InsurancePolicy::query()->active()->get();

        return [
            'quantidade' => $apolices->count(),
            'cobertura' => Money::sum($apolices->pluck('coverage_amount')),
            'mensal' => Money::sum($apolices->map(fn (InsurancePolicy $p) => $p->normalizedMonthlyCost())),
        ];
    }

    // -----------------------------------------------------------------
    // Cache
    // -----------------------------------------------------------------

    /**
     * Cache por perfil E por quem pergunta.
     *
     * Servir ao cônjuge um total calculado para o titular vazaria por
     * agregação o que a privacidade esconde no detalhe.
     */
    private function remember(string $chave, callable $callback): mixed
    {
        $profileId = $this->context->profileId();

        if ($profileId === null) {
            return $callback();
        }

        $quem = $this->context->isConsultant()
            ? 'consultor'
            : ($this->context->memberId() ?? 'dono');

        return Cache::remember(
            sprintf(
                'cerne:dash:%s:v%d:%s:%s',
                $profileId,
                self::version($profileId),
                $quem,
                $chave,
            ),
            now()->addMinutes(config('cerne.dashboard.cache_ttl_minutes')),
            $callback,
        );
    }

    /**
     * Versão do cache do perfil, embutida na chave.
     *
     * O driver de cache aqui é o `database` — a hospedagem compartilhada
     * não tem Redis, e sem Redis não há cache tags. Versionar a chave é o
     * que permite invalidar tudo de um perfil de uma vez: basta somar 1 e
     * as chaves antigas ficam órfãs, expirando sozinhas pelo TTL.
     */
    private static function version(string $profileId): int
    {
        return (int) Cache::get("cerne:dash:version:{$profileId}", 1);
    }

    /**
     * Invalida o dashboard do perfil.
     *
     * Chamado pelo observer a cada escrita em tabela financeira — ver
     * InvalidatesDashboard.
     */
    public static function forgetProfile(?string $profileId = null): void
    {
        $profileId ??= app(ProfileContext::class)->profileId();

        if ($profileId === null) {
            return;
        }

        $chave = "cerne:dash:version:{$profileId}";

        Cache::forever($chave, self::version($profileId) + 1);
    }
}
