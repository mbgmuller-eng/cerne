<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * O débito automático não está de pé: o banco do cliente não confirmou a
 * autorização, ou ela foi cancelada/expirou. Sem uma nova autorização as
 * cobranças mensais deixam de sair. Sempre por e-mail, sem checar
 * notify_email_enabled: é aviso de cobrança, não notificação opcional.
 */
class PixAutomaticAuthorizationFailed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $bundleLabel,
        public bool $recusada,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->recusada ? 'Não conseguimos ativar o débito automático' : 'O débito automático da sua assinatura foi encerrado')
            ->markdown('mail.pix-automatic-authorization-failed', [
                'name' => $notifiable->name,
                'bundleLabel' => $this->bundleLabel,
                'recusada' => $this->recusada,
                'url' => route('subscription.index'),
            ]);
    }
}
