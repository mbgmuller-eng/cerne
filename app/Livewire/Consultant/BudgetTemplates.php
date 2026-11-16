<?php

namespace App\Livewire\Consultant;

use App\Enums\Necessity;
use App\Livewire\Concerns\EditsBudgetStructure;
use App\Models\BudgetRule;
use App\Models\BudgetRuleTemplate;
use App\Models\ExpenseCategory;
use App\Models\FinancialProfile;
use App\Models\ConsultantClient;
use App\Services\BudgetRuleService;
use App\Support\Budget\BudgetStructure;
use App\Support\ClientDisplayName;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Modelos de regra de orçamento do consultor: a metodologia dele ("50/20/30 com tercos"), guardada uma vez e aplicada a vários clientes. Aplicar COPIA a
 * estrutura para a regra do cliente a partir de um mês; mudar o modelo depois não altera quem já recebeu. Só o consultor (nunca o corretor) e só os
 * clientes com vínculo ATIVO dele (o id vem do navegador e é conferido contra a lista de vínculos).
 */
#[Layout('components.layouts.app')]
class BudgetTemplates extends Component
{
    use EditsBudgetStructure;

    public bool $showEditor = false;

    public ?string $editingId = null;

    public string $name = '';

    public string $description = '';

    public bool $showApply = false;

    public ?string $applyId = null;

    /** @var list<string> perfis marcados para receber o modelo */
    public array $applyClients = [];

    public string $applyYm = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isConsultant(), 403);
    }

    // ------------------------------------------------------------------ editar o modelo

    public function newTemplate(): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->name = '';
        $this->description = '';
        $this->loadDraft(BudgetStructure::default());
        $this->showEditor = true;
    }

    public function edit(string $id): void
    {
        $modelo = BudgetRuleTemplate::query()->findOrFail($id);

        $this->resetValidation();
        $this->editingId = $modelo->id;
        $this->name = $modelo->name;
        $this->description = (string) $modelo->description;
        $this->loadDraft((array) $modelo->structure);
        $this->showEditor = true;
    }

    public function closeEditor(): void
    {
        $this->showEditor = false;
        $this->resetValidation();
    }

    public function saveTemplate(): void
    {
        $dados = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
        ], [], ['name' => 'nome', 'description' => 'descrição']);

        // Os erros da regra aparecem, ao vivo, no quadro do editor; aqui só impede de salvar.
        if ($this->draftErrors() !== []) {
            return;
        }

        $campos = ['name' => $dados['name'], 'description' => ($dados['description'] ?? '') !== '' ? $dados['description'] : null, 'structure' => $this->draftNormalized()];

        if ($this->editingId !== null) {
            BudgetRuleTemplate::query()->findOrFail($this->editingId)->update($campos);
        } else {
            BudgetRuleTemplate::create($campos);
        }

        $this->showEditor = false;
        session()->flash('budget_templates_status', 'Modelo salvo.');
    }

    public function delete(string $id): void
    {
        BudgetRuleTemplate::query()->findOrFail($id)->delete();
        session()->flash('budget_templates_status', 'Modelo excluído. As regras já aplicadas a clientes continuam como estão.');
    }

    // ------------------------------------------------------------------ aplicar a clientes

    public function openApply(string $id): void
    {
        BudgetRuleTemplate::query()->findOrFail($id);

        $this->resetValidation();
        $this->applyId = $id;
        $this->applyClients = [];
        $this->applyYm = CarbonImmutable::now()->format('Y-m');
        $this->showApply = true;
    }

    public function closeApply(): void
    {
        $this->showApply = false;
    }

    public function applyToClients(): void
    {
        $modelo = BudgetRuleTemplate::query()->findOrFail((string) $this->applyId);

        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $this->applyYm, $m) !== 1 || (int) $m[1] < 2020 || (int) $m[1] > CarbonImmutable::now()->year + 2) {
            $this->addError('applyYm', 'Escolha o mês de início da regra.');

            return;
        }

        // Só cliente com vínculo ativo deste consultor: o id marcado vem do navegador.
        $permitidos = ConsultantClient::activeProfileIds(auth()->user())->all();
        $escolhidos = array_values(array_intersect($this->applyClients, $permitidos));

        if ($escolhidos === []) {
            $this->addError('applyClients', 'Marque ao menos um cliente.');

            return;
        }

        $servico = app(BudgetRuleService::class);

        foreach ($escolhidos as $perfilId) {
            $servico->save($perfilId, (int) $m[1], (int) $m[2], (array) $modelo->structure, auth()->id());
        }

        $this->showApply = false;
        session()->flash('budget_templates_status', count($escolhidos) === 1
            ? 'Modelo aplicado a 1 cliente a partir de '.$m[2].'/'.$m[1].'.'
            : 'Modelo aplicado a '.count($escolhidos).' clientes a partir de '.$m[2].'/'.$m[1].'.');
    }

    /** Sem perfil ativo na área do consultor: as categorias do modelo são as padrão do sistema (as do cliente têm os mesmos nomes). */
    protected function categoryOptions(): array
    {
        return $this->withUsedCategories(
            ExpenseCategory::withoutTaxonomyScope()->whereNull('profile_id')->where('necessity', Necessity::Investment->value)->where('is_active', true)->orderBy('sort_order')->pluck('name')->unique()->values()->all(),
        );
    }

    // ------------------------------------------------------------------ tela

    public function render()
    {
        $erros = $this->showEditor ? $this->draftErrors() : [];

        $clientes = collect();

        if ($this->showApply) {
            $perfis = FinancialProfile::query()->whereIn('id', ConsultantClient::activeProfileIds(auth()->user()))->with(['owner', 'members.user'])->get();
            $regras = BudgetRule::withoutProfileScope()->whereIn('profile_id', $perfis->pluck('id'))->orderByDesc('effective_year')->orderByDesc('effective_month')->get()->groupBy('profile_id');

            $clientes = $perfis->map(fn (FinancialProfile $p) => [
                'id' => $p->id,
                'name' => ClientDisplayName::forProfile($p),
                'rule' => $regras->get($p->id)?->first(),
            ])->sortBy('name')->values();
        }

        return view('livewire.consultant.budget-templates', [
            'modelos' => BudgetRuleTemplate::query()->orderBy('name')->get(),
            'presets' => BudgetStructure::presets(),
            'editorErros' => $erros,
            'categoryOptions' => $this->showEditor ? $this->categoryOptions() : [],
            'clientes' => $clientes,
        ]);
    }
}
