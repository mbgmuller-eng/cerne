<?php

namespace App\Livewire\Admin;

use App\Models\Insurer;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Seguradoras da plataforma: fila de sugestões (nome que algum cliente
 * digitou na apólice e não bate com nenhuma aprovada) + lista aprovada, que
 * o admin também pode cadastrar, renomear, recolorir e remover. Mesmo
 * padrão de AdminBanks.
 *
 * Nada aqui mexe em apólice já cadastrada: insurance_policies.insurer_name é
 * texto, não um id desta tabela (ver App\Models\Insurer).
 */
#[Layout('components.layouts.app')]
class AdminInsurers extends Component
{
    /** @var array<string, string> cor escolhida por linha da fila, chave = id da sugestão */
    public array $corAprovacao = [];

    public string $novoNome = '';

    public string $novaCor = '#64748B';

    public ?string $editandoId = null;

    public string $editNome = '';

    public string $editCor = '#64748B';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isPlatformAdmin(), 403);

        // O seletor de cor precisa de um valor inicial: sem ele o wire:model o zera e o campo aparece preto.
        $this->corAprovacao = $this->sugestoes->mapWithKeys(fn (Insurer $i) => [$i->id => $i->color_hex ?? '#64748B'])->all();
    }

    public function aprovar(string $insurerId): void
    {
        $seguradora = Insurer::withoutTaxonomyScope()->findOrFail($insurerId);
        $cor = $this->corAprovacao[$insurerId] ?? null;
        $seguradora->approve(is_string($cor) && preg_match('/^#[0-9A-Fa-f]{6}$/', $cor) ? $cor : null);

        session()->flash('status', "\"{$seguradora->name}\" agora é uma seguradora padrão, visível a todo mundo.");
    }

    public function dispensar(string $insurerId): void
    {
        $seguradora = Insurer::withoutTaxonomyScope()->findOrFail($insurerId);
        $seguradora->dismiss();

        session()->flash('status', "\"{$seguradora->name}\" saiu da fila: quem sugeriu continua usando normalmente.");
    }

    public function adicionar(): void
    {
        $dados = $this->validate([
            'novoNome' => ['required', 'string', 'max:100'],
            'novaCor' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ], attributes: ['novoNome' => 'nome', 'novaCor' => 'cor']);

        if (Insurer::approvedNameTaken($dados['novoNome'])) {
            $this->addError('novoNome', 'Essa seguradora já está na lista.');

            return;
        }

        Insurer::createApproved($dados['novoNome'], $dados['novaCor']);
        $this->reset('novoNome');

        session()->flash('status', 'Seguradora adicionada.');
    }

    public function iniciarEdicao(string $insurerId): void
    {
        $seguradora = Insurer::withoutTaxonomyScope()->shared()->findOrFail($insurerId);

        $this->editandoId = $seguradora->id;
        $this->editNome = $seguradora->name;
        $this->editCor = $seguradora->color_hex ?? '#64748B';
        $this->resetErrorBag();
    }

    public function cancelarEdicao(): void
    {
        $this->reset('editandoId', 'editNome');
        $this->resetErrorBag();
    }

    public function salvarEdicao(): void
    {
        $dados = $this->validate([
            'editNome' => ['required', 'string', 'max:100'],
            'editCor' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ], attributes: ['editNome' => 'nome', 'editCor' => 'cor']);

        $seguradora = Insurer::withoutTaxonomyScope()->shared()->findOrFail($this->editandoId);

        if (Insurer::approvedNameTaken($dados['editNome'], $seguradora->id)) {
            $this->addError('editNome', 'Já existe outra seguradora com esse nome.');

            return;
        }

        // Renomear não altera as apólices: elas guardam o texto que o cliente digitou.
        $seguradora->update(['name' => trim($dados['editNome']), 'color_hex' => $dados['editCor']]);

        $this->cancelarEdicao();
        session()->flash('status', 'Seguradora atualizada.');
    }

    public function remover(string $insurerId): void
    {
        $seguradora = Insurer::withoutTaxonomyScope()->shared()->findOrFail($insurerId);
        $nome = $seguradora->name;
        $seguradora->delete();

        session()->flash('status', "\"{$nome}\" saiu da lista. As apólices que já usam esse nome não mudam.");
    }

    public function getSugestoesProperty(): Collection
    {
        return Insurer::withoutTaxonomyScope()->pending()->with('profile.owner')->orderBy('name')->get();
    }

    public function render()
    {
        return view('livewire.admin.admin-insurers', [
            'aprovadas' => Insurer::withoutTaxonomyScope()->shared()->orderBy('name')->get(),
        ]);
    }
}
