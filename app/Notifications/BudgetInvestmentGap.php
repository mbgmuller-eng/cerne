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
 * "Faltam R$ X para a meta de investimentos do mês" (`BudgetGapNotifier`): um aviso por pessoa por mês, a partir do dia 20. Carrega o texto já pronto
 * (o worker da fila não tem perfil ativo); o valor é o que ESSA pessoa enxerga, calculado sob o contexto dela.
 */
class BudgetInvestmentGap extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $profileId,
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
                'url' => route('budget.index'),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'budget_investment_gap',
            'title' => $this->title,
            'message' => $this->message,
            'profile_id' => $this->profileId,
        ];
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title)
            ->body($this->message)
            ->data(['url' => route('budget.index')]);
    }
}
