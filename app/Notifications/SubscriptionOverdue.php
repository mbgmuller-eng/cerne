<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A cobrança venceu sem pagamento. Sempre por e-mail, sem checar
 * notify_email_enabled: é aviso de cobrança, não notificação opcional.
 */
class SubscriptionOverdue extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $bundleLabel,
        public string $dueDateFormatted,
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
            ->subject('Sua assinatura do Cerne está em atraso')
            ->markdown('mail.subscription-overdue', [
                'name' => $notifiable->name,
                'bundleLabel' => $this->bundleLabel,
                'dueDateFormatted' => $this->dueDateFormatted,
                'accessUntilFormatted' => $this->accessUntilFormatted,
                'invoiceUrl' => $this->invoiceUrl,
                'url' => route('subscription.index'),
            ]);
    }
}
