<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * "Hora de renovar o treino": a fase de treino passou do ponto de troca (tempo para o objetivo ou exercícios parados). Um aviso por
 * fase, só para o dono (dado de saúde pessoal). Carrega o texto já pronto: o worker da fila não tem perfil ativo.
 */
class GymPlanReviewDue extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $profileId,
        public string $planId,
        public string $title,
        public string $message,
    ) {}

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
            ->subject($this->title)
            ->markdown('mail.engagement-notice', [
                'recipientName' => $notifiable->name,
                'heading' => $this->title,
                'message' => $this->message,
                'url' => route('health.gym.generate'),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'gym_plan_review_due',
            'title' => $this->title,
            'message' => $this->message,
            'profile_id' => $this->profileId,
            'gym_plan_id' => $this->planId,
        ];
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title)
            ->body($this->message)
            ->data(['url' => route('health.gym.generate')]);
    }
}
