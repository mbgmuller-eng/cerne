<?php

namespace App\Livewire\Engagement;

use App\Models\EngagementNote;
use App\Services\EngagementAccess;
use App\Services\EngagementService;
use App\Support\Engagement\EngagementViewer;
use Livewire\Component;

/**
 * Notas do acompanhamento. As compartilhadas aparecem para os dois lados; as privadas, só para o consultor que as escreveu.
 */
class EngagementNotes extends Component
{
    public string $body = '';

    public bool $private = false;

    private function visitante(): EngagementViewer
    {
        return app(EngagementAccess::class)->viewer() ?? abort(404);
    }

    public function save(): void
    {
        $visitante = $this->visitante();

        $dados = $this->validate(['body' => ['required', 'string', 'max:3000']], [], ['body' => 'nota']);

        app(EngagementService::class)->addNote($visitante, $dados['body'], $visitante->isConsultant() && $this->private);

        $this->reset('body', 'private');
    }

    public function delete(string $id): void
    {
        $visitante = $this->visitante();

        app(EngagementService::class)->deleteNote($visitante, EngagementNote::query()->findOrFail($id));
    }

    public function render()
    {
        $visitante = $this->visitante();

        $notas = EngagementNote::query()->with('author')->latest()->limit(40)->get();

        return view('livewire.engagement.engagement-notes', [
            'visitante' => $visitante,
            'compartilhadas' => $notas->where('is_private', false)->values(),
            'privadas' => $notas->where('is_private', true)->values(),
        ]);
    }
}
