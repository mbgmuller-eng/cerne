<?php

namespace App\Livewire\Tutorial;

use App\Enums\TutorialStatus;
use App\Models\TutorialProgress;
use App\Support\Tutorials\TutorialCatalog;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * O tutorial da tela em que a pessoa está: o cartão de primeira visita no topo e a janela "Como usar esta tela", aberta por ele ou pelo botão "?"
 * do menu (que dispara o evento tutorial-open). Mora no layout, então recebe o nome da rota na montagem: numa ação do Livewire a requisição já é
 * a do Livewire, e `request()->route()` deixaria de dizer em que tela a pessoa está.
 */
class PageHelp extends Component
{
    /** O nome da rota é a chave do tutorial: travado para ninguém gravar progresso de uma tela que não é a sua. */
    #[Locked]
    public ?string $routeName = null;

    public bool $open = false;

    public function mount(?string $routeName = null): void
    {
        $this->routeName = $routeName;
    }

    #[On('tutorial-open')]
    public function openTutorial(): void
    {
        if (! TutorialCatalog::has($this->routeName)) {
            return;
        }

        $this->open = true;
        $this->record(TutorialStatus::Viewed);
    }

    public function dismissCard(): void
    {
        $this->record(TutorialStatus::Dismissed);
    }

    /** Quem está vendo como outra pessoa ("Entrar como") não grava nada na conta dela. */
    private function recording(): bool
    {
        return auth()->check() && ! session()->has('impersonator_id') && TutorialCatalog::has($this->routeName);
    }

    private function record(TutorialStatus $status): void
    {
        if (! $this->recording()) {
            return;
        }

        $linha = [
            'id' => (string) Str::uuid(),
            'user_id' => auth()->id(),
            'tutorial_key' => $this->routeName,
            'status' => $status->value,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if ($status === TutorialStatus::Viewed) {
            // Abrir o tutorial vale mais que dispensar o cartão: troca "dispensado" por "visto", e nunca o contrário.
            TutorialProgress::query()->upsert([$linha], ['user_id', 'tutorial_key'], ['status', 'updated_at']);

            return;
        }

        // O índice único segura as duas abas clicando juntas; quem já tinha visto o tutorial continua como "visto".
        TutorialProgress::query()->insertOrIgnore([$linha]);
    }

    public function render()
    {
        $tutorial = TutorialCatalog::for($this->routeName);

        $primeiraVisita = $tutorial !== null
            && auth()->check()
            && ! session()->has('impersonator_id')
            && ! TutorialProgress::query()->where('user_id', auth()->id())->where('tutorial_key', $this->routeName)->exists();

        return view('livewire.tutorial.page-help', [
            'tutorial' => $tutorial,
            'primeiraVisita' => $primeiraVisita,
        ]);
    }
}
