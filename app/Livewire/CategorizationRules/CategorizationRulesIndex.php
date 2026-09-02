<?php

namespace App\Livewire\CategorizationRules;

use App\Enums\Necessity;
use App\Livewire\Concerns\RequiresActiveProfile;
use App\Models\ExpenseCategory;
use App\Models\ExpenseCategorizationRule;
use App\Models\ExpenseRecord;
use App\Models\ExpenseSubcategory;
use App\Models\FixedBill;
use App\Models\IncomeCategory;
use App\Models\IncomeCategorizationRule;
use App\Models\IncomeRecord;
use App\Models\RecurringIncome;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Regras de categorização usadas na revisão de um documento importado (ver
 * CategorizationRuleMatcher / DocumentsIndex). Duas abas — despesa e receita
 * têm taxonomias diferentes (despesa tem subcategoria e necessidade,
 * receita não), mesmo padrão de abas de FixedBillsIndex.
 */
#[Layout('components.layouts.app')]
class CategorizationRulesIndex extends Component
{
    use RequiresActiveProfile;

    #[Url]
    public string $tab = 'despesas';

    /**
     * Depois de salvar uma regra, quantos lançamentos já existentes batem
     * com o padrão dela — oferta de recategorizar em bloco, nunca
     * automático (ver aplicarRegraAosExistentes()).
     *
     * @var array{tipo: 'despesa'|'receita', ids: list<string>, quantidade: int, categoria_id: string, subcategoria_id: ?string, necessidade: ?string}|null
     */
    public ?array $regraAplicavelExistentes = null;

    // -----------------------------------------------------------------
    // Formulário — Regra de despesa
    // -----------------------------------------------------------------

    public bool $showExpenseForm = false;

    public ?string $editingExpenseRuleId = null;

    public string $expensePattern = '';

    public string $expenseCategoryId = '';

    public string $expenseSubcategoryId = '';

    public string $expenseNecessity = 'essential';

    public string $expenseFixedBillId = '';

    public ?string $confirmingDeleteExpenseRuleId = null;

    // -----------------------------------------------------------------
    // Formulário — Regra de receita
    // -----------------------------------------------------------------

    public bool $showIncomeForm = false;

    public ?string $editingIncomeRuleId = null;

    public string $incomePattern = '';

    public string $incomeCategoryId = '';

    public string $incomeRecurringIncomeId = '';

    public ?string $confirmingDeleteIncomeRuleId = null;

