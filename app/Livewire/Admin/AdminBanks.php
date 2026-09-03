<?php

namespace App\Livewire\Admin;

use App\Models\Bank;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Fila de bancos sugeridos por cliente — quem digita um banco fora da
 * lista já cadastra normal (bank_name sempre foi texto livre, ver
 * Bank::resolveOrSuggest()), isto só dá ao admin um jeito de promover
 * aquele nome a banco oficial (visível a todo mundo, com cor de marca)
 * sem precisar de deploy. Não aprovar não bloqueia nada — o cliente que
 * sugeriu continua usando normalmente, só ele não vê a sugestão de outro.
 */
#[Layout('components.layouts.app')]
class AdminBanks extends Component
{
    /** cor escolhida por linha, chave é o id do banco — vazio usa um cinza neutro. */
    public array $corAprovacao = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->isPlatformAdmin(), 403);
    }

    public function aprovar(string $bankId): void
    {
        $banco = Bank::withoutTaxonomyScope()->findOrFail($bankId);
        $banco->approve($this->corAprovacao[$bankId] ?? null);

        session()->flash('status', "\"{$banco->name}\" agora é um banco padrão, visível a todo mundo.");
    }

    public function dispensar(string $bankId): void
    {
        $banco = Bank::withoutTaxonomyScope()->findOrFail($bankId);
        $banco->dismiss();

        session()->flash('status', "\"{$banco->name}\" saiu da fila — quem sugeriu continua usando normalmente.");
    }

    /** @return Collection<int, Bank> */
    public function getSugestoesProperty(): Collection
    {
        return Bank::withoutTaxonomyScope()
            ->pending()
            ->with('profile.owner')
            ->orderBy('name')
            ->get();
    }

    public function render()
    {
        return view('livewire.admin.admin-banks', [
            'aprovados' => Bank::shared()->orderBy('name')->get(),
        ]);
    }
}
