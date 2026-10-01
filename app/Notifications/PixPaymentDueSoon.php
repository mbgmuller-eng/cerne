<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Pix não tem débito automático — sem isso, ninguém saberia que precisa
 * pagar até o acesso já estar bloqueado. Sempre por e-mail, sem checar
 * notify_email_enabled: é aviso de cobrança, não notificação opcional.
 */
class PixPaymentDueSoon extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $bundleLabel,
        public string $dueDateFormatted,
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
            ->subject('Sua assinatura do Cerne vence em 3 dias')
            ->markdown('mail.pix-payment-due-soon', [
                'name' => $notifiable->name,
                'bundleLabel' => $this->bundleLabel,
                'dueDateFormatted' => $this->dueDateFormatted,
                'invoiceUrl' => $this->invoiceUrl,
                'url' => route('subscription.index'),
            ]);
    }
}
