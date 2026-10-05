<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Último dia de carência: amanhã o acesso é cortado. Sempre por e-mail,
 * sem checar notify_email_enabled: é aviso de cobrança, não notificação
 * opcional.
 */
class SubscriptionAccessEnding extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $bundleLabel,
        public string $cutoffFormatted,
        public ?string $invoiceUrl,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Seu acesso ao Cerne será encerrado amanhã')
            ->markdown('mail.subscription-access-ending', [
                'name' => $notifiable->name,
                'bundleLabel' => $this->bundleLabel,
                'cutoffFormatted' => $this->cutoffFormatted,
                'invoiceUrl' => $this->invoiceUrl,
                'url' => route('subscription.index'),
            ]);
    }
}
