<?php

namespace App\Livewire\Debts;

use App\Models\Debt;
use App\Models\ProfileMember;
use App\Services\DebtTaxReportService;
use App\Support\ProfileContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Apoio à seção "Dívidas e ônus reais" do imposto de renda: o que se devia em 31/12 do ano-base e do anterior, por dívida, com o
 * credor. É conferência, não a declaração (ver DebtTaxReportService). Mesmo desenho do relatório de investimentos.
 */
#[Layout('components.layouts.app')]
class DebtTaxReport extends Component
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

    /** @return list<int> do ano atual ao mais antigo com dívida; o ano anterior (o da declaração de agora) sempre entra */
    private function anos(): array
    {
        $atual = (int) now()->format('Y');
        $primeiro = Debt::query()->min('first_due_date');
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
        return app(DebtTaxReportService::class)->forYear((int) $this->ano, $this->pessoa === 'todos' ? null : $this->pessoa);
    }

    private function nomeDaPessoa(): string
    {
        return $this->pessoa === 'todos' ? 'Todos os membros do perfil' : ($this->membros()->firstWhere('id', $this->pessoa)?->name ?? '');
    }

    public function baixarPdf()
    {
        $pdf = Pdf::loadView('pdf.debt-tax-report', [
            'relatorio' => $this->relatorio(),
            'pessoa' => $this->nomeDaPessoa(),
            'geradoEm' => now(),
        ])->setPaper('a4');

        return response()->streamDownload(fn () => print ($pdf->output()), 'dividas-imposto-de-renda-'.$this->ano.'.pdf');
    }

    public function baixarCsv()
    {
        $r = $this->relatorio();
        $n = fn (string|int|float|null $v): string => number_format((float) $v, 2, ',', '');

        $linhas = [
            ['Dívidas e ônus reais', 'Ano-base '.$r['year'], $this->nomeDaPessoa()],
            [],
            ['Dívida', 'Credor', 'CPF ou CNPJ do credor', 'Contratada em', 'Situação em 31/12/'.($r['year'] - 1), 'Situação em 31/12/'.$r['year']],
        ];

        foreach ($r['rows'] as $l) {
            $linhas[] = [
                $l['debt']->name, $l['creditor'], $l['document'],
                $l['contracted_on'] ? $l['contracted_on']->format('d/m/Y').($l['contract_estimated'] ? ' (estimada)' : '') : '',
                $n($l['previous']), $n($l['current']),
            ];
        }

        $linhas[] = ['Total', '', '', '', $n($r['total_previous']), $n($r['total_current'])];

        if ($r['unscheduled'] !== []) {
            $linhas[] = [];
            $linhas[] = ['SEM CRONOGRAMA (valor informado, fora dos totais)'];
            $linhas[] = ['Dívida', 'Credor', 'CPF ou CNPJ do credor', 'Saldo informado', 'Informado em'];

            foreach ($r['unscheduled'] as $l) {
                $linhas[] = [$l['debt']->name, $l['debt']->creditor, $l['debt']->creditor_document, $n($l['balance']), $l['updated_on']?->format('d/m/Y')];
            }
        }

        return response()->streamDownload(function () use ($linhas): void {
            $saida = fopen('php://output', 'w');
            fwrite($saida, "\xEF\xBB\xBF"); // o Excel em português só lê acento com a marca de UTF-8

            foreach ($linhas as $linha) {
                fputcsv($saida, $linha, ';');
            }

            fclose($saida);
        }, 'dividas-imposto-de-renda-'.$this->ano.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function render()
    {
        return view('livewire.debts.debt-tax-report', [
            'relatorio' => $this->relatorio(),
            'anos' => $this->anos(),
            'membros' => $this->membros(),
            'nomeDaPessoa' => $this->nomeDaPessoa(),
        ]);
    }
}
