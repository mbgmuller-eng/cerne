<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso à PESSOA quando o consultor, em nome dela (convite assistido), libera o acesso de um familiar à saúde e/ou às finanças dela. Ela sempre sabe
 * quem enxerga o quê, e encerra o acesso quando quiser (Pessoas e acessos).
 */
class FamilyAccessGranted extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $consultantName,
        public string $relativeName,
        public string $scopeText,
        public string $basisText,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $notifiable->notify_email_enabled ? ['database', 'mail'] : ['database'];
    }

    private function message(): string
    {
        return "{$this->consultantName} liberou o acesso de {$this->relativeName} a {$this->scopeText}. Base: {$this->basisText}. Se você não reconhece, encerre o acesso em Pessoas e acessos.";
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Novo acesso à sua conta: {$this->relativeName}")
            ->markdown('mail.engagement-notice', [
                'recipientName' => $notifiable->name,
                'heading' => "{$this->relativeName} agora acompanha você",
                'message' => $this->message(),
                'url' => route('care.index'),
                'buttonLabel' => 'Ver quem tem acesso',
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'family_access_granted',
            'title' => "{$this->relativeName} agora acompanha você",
            'message' => $this->message(),
        ];
    }
}