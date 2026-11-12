<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-mail de redefinição de senha, com a cara do Cerne. O link leva SÓ o token: o e-mail da conta não vai na URL (endereço
 * em query string cai em log de servidor, histórico e cabeçalho Referer); a pessoa o digita na tela de nova senha.
 */
class ResetPasswordLink extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutos = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

        return (new MailMessage)
            ->subject('Redefinir sua senha — Cerne')
            ->markdown('mail.reset-password', [
                'name' => $notifiable->name,
                'url' => route('password.reset', ['token' => $this->token]),
                'minutos' => $minutos,
            ]);
    }
}
