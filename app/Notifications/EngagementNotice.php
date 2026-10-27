<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Aviso do Acompanhamento (reunião, tarefa, demanda, lembrete). Carrega só texto já pronto e o perfil, sem model: o worker da
 * fila não tem contexto de perfil, e o clique no sino abre o perfil certo antes de ir para a tela.
 */
class EngagementNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $profileId,
        public string $kind,
        public string $heading,
        public string $message,
        /** Só para reunião: `consultant_id`, `parties` (consultant, client), `details` (rótulo e valor) e `meeting_id`. */
        public array $extra = [],
    ) {}

    /** "{outro}" vira o nome de quem está do outro lado: o cliente para o consultor, o consultor para o cliente. */
    private function text(string $texto, object $notifiable): string
    {
        if (! str_contains($texto, '{outro}')) {
            return $texto;
        }

        $parties = $this->extra['parties'] ?? [];
        $outro = ($this->extra['consultant_id'] ?? null) === $notifiable->id ? ($parties['client'] ?? 'o cliente') : ($parties['consultant'] ?? 'o consultor');

        return str_replace('{outro}', $outro, $texto);
    }

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
        $heading = $this->text($this->heading, $notifiable);

        return (new MailMessage)
            ->subject($heading)
            ->markdown('mail.engagement-notice', [
                'recipientName' => $notifiable->name,
                'heading' => $heading,
                'message' => $this->text($this->message, $notifiable),
                'details' => $this->extra['details'] ?? [],
                'calendarUrl' => isset($this->extra['meeting_id']) ? route('engagement.meeting.ics', $this->extra['meeting_id']) : null,
                'url' => route('engagement.index'),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'engagement_notice',
            'notice' => $this->kind,
            'title' => $this->text($this->heading, $notifiable),
            'message' => $this->text($this->message, $notifiable),
            'profile_id' => $this->profileId,
        ];
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->text($this->heading, $notifiable))
            ->body($this->text($this->message, $notifiable))
            ->data(['url' => route('engagement.index')]);
    }
}
