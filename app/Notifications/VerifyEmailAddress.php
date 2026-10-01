<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Versão com a cara do Cerne da verificação padrão do framework — mesmo
 * link assinado por baixo (URL::temporarySignedRoute), só troca o
 * template genérico pelo nosso. Ver User::sendEmailVerificationNotification().
 */
class VerifyEmailAddress extends Notification implements ShouldQueue
{
    use Queueable;

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(config('auth.verification.expire', 60)),
            ['id' => $notifiable->getKey(), 'hash' => sha1($notifiable->getEmailForVerification())],
        );

        return (new MailMessage)
            ->subject('Confirme seu e-mail — Cerne')
            ->markdown('mail.verify-email', [
                'name' => $notifiable->name,
                'url' => $url,
            ]);
    }
}
