<?php

namespace App\Livewire;

use App\Livewire\Concerns\RequiresActiveProfile;
use App\Models\Debt;
use App\Models\Document;
use App\Models\ExpenseRecord;
use App\Models\FixedBill;
use App\Models\Goal;
use App\Models\IncomeRecord;
use App\Models\InsurancePolicy;
use App\Models\InvestmentRecord;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Busca global do perfil aberto: lançamentos, contas fixas, dívidas, objetivos, investimentos, apólices e documentos.
 *
 * Toda consulta passa pelos escopos do model (perfil, privacidade do casal, visibilidade do corretor e do documento), então a busca nunca
 * mostra o que a tela de origem não mostraria. Só procura em texto que está em claro no banco: campo cifrado (`Sensitive`, como o nome do
 * segurado e as notas) não dá para filtrar em SQL e por isso fica de fora de propósito.
 */
#[Layout('components.layouts.app')]
class GlobalSearch extends Component
{
    use RequiresActiveProfile;

    /** Cada grupo mostra no máximo isto: a busca serve para achar, não para listar tudo (a tela de origem faz isso). */
    private const LIMIT = 8;

    private const MIN_LENGTH = 2;

    #[Url(as: 'q')]
    public string $q = '';

    public function mount(): void
    {
        $this->redirectOrAbortWithoutProfile();
        // Corretor só entra em Seguros: nenhuma outra tela financeira (e a busca cruzaria o que ele não vê).
        abort_if(auth()->user()?->isBroker(), 403);
    }

    /** Escapa os curingas do LIKE para o que a pessoa digitou valer como texto, não como padrão. */
    private function like(): string
    {
        return '%'.addcslashes(trim($this->q), '%_\\').'%';
    }

    public function render()
    {
        $termo = trim($this->q);
        $grupos = [];

        if (mb_strlen($termo) >= self::MIN_LENGTH) {
            $like = $this->like();

            $grupos[] = ['titulo' => 'Despesas', 'itens' => ExpenseRecord::query()->with('category')->where('description', 'like', $like)
                ->orderByDesc('expense_date')->limit(self::LIMIT)->get()->map(fn (ExpenseRecord $e) => [
                    'titulo' => $e->description, 'apoio' => $e->category?->name.' · '.$e->expense_date->format('d/m/Y'), 'valor' => $e->amount, 'sinal' => -1,
                    'url' => route('cashflow.index', ['year' => $e->expense_date->year, 'month' => $e->expense_date->month]),
                ])->all()];

            $grupos[] = ['titulo' => 'Receitas', 'itens' => IncomeRecord::query()->with('category')->where('description', 'like', $like)
                ->orderByDesc('received_date')->limit(self::LIMIT)->get()->map(fn (IncomeRecord $r) => [
                    'titulo' => $r->description, 'apoio' => $r->category?->name.' · '.$r->received_date->format('d/m/Y'), 'valor' => $r->amount, 'sinal' => 1,
                    'url' => route('cashflow.index', ['year' => $r->received_date->year, 'month' => $r->received_date->month]),
                ])->all()];

            $grupos[] = ['titulo' => 'Contas fixas', 'itens' => FixedBill::query()->where('name', 'like', $like)->orderBy('name')->limit(self::LIMIT)->get()
                ->map(fn (FixedBill $b) => ['titulo' => $b->name, 'apoio' => $b->is_active ? 'Conta fixa' : 'Conta fixa encerrada', 'valor' => $b->amount, 'sinal' => -1, 'url' => route('fixedbills.index')])->all()];

            $grupos[] = ['titulo' => 'Dívidas', 'itens' => Debt::query()->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('creditor', 'like', $like))
                ->orderBy('name')->limit(self::LIMIT)->get()
                ->map(fn (Debt $d) => ['titulo' => $d->name, 'apoio' => $d->creditor, 'valor' => null, 'sinal' => 0, 'url' => route('debts.show', $d)])->all()];

            $grupos[] = ['titulo' => 'Objetivos', 'itens' => Goal::query()->where('name', 'like', $like)->orderBy('name')->limit(self::LIMIT)->get()
                ->map(fn (Goal $g) => ['titulo' => $g->name, 'apoio' => 'Objetivo', 'valor' => $g->estimated_value, 'sinal' => 0, 'url' => route('goals.index')])->all()];

            $grupos[] = ['titulo' => 'Investimentos', 'itens' => InvestmentRecord::query()->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('ticker', 'like', $like)->orWhere('institution', 'like', $like))
                ->orderBy('name')->limit(self::LIMIT)->get()
                ->map(fn (InvestmentRecord $i) => ['titulo' => $i->name, 'apoio' => trim(($i->ticker ? $i->ticker.' · ' : '').$i->institution, ' ·'), 'valor' => $i->current_amount, 'sinal' => 0, 'url' => route('investments.index')])->all()];

            // O nome do segurado é cifrado: a apólice se acha pela seguradora, pelo item segurado e pelo número.
            $grupos[] = ['titulo' => 'Apólices', 'itens' => InsurancePolicy::query()->where(fn ($q) => $q->where('insurer_name', 'like', $like)->orWhere('insured_item', 'like', $like)->orWhere('policy_number', 'like', $like))
                ->orderBy('insurer_name')->limit(self::LIMIT)->get()
                ->map(fn (InsurancePolicy $p) => ['titulo' => $p->insurer_name, 'apoio' => $p->insurance_type?->label(), 'valor' => $p->monthly_premium, 'sinal' => 0, 'url' => route('insurance.index')])->all()];

            $grupos[] = ['titulo' => 'Documentos', 'itens' => Document::query()->where(fn ($q) => $q->where('title', 'like', $like)->orWhere('original_filename', 'like', $like))
                ->orderBy('title')->limit(self::LIMIT)->get()
                ->map(fn (Document $d) => ['titulo' => $d->title, 'apoio' => $d->original_filename, 'valor' => null, 'sinal' => 0, 'url' => route('documents.vault.index')])->all()];

            $grupos = array_values(array_filter($grupos, fn (array $g) => $g['itens'] !== []));
        }

        return view('livewire.global-search', [
            'grupos' => $grupos,
            'termo' => $termo,
            'curto' => $termo !== '' && mb_strlen($termo) < self::MIN_LENGTH,
        ]);
    }
}
