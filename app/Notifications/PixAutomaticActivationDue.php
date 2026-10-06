<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Quem escolheu Pix Automático ainda não autorizou o débito e o teste grátis
 * está acabando. Sempre por e-mail, sem checar notify_email_enabled: é aviso
 * de cobrança, não notificação opcional.
 */
class PixAutomaticActivationDue extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $bundleLabel,
        public string $trialEndsFormatted,
        public string $priceFormatted,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Ative o débito automático da sua assinatura do Cerne')
            ->markdown('mail.pix-automatic-activation-due', [
                'name' => $notifiable->name,
                'bundleLabel' => $this->bundleLabel,
                'trialEndsFormatted' => $this->trialEndsFormatted,
                'priceFormatted' => $this->priceFormatted,
                'url' => route('subscription.index'),
            ]);
    }
}
