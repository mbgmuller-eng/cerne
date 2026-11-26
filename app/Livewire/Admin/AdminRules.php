<?php

namespace App\Livewire\Admin;

use App\Models\RuleReview;
use App\Models\RuleReviewer;
use App\Services\RuleReviewService;
use App\Support\Rules\RuleBook;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Revisão das regras de atividade física: convidar o profissional, revogar o acesso e ler os pareceres. Só o administrador. As mudanças
 * pedidas são feitas no código, e a aprovação de uma regra cai sozinha quando o conteúdo dela muda.
 */
#[Layout('components.layouts.app')]
class AdminRules extends Component
{
    public string $name = '';

    public string $registry = '';

    public string $email = '';

    /** O link recém-criado: aparece uma vez só. */
    public string $newLink = '';

    public function mount(): void
    {
        $this->guard();
    }

    private function guard(): void
    {
        abort_unless(auth()->user()?->isPlatformAdmin() && ! session()->has('impersonator_id'), 403);
    }

    public function invite(RuleReviewService $service): void
    {
        $this->guard();
        $this->validate([
            'name' => ['required', 'string', 'max:160'],
            'registry' => ['required', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:190'],
        ], attributes: ['name' => 'nome', 'registry' => 'CREF', 'email' => 'e-mail']);

        [, $link] = $service->invite($this->name, $this->registry, $this->email, auth()->user());
        $this->newLink = $link;
        $this->reset('name', 'registry', 'email');
    }

    public function revoke(string $id, RuleReviewService $service): void
    {
        $this->guard();
        $service->revoke(RuleReviewer::query()->findOrFail($id));
    }

    public function render(RuleReviewService $service)
    {
        $this->guard();
        $regras = RuleBook::all();

        return view('livewire.admin.admin-rules', [
            'regras' => $regras,
            'aprovacoes' => collect($regras)->map(fn (array $r) => $service->approval($r['key'])),
            'revisores' => RuleReviewer::query()->orderByDesc('created_at')->get(),
            'pareceres' => RuleReview::query()->whereNotNull('submitted_at')->with('reviewer')->orderByDesc('submitted_at')->limit(100)->get(),
        ]);
    }
}
