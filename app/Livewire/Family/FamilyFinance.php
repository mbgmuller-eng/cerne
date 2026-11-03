<?php

namespace App\Livewire\Family;

use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\Debt;
use App\Models\ExpenseRecord;
use App\Models\FixedBillPayment;
use App\Models\Goal;
use App\Models\IncomeRecord;
use App\Models\InsurancePolicy;
use App\Models\InvestmentRecord;
use App\Services\DashboardService;
use App\Support\Money;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Finanças da família, SÓ LEITURA: o que um familiar que acompanha as finanças de alguém (um filho dos pais, por exemplo) enxerga.
 *
 * É uma tela própria, sem nenhum botão de gravar, e não as telas de finanças com os botões escondidos: o que o familiar vê sai dos mesmos models, que
 * já passam pela privacidade do casal (o que alguém marcou como privado nunca chega aqui) e pela lista de permitidos de quem acompanha. Não carrega
 * número de conta, agência, final de cartão nem observações.
 */
#[Layout('components.layouts.app')]
class FamilyFinance extends Component
{
    public const ABAS = [
        'resumo' => 'Resumo',
        'vencimentos' => 'Contas a vencer',
        'lancamentos' => 'Lançamentos',
        'contas' => 'Contas e cartões',
        'investimentos' => 'Investimentos',
        'dividas' => 'Dívidas',
        'seguros' => 'Seguros',
        'metas' => 'Metas',
    ];

    #[Url]
    public string $aba = 'resumo';

    /** Mês dos lançamentos, "AAAA-MM". */
    #[Url]
    public string $mes = '';

    public function mount(): void
    {
        $contexto = app(ProfileContext::class);

        abort_unless($contexto->isCaregiver() && $contexto->hasFinanceAccess() && ! $contexto->profile()?->isCare(), 403);

        $this->aba = array_key_exists($this->aba, self::ABAS) ? $this->aba : 'resumo';
        $this->mes = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->mes) ? $this->mes : now()->format('Y-m');
    }

    public function abrir(string $aba): void
    {
        if (array_key_exists($aba, self::ABAS)) {
            $this->aba = $aba;
        }
    }

    public function mesAnterior(): void
    {
        $this->mes = CarbonImmutable::createFromFormat('Y-m-d', $this->mes.'-01')->subMonth()->format('Y-m');
    }

    public function mesSeguinte(): void
    {
        $proximo = CarbonImmutable::createFromFormat('Y-m-d', $this->mes.'-01')->addMonth();

        // O futuro ainda não aconteceu: não há o que ver depois do mês atual.
        if ($proximo->lte(CarbonImmutable::now()->startOfMonth())) {
            $this->mes = $proximo->format('Y-m');
        }
    }

    public function render(DashboardService $painel)
    {
        $contexto = app(ProfileContext::class);

        return view('livewire.family.family-finance', [
            'perfil' => $contexto->profile(),
            'seguindo' => $contexto->followedNames(),
            'abas' => self::ABAS,
            'dados' => match ($this->aba) {
                'resumo' => ['painel' => $painel->overview()],
                'vencimentos' => $this->vencimentos(),
                'lancamentos' => $this->lancamentos(),
                'contas' => $this->contas(),
                'investimentos' => $this->investimentos(),
                'dividas' => $this->dividas(),
                'seguros' => $this->seguros(),
                'metas' => $this->metas(),
            },
        ]);
    }

    /** @return array<string, mixed> */
    private function vencimentos(): array
    {
        $hoje = CarbonImmutable::now()->startOfDay();

        $contas = FixedBillPayment::query()->outstanding()->whereDate('due_date', '<=', $hoje->addDays(45)->toDateString())
            ->with('fixedBill')->orderBy('due_date')->get();

        $faturas = CreditCardInvoice::query()->outstanding()->whereDate('due_date', '<=', $hoje->addDays(45)->toDateString())
            ->with('creditCard')->orderBy('due_date')->get();

        $itens = $contas->map(fn (FixedBillPayment $p) => [
            'nome' => $p->fixedBill->name, 'valor' => $p->effectiveAmount(), 'data' => $p->due_date, 'tipo' => 'Conta fixa',
        ])->concat($faturas->map(fn (CreditCardInvoice $f) => [
            'nome' => 'Fatura '.$f->creditCard->card_name, 'valor' => (string) $f->total_amount, 'data' => $f->due_date, 'tipo' => 'Fatura',
        ]))->sortBy('data')->values();

        return [
            'atrasados' => $itens->filter(fn (array $i) => $i['data']->lt($hoje))->values(),
            'proximos' => $itens->filter(fn (array $i) => $i['data']->gte($hoje))->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function lancamentos(): array
    {
        [$ano, $mes] = array_map('intval', explode('-', $this->mes));

        $despesas = ExpenseRecord::query()->forPeriod($ano, $mes)->with('category')->orderByDesc('expense_date')->orderByDesc('created_at')->limit(150)->get();
        $receitas = IncomeRecord::query()->forPeriod($ano, $mes)->with('category')->orderByDesc('received_date')->orderByDesc('created_at')->limit(150)->get();

        return [
            'despesas' => $despesas,
            'receitas' => $receitas,
            'totalDespesas' => Money::parse(ExpenseRecord::query()->forPeriod($ano, $mes)->sum('amount')),
            'totalReceitas' => Money::parse(IncomeRecord::query()->forPeriod($ano, $mes)->sum('amount')),
            'rotuloMes' => CarbonImmutable::create($ano, $mes, 1)->translatedFormat('F \d\e Y'),
            'ehMesAtual' => $this->mes === now()->format('Y-m'),
        ];
    }

    /** @return array<string, mixed> */
    private function contas(): array
    {
        $contas = BankAccount::query()->where('is_active', true)->orderBy('bank_name')->get();
        $cartoes = CreditCard::query()->where('is_active', true)->orderBy('card_name')->get();
        $faturasEmAberto = CreditCardInvoice::query()->outstanding()->selectRaw('credit_card_id, SUM(total_amount) as total')->groupBy('credit_card_id')->pluck('total', 'credit_card_id');

        return [
            'contas' => $contas,
            'cartoes' => $cartoes,
            'faturas' => $faturasEmAberto,
            'saldoTotal' => Money::parse(BankAccount::query()->where('is_active', true)->where('included_in_consolidated', true)->sum('current_balance')),
        ];
    }

    /** @return array<string, mixed> */
    private function investimentos(): array
    {
        $ativos = InvestmentRecord::query()->active()->orderByDesc('current_amount')->get();

        return [
            'grupos' => $ativos->groupBy(fn (InvestmentRecord $i) => $i->sector->label()),
            'total' => Money::sum($ativos->pluck('current_amount')),
        ];
    }

    /** @return array<string, mixed> */
    private function dividas(): array
    {
        return ['dividas' => Debt::query()->orderBy('name')->get()];
    }

    /** @return array<string, mixed> */
    private function seguros(): array
    {
        return ['apolices' => InsurancePolicy::query()->active()->orderBy('expiry_date')->get()];
    }

    /** @return array<string, mixed> */
    private function metas(): array
    {
        return ['metas' => Goal::query()->active()->byPriority()->with('linkedInvestment', 'linkedDebt')->get()];
    }
}
