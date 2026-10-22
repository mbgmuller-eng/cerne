<?php

namespace App\Livewire;

use App\Services\DashboardService;
use App\Support\ProfileContext;
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

            if ($usuario !== null && ($usuario->careProfiles()->exists() || $usuario->followedProfiles()->exists())) {
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
            'dados' => $dashboard->overview(),
        ]);
    }
}
