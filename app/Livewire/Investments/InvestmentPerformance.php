<?php

namespace App\Livewire\Investments;

use App\Models\ProfileMember;
use App\Services\InvestmentPerformanceService;
use App\Support\ProfileContext;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Gráficos de desempenho dos investimentos: patrimônio, rentabilidade contra o CDI, ganho, composição e proventos.
 * Leitura apenas; os dados vêm da carteira mês a mês da corretora e das movimentações (ver InvestmentPerformanceService).
 */
#[Layout('components.layouts.app')]
class InvestmentPerformance extends Component
{
    /** Janela em meses: 12, 24 ou 0 para todo o histórico. */
    #[Url]
    public string $periodo = '12';

    #[Url]
    public string $pessoa = 'todos';

    public function mount(): void
    {
        // Mesmo bloqueio das demais telas financeiras: corretor só entra em Seguros.
        abort_if(auth()->user()?->isBroker(), 403);

        if (! in_array($this->periodo, ['12', '24', '0'], true)) {
            $this->periodo = '12';
        }

        if ($this->pessoa !== 'todos' && ! $this->membros()->contains('id', $this->pessoa)) {
            $this->pessoa = 'todos';
        }
    }

    /** @return Collection<int, ProfileMember> */
    private function membros(): Collection
    {
        return ProfileMember::query()
            ->where('profile_id', app(ProfileContext::class)->profileId())
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function render()
    {
        $periodo = in_array($this->periodo, ['12', '24', '0'], true) ? (int) $this->periodo : 12;
        $membro = $this->pessoa !== 'todos' && $this->membros()->contains('id', $this->pessoa) ? $this->pessoa : null;

        return view('livewire.investments.investment-performance', [
            'dados' => app(InvestmentPerformanceService::class)->forPeriod($membro, $periodo),
            'membros' => $this->membros(),
            'meses' => $periodo,
        ]);
    }
}
