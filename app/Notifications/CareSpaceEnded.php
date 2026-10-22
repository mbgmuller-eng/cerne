<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A própria pessoa acompanhada encerrou o espaço dela (direito de eliminação). Avisa o dono: os dados foram apagados e a cobrança dela sai
 * a partir da próxima cobrança. Carrega o texto pronto: o worker da fila não tem perfil ativo.
 */
class CareSpaceEnded extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $personName) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $notifiable->notify_email_enabled ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->personName} encerrou o espaço de saúde no Cerne")
            ->markdown('mail.engagement-notice', [
                'recipientName' => $notifiable->name,
                'heading' => 'Espaço encerrado',
                'message' => "{$this->personName} pediu o encerramento do espaço dela. Os dados e os documentos foram apagados, quem cuidava junto perdeu o acesso e a cobrança dela sai a partir da próxima cobrança.",
                'url' => route('care.index'),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'care_space_ended',
            'title' => "{$this->personName} encerrou o espaço de saúde dela",
        ];
    }
}
