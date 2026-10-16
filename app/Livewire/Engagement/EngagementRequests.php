<?php

namespace App\Livewire\Engagement;

use App\Enums\EngagementRequestStatus;
use App\Models\EngagementRequest;
use App\Services\EngagementAccess;
use App\Services\EngagementService;
use App\Support\Engagement\EngagementViewer;
use Livewire\Component;

/**
 * Demandas: o cliente pede ajuda ("preciso de ajuda com..."); o consultor responde, resolve ou transforma em tarefa.
 */
class EngagementRequests extends Component
{
    public bool $showForm = false;

    public string $title = '';

    public string $body = '';

    /** Demanda aberta na lista (conversa visível). */
    public ?string $openId = null;

    public string $reply = '';

    private function visitante(): EngagementViewer
    {
        return app(EngagementAccess::class)->viewer() ?? abort(404);
    }

    public function openForm(): void
    {
        abort_unless($this->visitante()->isClient(), 403);

        $this->resetValidation();
        $this->reset('title', 'body');
        $this->showForm = true;
    }

    public function save(): void
    {
        $visitante = $this->visitante();

        $dados = $this->validate([
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:3000'],
        ], [], ['title' => 'assunto', 'body' => 'descrição']);

        app(EngagementService::class)->openRequest($visitante, $dados['title'], $dados['body']);

        $this->showForm = false;
    }

    public function toggle(string $id): void
    {
        $this->openId = $this->openId === $id ? null : $id;
        $this->reply = '';
        $this->resetValidation();
    }

    public function sendReply(): void
    {
        $visitante = $this->visitante();

        $dados = $this->validate(['reply' => ['required', 'string', 'max:3000']], [], ['reply' => 'mensagem']);

        app(EngagementService::class)->commentRequest($visitante, EngagementRequest::query()->findOrFail($this->openId), $dados['reply']);

        $this->reply = '';
    }

    public function resolve(string $id): void
    {
        $visitante = $this->visitante();

        app(EngagementService::class)->resolveRequest($visitante, EngagementRequest::query()->findOrFail($id));
    }

    public function convertToTask(string $id): void
    {
        $visitante = $this->visitante();

        app(EngagementService::class)->convertRequestToTask($visitante, EngagementRequest::query()->findOrFail($id), 'consultant');
    }

    public function render()
    {
        $visitante = $this->visitante();

        $todas = EngagementRequest::query()->with(['comments.author', 'author', 'task'])->latest()->get();

        return view('livewire.engagement.engagement-requests', [
            'visitante' => $visitante,
            'ativas' => $todas->filter(fn (EngagementRequest $d) => $d->status !== EngagementRequestStatus::Resolved)->values(),
            'resolvidas' => $todas->filter(fn (EngagementRequest $d) => $d->status === EngagementRequestStatus::Resolved)->take(8)->values(),
        ]);
    }
}
