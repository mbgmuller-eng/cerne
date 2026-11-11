<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso ao DONO do perfil quando o consultor monta, em nome dele, um convite de cônjuge ou o acesso de um familiar. Nada vale (nem sai e-mail ao
 * convidado) até o dono aprovar em Minha conta: sem isso o consultor convidaria um e-mail que ele mesmo controla e ganharia um login dentro do perfil.
 */
class ConsultantAccessRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $consultantName,
        public string $inviteeName,
        public string $what,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $notifiable->notify_email_enabled ? ['database', 'mail'] : ['database'];
    }

    private function message(): string
    {
        return "{$this->consultantName} pediu para dar a {$this->inviteeName} {$this->what}. Nada acontece até você aprovar. Se você não reconhece o pedido, recuse.";
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Pedido de acesso à sua conta: {$this->inviteeName}")
            ->markdown('mail.engagement-notice', [
                'recipientName' => $notifiable->name,
                'heading' => "Aprove ou recuse o acesso de {$this->inviteeName}",
                'message' => $this->message(),
                'url' => route('my-account'),
                'buttonLabel' => 'Ver o pedido',
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'consultant_access_requested',
            'title' => "Aprove ou recuse o acesso de {$this->inviteeName}",
            'message' => $this->message(),
        ];
    }
}
