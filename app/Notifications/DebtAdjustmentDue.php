<?php

namespace App\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Chegou a data do reajuste da carta do consórcio: a pessoa informa o percentual do índice (o Cerne não busca o índice sozinho).
 * Carrega dado já resolvido, não o model (o worker da fila não tem perfil ativo).
 */
class DebtAdjustmentDue extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $profileId,
        public string $debtId,
        public string $title,
        public string $indexLabel,
        public string $dueDate,
    ) {}

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

    public function message(): string
    {
        return "{$this->title}: informe o percentual do {$this->indexLabel} (reajuste previsto para ".CarbonImmutable::parse($this->dueDate)->format('d/m/Y').').';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Reajuste do consórcio: {$this->title}")
            ->markdown('mail.engagement-notice', [
                'recipientName' => $notifiable->name,
                'heading' => 'Hora de reajustar a carta do consórcio',
                'message' => $this->message(),
                'url' => route('debts.show', $this->debtId),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'debt_adjustment_due',
            'title' => $this->title,
            'message' => $this->message(),
            'profile_id' => $this->profileId,
            'debt_id' => $this->debtId,
            'due_date' => $this->dueDate,
        ];
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('Reajuste do consórcio')
            ->body($this->message())
            ->data(['url' => route('debts.show', $this->debtId)]);
    }
}
