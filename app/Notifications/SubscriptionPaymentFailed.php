<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Cartão recusado na cobrança da assinatura. Sempre por e-mail, sem checar
 * notify_email_enabled: é aviso de cobrança, não notificação opcional.
 */
class SubscriptionPaymentFailed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $bundleLabel,
        public string $accessUntilFormatted,
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
            ->subject('Não conseguimos cobrar sua assinatura do Cerne')
            ->markdown('mail.subscription-payment-failed', [
                'name' => $notifiable->name,
                'bundleLabel' => $this->bundleLabel,
                'accessUntilFormatted' => $this->accessUntilFormatted,
                'invoiceUrl' => $this->invoiceUrl,
                'url' => route('subscription.index'),
            ]);
    }
}
