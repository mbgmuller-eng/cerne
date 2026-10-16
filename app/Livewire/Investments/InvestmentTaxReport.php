<?php

namespace App\Livewire\Investments;

use App\Models\InvestmentTransaction;
use App\Models\ProfileMember;
use App\Services\InvestmentTaxReportService;
use App\Support\Money;
use App\Support\ProfileContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Apoio ao imposto de renda dos investimentos: posição em 31/12, proventos do ano e vendas mês a mês, com exportação em
 * CSV e PDF. É conferência, não apuração oficial (ver InvestmentTaxReportService).
 */
#[Layout('components.layouts.app')]
class InvestmentTaxReport extends Component
{
    #[Url]
    public string $ano = '';

    /** Cada pessoa declara no seu CPF: o relatório é de um membro do perfil por vez. */
    #[Url]
    public string $pessoa = '';

    public function mount(): void
    {
        // Mesmo bloqueio das demais telas financeiras: corretor só entra em Seguros.
        abort_if(auth()->user()?->isBroker(), 403);

        $anos = $this->anos();

        // A declaração que se faz agora é a do ano anterior.
        if (! in_array((int) $this->ano, $anos, true)) {
            $anterior = (int) now()->subYear()->format('Y');
            $this->ano = (string) (in_array($anterior, $anos, true) ? $anterior : ($anos[0] ?? now()->year));
        }

        $membros = $this->membros();

        if ($this->pessoa !== 'todos' && ! $membros->contains('id', $this->pessoa)) {
            $meu = app(ProfileContext::class)->member();
            $this->pessoa = $membros->contains('id', $meu?->id) ? $meu->id : ($membros->first()->id ?? 'todos');
        }
    }

    /** @return list<int> do ano atual ao mais antigo com movimentação; o ano anterior (o da declaração de agora) sempre entra */
    private function anos(): array
    {
        $atual = (int) now()->format('Y');
        $primeiro = InvestmentTransaction::query()->min('operation_date');
        $de = $primeiro !== null ? (int) substr((string) $primeiro, 0, 4) : $atual - 1;

        return range($atual, min($de, $atual - 1));
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

    /** @return array<string, mixed> */
    private function relatorio(): array
    {
        return app(InvestmentTaxReportService::class)->forYear((int) $this->ano, $this->pessoa === 'todos' ? null : $this->pessoa);
    }

    private function nomeDaPessoa(): string
    {
        return $this->pessoa === 'todos' ? 'Todos os membros do perfil' : ($this->membros()->firstWhere('id', $this->pessoa)?->name ?? '');
    }

    public function baixarPdf()
    {
        $pdf = Pdf::loadView('pdf.investment-tax-report', [
            'relatorio' => $this->relatorio(),
            'pessoa' => $this->nomeDaPessoa(),
            'geradoEm' => now(),
        ])->setPaper('a4');

        return response()->streamDownload(fn () => print ($pdf->output()), 'investimentos-imposto-de-renda-'.$this->ano.'.pdf');
    }

    public function baixarCsv()
    {
        $r = $this->relatorio();
        $n = fn (string|int|float|null $v): string => number_format((float) $v, 2, ',', '');
        $q = fn (string $v): string => rtrim(rtrim($v, '0'), ',.') === '' ? '0' : str_replace('.', ',', rtrim(rtrim($v, '0'), '.'));

        $linhas = [
            ['Investimentos e imposto de renda', 'Ano-base '.$r['year'], $this->nomeDaPessoa()],
            [],
            ['BENS E DIREITOS (custo de aquisição)'],
            ['Ativo', 'Classe', 'Cotas em 31/12/'.$r['year'], 'Preço médio', 'Custo em 31/12/'.$r['year'], 'Custo em 31/12/'.($r['year'] - 1)],
        ];

        foreach ($r['positions'] as $p) {
            $linhas[] = [$p['investment']->displayName(), $p['investment']->asset_class->label(), $q($p['quantity']), $n($p['average_price']), $n($p['cost']), $n($p['previous_cost'])];
        }

        $linhas[] = ['Total', '', '', '', $n($r['positions_cost']), $n($r['previous_cost'])];
        $linhas[] = [];
        $linhas[] = ['PROVENTOS RECEBIDOS'];
        $linhas[] = ['Ativo', 'Natureza', 'Bruto', 'Imposto retido', 'Líquido'];

        foreach ($r['incomes'] as $p) {
            $linhas[] = [$p['investment']->displayName(), $p['label'], $n($p['gross']), $n($p['tax']), $n($p['net'])];
        }

        $linhas[] = [];
        $linhas[] = ['VENDAS DO ANO'];
        $linhas[] = ['Data', 'Ativo', 'Cotas', 'Valor da venda', 'Valor líquido', 'Custo', 'Resultado'];

        foreach ($r['months'] as $mes) {
            foreach ($mes['sales'] as $v) {
                $linhas[] = [$v['date']->format('d/m/Y'), $v['investment']->displayName(), $q($v['quantity']), $n($v['gross']), $n($v['net']), $n($v['cost']), $n($v['result'])];
            }
        }

        $nome = 'investimentos-imposto-de-renda-'.$this->ano.'.csv';

        return response()->streamDownload(function () use ($linhas): void {
            $saida = fopen('php://output', 'w');
            fwrite($saida, "\xEF\xBB\xBF"); // o Excel em português só lê acento com a marca de UTF-8

            foreach ($linhas as $linha) {
                fputcsv($saida, $linha, ';');
            }

            fclose($saida);
        }, $nome, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function render()
    {
        return view('livewire.investments.investment-tax-report', [
            'relatorio' => $this->relatorio(),
            'anos' => $this->anos(),
            'membros' => $this->membros(),
            'nomeDaPessoa' => $this->nomeDaPessoa(),
        ]);
    }
}
