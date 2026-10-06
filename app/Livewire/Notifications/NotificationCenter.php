<?php

namespace App\Livewire\Notifications;

use App\Http\Middleware\SetProfileContext;
use App\Models\FinancialProfile;
use App\Support\NotificationPresenter;
use Livewire\Component;

/**
 * O sino de notificação, presente nas duas telas de navegação (barra
 * lateral e cabeçalho compacto) — cada aparição é uma instância Livewire
 * independente, sem estado compartilhado entre as duas.
 *
 * Sem wire:poll de propósito: o badge atualiza a cada navegação, que já é
 * frequente neste layout. Seria o primeiro wire:poll da base de código só
 * pra um ganho marginal — não vale a complexidade agora.
 */
class NotificationCenter extends Component
{
    /**
     * 'down' no cabeçalho (sobra espaço embaixo); 'up' na barra lateral,
     * onde o sino fica perto do rodapé da tela — abrir pra baixo ali
     * derrubaria o painel pra fora da viewport.
     */
    public string $direction = 'down';

    public function mount(string $direction = 'down'): void
    {
        $this->direction = $direction;
    }

    public function markAsRead(string $id): void
    {
        auth()->user()->unreadNotifications()->where('id', $id)->first()?->markAsRead();
    }

    /**
     * Clique na notificação: marca como lida e abre a tela de que ela trata.
     * Sem destino conhecido, só marca.
     */
    public function abrirNotificacao(string $id)
    {
        $notificacao = auth()->user()->notifications()->where('id', $id)->first();

        if ($notificacao === null) {
            return null;
        }

        $notificacao->markAsRead();

        // Aviso de dado de um perfil abre ESSE perfil antes de ir pra tela.
        // Só com acesso a ele; a sessão não concede nada (o middleware
        // reautoriza a cada requisição), é só pra escolher qual perfil abrir.
        $perfilId = NotificationPresenter::profileId($notificacao);
        $perfil = $perfilId !== null ? FinancialProfile::find($perfilId) : null;

        if ($perfil !== null && auth()->user()->can('view', $perfil)) {
            session()->put(SetProfileContext::SESSION_KEY, $perfil->id);
        }

        $url = NotificationPresenter::present($notificacao)['url'];

        return $url !== null ? $this->redirect($url) : null;
    }

    public function markAllAsRead(): void
    {
        auth()->user()->unreadNotifications->markAsRead();
    }

    public function render()
    {
        return view('livewire.notifications.notification-center', [
            'notifications' => auth()->user()->notifications()->latest()->limit(10)->get()
                ->map(fn ($n) => ['notificacao' => $n, 'dados' => NotificationPresenter::present($n)]),
            'unreadCount' => auth()->user()->unreadNotifications()->count(),
        ]);
    }
}
