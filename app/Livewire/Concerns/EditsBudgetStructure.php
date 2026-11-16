<?php

namespace App\Livewire\Concerns;

use App\Enums\Necessity;
use App\Models\ExpenseCategory;
use App\Support\Budget\BudgetStructure;

/**
 * O editor da regra de orçamento, comum à tela do cliente e à do modelo do consultor: o rascunho (`$draft`) é a estrutura em edição, e cada
 * clique de adicionar ou tirar grupo e item mexe só nele. Nada é gravado aqui; quem usa o trait grava no seu próprio "Salvar", depois de
 * `BudgetStructure::validate`.
 */
trait EditsBudgetStructure
{
    /** @var array<string, mixed> */
    public array $draft = [];

    /** @param  array<string, mixed>  $estrutura */
    protected function loadDraft(array $estrutura): void
    {
        $this->draft = BudgetStructure::normalize($estrutura);
    }

    public function usePreset(string $chave): void
    {
        abort_unless(array_key_exists($chave, BudgetStructure::presets()), 404);

        $this->loadDraft(BudgetStructure::preset($chave));
    }

    public function addGroup(): void
    {
        if (count($this->draft['groups'] ?? []) >= BudgetStructure::MAX_GROUPS) {
            return;
        }

        $this->draft['groups'][] = ['key' => '', 'name' => '', 'percent' => '0', 'items' => []];
    }

    public function removeGroup(int $grupo): void
    {
        unset($this->draft['groups'][$grupo]);
        $this->draft['groups'] = array_values($this->draft['groups'] ?? []);
    }

    public function addItem(int $grupo): void
    {
        if (! isset($this->draft['groups'][$grupo]) || count($this->draft['groups'][$grupo]['items'] ?? []) >= BudgetStructure::MAX_ITEMS) {
            return;
        }

        $this->draft['groups'][$grupo]['items'][] = ['key' => '', 'name' => '', 'kind' => 'percent', 'percent' => '0', 'sources' => [], 'categories' => []];
    }

    public function removeItem(int $grupo, int $item): void
    {
        unset($this->draft['groups'][$grupo]['items'][$item]);
        $this->draft['groups'][$grupo]['items'] = array_values($this->draft['groups'][$grupo]['items'] ?? []);
    }

    /** O rascunho limpo e no formato da regra. @return array<string, mixed> */
    protected function draftNormalized(): array
    {
        return BudgetStructure::normalize($this->draft);
    }

    /** @return list<string> o que impede de salvar, em português */
    protected function draftErrors(): array
    {
        return BudgetStructure::validate($this->draftNormalized());
    }

    /**
     * As categorias de investimento que o editor oferece para compor um item: as do perfil e as padrão do sistema, sem repetir nome.
     *
     * @return list<string>
     */
    protected function categoryOptions(): array
    {
        return $this->withUsedCategories(ExpenseCategory::query()->available()->where('necessity', Necessity::Investment->value)->pluck('name')->unique()->values()->all());
    }

    /**
     * Soma às opções as categorias que o rascunho já usa (de um modelo de outro perfil, por exemplo), para nenhuma marcação sumir da tela.
     *
     * @param  list<string>  $opcoes
     * @return list<string>
     */
    protected function withUsedCategories(array $opcoes): array
    {
        foreach ($this->draft['groups'] ?? [] as $grupo) {
            foreach ($grupo['items'] ?? [] as $item) {
                foreach ($item['categories'] ?? [] as $nome) {
                    if (! in_array(BudgetStructure::fold($nome), array_map([BudgetStructure::class, 'fold'], $opcoes), true)) {
                        $opcoes[] = $nome;
                    }
                }
            }
        }

        return $opcoes;
    }
}
