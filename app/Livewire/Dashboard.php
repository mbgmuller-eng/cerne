<?php

namespace App\Livewire;

use App\Services\DashboardService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Url;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Tela 2 — Visão Geral.
 *
 * Todos os números vêm do DashboardService, que agrega em SQL e guarda em
 * cache por perfil e por quem pergunta.
 */
#[Layout('components.layouts.app')]
class Dashboard extends Component
{
    /** 'reais' (tudo convertido) ou 'moedas' (dividido em reais, USDT, BTC e ETH). Lembrado na sessão. */
    public string $exibicao = 'reais';

    /** Mês mostrado (na URL, para sobreviver ao recarregar e poder ser enviado). Vazio = mês de hoje. */
    #[Url]
    public ?int $ano = null;

    #[Url]
    public ?int $mes = null;

    public function previousMonth(): void
    {
        $alvo = $this->referencia()->subMonth();
        $this->ano = $alvo->year;
        $this->mes = $alvo->month;
    }

    public function nextMonth(): void
    {
        $alvo = $this->referencia()->addMonth();
        $this->ano = $alvo->year;
        $this->mes = $alvo->month;
    }

    public function currentMonth(): void
    {
        $this->ano = null;
        $this->mes = null;
    }

    private function referencia(): CarbonImmutable
    {
        $hoje = CarbonImmutable::now();
        $ano = $this->ano ?? $hoje->year;
        $mes = min(12, max(1, $this->mes ?? $hoje->month));

        return CarbonImmutable::create($ano, $mes, 1);
    }

    public function exibir(string $modo): void
    {
        $this->exibicao = $modo === 'moedas' ? 'moedas' : 'reais';
        session(['dashboard_exibicao' => $this->exibicao]);
    }

    /**
     * Consultor sem cliente aberto cai na Carteira, não numa tela vazia —
     * "escolher um cliente" agora só acontece pela aba Clientes. Um cliente
     * comum sem perfil ativo (caso raro, ex.: perfil desativado) continua
     * vendo o vazio abaixo, porque não há carteira alguma para ele.
     */
    public function mount(): void
    {
        $this->exibicao = session('dashboard_exibicao') === 'moedas' ? 'moedas' : 'reais';

        if (app(ProfileContext::class)->profile() === null) {
            // Quem só cuida de alguém (sem perfil próprio) começa na lista de pessoas.
            $usuario = auth()->user();

            if ($usuario !== null && $usuario->hasCareAccess()) {
                $this->redirect(route('care.index'));

                return;
            }

            if (auth()->user()?->isConsultant()) {
                $this->redirect(route('consultant.portfolio'));

                return;
            }

            // Corretor não tem "Visão geral" — é tela financeira. A casa
            // dele é Seguros da carteira.
            if (auth()->user()?->isBroker()) {
                $this->redirect(route('consultant.portfolio.insurance'));

                return;
            }
        }

        // Com perfil ativo, Visão geral mostra dado financeiro — corretor
        // fica de fora, mesma trava das outras telas fora de Seguros.
        abort_if(auth()->user()?->isBroker(), 403);
    }

    public function render(DashboardService $dashboard)
    {
        $context = app(ProfileContext::class);
        $profile = $context->profile();

        if ($profile === null) {
            return view('livewire.dashboard-empty');
        }

        return view('livewire.dashboard', [
            'profile' => $profile,
            'member' => $context->member(),
            'dados' => $dashboard->overview($this->referencia()->year, $this->referencia()->month),
            'referencia' => $this->referencia(),
            'ehMesAtual' => $this->referencia()->isSameMonth(CarbonImmutable::now()),
        ]);
    }
}
