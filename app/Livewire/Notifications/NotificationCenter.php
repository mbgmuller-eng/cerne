<?php

namespace App\Livewire\Notifications;

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
    public function markAsRead(string $id): void
    {
        auth()->user()->unreadNotifications()->where('id', $id)->first()?->markAsRead();
    }

    public function markAllAsRead(): void
    {
        auth()->user()->unreadNotifications->markAsRead();
    }

    public function render()
    {
        return view('livewire.notifications.notification-center', [
            'notifications' => auth()->user()->notifications()->latest()->limit(10)->get(),
            'unreadCount' => auth()->user()->unreadNotifications()->count(),
        ]);
    }
}