    public function mount(): void
    {
        $this->redirectOrAbortWithoutProfile();
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['despesas', 'receitas'], true) ? $tab : 'despesas';
    }

    /** Mesmo raciocínio de CashFlowIndex::updatedExpenseNecessity() — só limpa a categoria se ela deixou de valer, e sempre limpa a subcategoria pra Investimento (ela nem aparece nesse caso). */
    public function updatedExpenseNecessity(): void
    {
        if ($this->expenseNecessity === Necessity::Investment->value) {
            $this->expenseSubcategoryId = '';
        }

        if ($this->expenseCategoryId === '' || $this->expenseFormCategories->contains('id', $this->expenseCategoryId)) {
            return;
        }

        $this->expenseCategoryId = '';
        $this->expenseSubcategoryId = '';
    }

    // -----------------------------------------------------------------
    // Regra de despesa — cadastrar / editar / excluir
    // -----------------------------------------------------------------

    public function toggleExpenseForm(): void
    {
        $this->showExpenseForm = ! $this->showExpenseForm;

        if ($this->showExpenseForm) {
            $this->resetExpenseForm();
        }
    }

    public function editExpenseRule(string $id): void
    {
        $regra = ExpenseCategorizationRule::query()->findOrFail($id);

        $this->editingExpenseRuleId = $regra->id;
        $this->expensePattern = $regra->pattern;
        $this->expenseCategoryId = $regra->category_id;
        $this->expenseSubcategoryId = (string) $regra->subcategory_id;
        $this->expenseNecessity = $regra->necessity->value;
        $this->expenseFixedBillId = (string) $regra->fixed_bill_id;
        $this->showExpenseForm = true;
    }

    public function saveExpenseRule(): void
    {
        $data = $this->validate([
            'expensePattern' => ['required', 'string', 'max:255'],
            'expenseCategoryId' => ['required'],
            // Subcategoria é obrigatória — só não existe pra necessidade
            // Investimento, que nem mostra o campo.
            'expenseSubcategoryId' => [Rule::requiredIf($this->expenseNecessity !== Necessity::Investment->value)],
            'expenseNecessity' => ['required', Rule::enum(Necessity::class)],
            'expenseFixedBillId' => ['nullable'],
        ], attributes: [
            'expensePattern' => 'padrão',
            'expenseCategoryId' => 'categoria',
            'expenseSubcategoryId' => 'subcategoria',
            'expenseNecessity' => 'necessidade',
        ]);

        $categoria = ExpenseCategory::query()->findOrFail($data['expenseCategoryId']);
        $subcategoria = $this->expenseSubcategoryId !== ''
            ? ExpenseSubcategory::query()->findOrFail($this->expenseSubcategoryId)
            : null;
        $contaFixa = $this->expenseFixedBillId !== ''
            ? FixedBill::query()->findOrFail($this->expenseFixedBillId)
            : null;

        $payload = [
            'pattern' => $data['expensePattern'],
            'category_id' => $categoria->id,
            'subcategory_id' => $subcategoria?->id,
            'necessity' => $data['expenseNecessity'],
            'fixed_bill_id' => $contaFixa?->id,
        ];

        if ($this->editingExpenseRuleId !== null) {
            ExpenseCategorizationRule::query()->findOrFail($this->editingExpenseRuleId)->update($payload);
            session()->flash('status', 'Regra de despesa atualizada.');
        } else {
            ExpenseCategorizationRule::create($payload + ['is_active' => true]);
            session()->flash('status', 'Regra de despesa cadastrada.');
        }

        $this->detectarLancamentosExistentesParaRegra(
            'despesa', $data['expensePattern'], $categoria->id, $subcategoria?->id, $data['expenseNecessity'],
        );

        $this->resetExpenseForm();
        $this->showExpenseForm = false;
    }

    public function confirmDeleteExpenseRule(string $id): void
    {
        $this->confirmingDeleteExpenseRuleId = $id;
    }

    public function cancelDeleteExpenseRule(): void
    {
        $this->confirmingDeleteExpenseRuleId = null;
    }

    public function deleteExpenseRule(string $id): void
    {
        ExpenseCategorizationRule::query()->findOrFail($id)->delete();
        $this->confirmingDeleteExpenseRuleId = null;
        session()->flash('status', 'Regra de despesa excluída.');
    }

    private function resetExpenseForm(): void
    {
        $this->reset(
            'editingExpenseRuleId', 'expensePattern', 'expenseCategoryId',
            'expenseSubcategoryId', 'expenseFixedBillId',
        );
        $this->expenseNecessity = 'essential';
        $this->resetErrorBag();
    }

    // -----------------------------------------------------------------
    // Regra de receita — cadastrar / editar / excluir
    // -----------------------------------------------------------------

    public function toggleIncomeForm(): void
    {
        $this->showIncomeForm = ! $this->showIncomeForm;

        if ($this->showIncomeForm) {
            $this->resetIncomeForm();
        }
    }

    public function editIncomeRule(string $id): void
    {
        $regra = IncomeCategorizationRule::query()->findOrFail($id);

        $this->editingIncomeRuleId = $regra->id;
        $this->incomePattern = $regra->pattern;
        $this->incomeCategoryId = $regra->category_id;
        $this->incomeRecurringIncomeId = (string) $regra->recurring_income_id;
        $this->showIncomeForm = true;
    }

    public function saveIncomeRule(): void
    {
        $data = $this->validate([
            'incomePattern' => ['required', 'string', 'max:255'],
            'incomeCategoryId' => ['required'],
            'incomeRecurringIncomeId' => ['nullable'],
        ], attributes: [
            'incomePattern' => 'padrão',
            'incomeCategoryId' => 'categoria',
        ]);

        $categoria = IncomeCategory::query()->findOrFail($data['incomeCategoryId']);
        $receitaRecorrente = $this->incomeRecurringIncomeId !== ''
            ? RecurringIncome::query()->findOrFail($this->incomeRecurringIncomeId)
            : null;

        $payload = [
            'pattern' => $data['incomePattern'],
            'category_id' => $categoria->id,
            'recurring_income_id' => $receitaRecorrente?->id,
        ];

        if ($this->editingIncomeRuleId !== null) {
            IncomeCategorizationRule::query()->findOrFail($this->editingIncomeRuleId)->update($payload);
            session()->flash('status', 'Regra de receita atualizada.');
        } else {
            IncomeCategorizationRule::create($payload + ['is_active' => true]);
            session()->flash('status', 'Regra de receita cadastrada.');
        }

        $this->detectarLancamentosExistentesParaRegra('receita', $data['incomePattern'], $categoria->id, null, null);

        $this->resetIncomeForm();
        $this->showIncomeForm = false;
    }

    public function confirmDeleteIncomeRule(string $id): void
    {
        $this->confirmingDeleteIncomeRuleId = $id;
    }

    public function cancelDeleteIncomeRule(): void
    {
        $this->confirmingDeleteIncomeRuleId = null;
    }

    public function deleteIncomeRule(string $id): void
    {
        IncomeCategorizationRule::query()->findOrFail($id)->delete();
        $this->confirmingDeleteIncomeRuleId = null;
        session()->flash('status', 'Regra de receita excluída.');
    }

    private function resetIncomeForm(): void
    {
        $this->reset('editingIncomeRuleId', 'incomePattern', 'incomeCategoryId', 'incomeRecurringIncomeId');
        $this->resetErrorBag();
    }

    // -----------------------------------------------------------------
    // Comum
    // -----------------------------------------------------------------

    /**
     * Depois de salvar a regra, confere se algum lançamento JÁ existente
     * bate com o padrão — mesma lógica "contém" do
     * CategorizationRuleMatcher, mas aqui é sobre o passado, não sobre
     * importação. Nunca aplica sozinho: só guarda a oferta pra
     * aplicarRegraAosExistentes() confirmar.
     */
    private function detectarLancamentosExistentesParaRegra(
        string $tipo, string $pattern, string $categoriaId, ?string $subcategoriaId, ?string $necessidade,
    ): void {
        $modelo = $tipo === 'despesa' ? ExpenseRecord::class : IncomeRecord::class;

        $ids = $modelo::query()
            ->whereRaw('LOWER(description) LIKE ?', ['%'.mb_strtolower($pattern).'%'])
            ->pluck('id')
            ->all();

        if ($ids === []) {
            $this->regraAplicavelExistentes = null;

            return;
        }

        $this->regraAplicavelExistentes = [
            'tipo' => $tipo,
            'ids' => $ids,
            'quantidade' => count($ids),
            'categoria_id' => $categoriaId,
            'subcategoria_id' => $subcategoriaId,
            'necessidade' => $necessidade,
        ];
    }

    public function aplicarRegraAosExistentes(): void
    {
        if ($this->regraAplicavelExistentes === null) {
            return;
        }

        $r = $this->regraAplicavelExistentes;
        $modelo = $r['tipo'] === 'despesa' ? ExpenseRecord::class : IncomeRecord::class;

        $payload = ['category_id' => $r['categoria_id']];

        if ($r['tipo'] === 'despesa') {
            $payload['subcategory_id'] = $r['subcategoria_id'];
            $payload['necessity'] = $r['necessidade'];
        }

        // Um a um, não whereIn()->update() em massa — é o update() por
        // instância que dispara Auditable/InvalidatesDashboard (mesmo
        // raciocínio de CashFlowIndex::aplicarCategoriaAosDuplicados()).
        DB::transaction(function () use ($modelo, $r, $payload): void {
            foreach ($modelo::query()->whereIn('id', $r['ids'])->get() as $registro) {
                $registro->update($payload);
            }
        });

        session()->flash('status', "{$r['quantidade']} lançamento(s) já existente(s) recategorizado(s).");
        $this->regraAplicavelExistentes = null;
    }

    public function descartarAplicacaoAosExistentes(): void
    {
        $this->regraAplicavelExistentes = null;
    }

    /** @return Collection<int, ExpenseSubcategory> */
    public function getExpenseSubcategoriesProperty(): Collection
    {
        if ($this->expenseCategoryId === '') {
            return collect();
        }

        return ExpenseSubcategory::query()
            ->where('category_id', $this->expenseCategoryId)
            ->available()
            ->get();
    }

    /**
     * Mesmo raciocínio de CashFlowIndex::getExpenseFormCategoriesProperty():
     * categoria sem necessidade fixa aparece sempre, "Investimentos" só
     * quando a regra é de necessidade Investimento.
     *
     * @return Collection<int, ExpenseCategory>
     */
    public function getExpenseFormCategoriesProperty(): Collection
    {
        return ExpenseCategory::query()->available()
            ->where(function (Builder $query): void {
                $query->whereNull('necessity');

                if ($this->expenseNecessity !== '') {
                    $query->orWhere('necessity', $this->expenseNecessity);
                }
            })
            ->get();
    }

    /** @return Collection<int, ExpenseCategorizationRule> */
    public function getExpenseRulesProperty(): Collection
    {
        return ExpenseCategorizationRule::query()
            ->with('category', 'subcategory', 'fixedBill')
            ->orderByDesc('created_at')
            ->get();
    }

    /** @return Collection<int, IncomeCategorizationRule> */
    public function getIncomeRulesProperty(): Collection
    {
        return IncomeCategorizationRule::query()
            ->with('category', 'recurringIncome')
            ->orderByDesc('created_at')
            ->get();
    }

    public function render()
    {
        return view('livewire.categorization-rules.categorization-rules-index', [
            'expenseRules' => $this->expenseRules,
            'incomeRules' => $this->incomeRules,
            'expenseCategories' => $this->expenseFormCategories,
            'expenseSubcategories' => $this->expenseSubcategories,
            'incomeCategories' => IncomeCategory::query()->available()->get(),
            'fixedBills' => FixedBill::query()->active()->orderBy('name')->get(),
            'recurringIncomes' => RecurringIncome::query()->active()->orderBy('name')->get(),
        ]);
    }
}
