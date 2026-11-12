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
 * Objetivo abaixo do ritmo planejado (`GoalPaceService::notifyBehind`): no máximo um aviso por objetivo por mês. Carrega o texto já
 * pronto (o worker da fila não tem perfil ativo) e o nome do objetivo, que vai cifrado em `notifications.data` com o resto do texto.
 */
class GoalBehindPace extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $profileId,
        public string $goalId,
        public string $goalName,
        public string $message,
    ) {}

    public function title(): string
    {
        return "Objetivo abaixo do planejado: {$this->goalName}";
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
            ->subject($this->title())
            ->markdown('mail.engagement-notice', [
                'recipientName' => $notifiable->name,
                'heading' => 'Objetivo abaixo do planejado',
                'message' => "{$this->goalName}: {$this->message}",
                'url' => route('goals.index'),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'goal_behind_pace',
            'title' => $this->goalName,
            'message' => $this->message,
            'profile_id' => $this->profileId,
            'goal_id' => $this->goalId,
        ];
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('Objetivo abaixo do planejado')
            ->body("{$this->goalName}: {$this->message}")
            ->data(['url' => route('goals.index')]);
    }
}
