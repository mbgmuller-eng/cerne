<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Aviso a quem acompanha as finanças de alguém: conta a vencer ou atrasada, gasto acima do limite combinado e o resumo da semana. Carrega o texto
 * pronto e só o id do perfil (o worker da fila não tem contexto de perfil); o link entra pelo `care.goto`, que escolhe o perfil de quem se acompanha
 * antes de abrir a tela de leitura.
 */
class FamilyFinanceNotice extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $profileId,
        public string $kind,
        public string $heading,
        public string $message,
        public ?string $subjectId = null,
    ) {}

    private function url(): string
    {
        return route('care.goto', ['profile' => $this->profileId, 'destino' => 'financas']);
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($notifiable->notify_email_enabled) {
            $channels[] = 'mail';
        }

        if ($notifiable->notify_push_enabled) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->heading)
            ->markdown('mail.engagement-notice', [
                'recipientName' => $notifiable->name,
                'heading' => $this->heading,
                'message' => $this->message,
                'url' => $this->url(),
                'buttonLabel' => 'Ver as finanças',
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'family_finance_notice',
            'notice' => $this->kind,
            'subject_id' => $this->subjectId,
            'title' => $this->heading,
            'message' => $this->message,
            'profile_id' => $this->profileId,
        ];
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->heading)
            ->body($this->message)
            ->data(['url' => $this->url()]);
    }
}
