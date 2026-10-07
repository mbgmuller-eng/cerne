<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Faltam 3 dias para o teste grátis acabar. Vai por e-mail e também aparece no
 * sino do Cerne, sempre, sem checar notify_email_enabled: é aviso de cobrança,
 * não notificação opcional, e depois do último dia o acesso trava.
 */
class TrialEndingSoon extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $bundleLabel,
        public string $lastDay,
        public string $priceFormatted,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Seu teste grátis do Cerne termina em 3 dias')
            ->markdown('mail.trial-ending-soon', [
                'name' => $notifiable->name,
                'bundleLabel' => $this->bundleLabel,
                'lastDayFormatted' => Carbon::parse($this->lastDay)->format('d/m/Y'),
                'priceFormatted' => $this->priceFormatted,
                'url' => route('subscription.index'),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'trial_ending_soon',
            'title' => 'Teste grátis terminando',
            'bundle' => $this->bundleLabel,
            'trial_last_day' => $this->lastDay,
        ];
    }
}
