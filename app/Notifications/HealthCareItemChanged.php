<?php

namespace App\Notifications;

use App\Enums\HealthCareItemEvent;
use App\Models\HealthCareItem;
use App\Support\CareLinks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Aviso a quem acompanha a pessoa quando um item de cuidado muda: cadastrado, alterado, troca registrada, pausado, retomado ou removido.
 * Quem fez a mudança não recebe o próprio aviso. Carrega o texto pronto: o worker da fila não tem perfil ativo e o item pode já ter mudado.
 */
class HealthCareItemChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $profileId,
        public ?string $careProfileId,
        public string $itemId,
        public string $event,
        public string $heading,
        public string $message,
        public string $memberName,
    ) {}

    /**
     * @param  array{changed?: list<string>, done_on?: string, next_due?: ?string}  $extra
     */
    public static function make(HealthCareItem $item, HealthCareItemEvent $evento, string $quemFez, array $extra = []): self
    {
        $pessoa = $item->member->name;
        $nome = $item->name.($item->device_name ? " ({$item->device_name})" : '');
        $proxima = isset($extra['next_due']) ? ' Próxima em '.date('d/m/Y', strtotime($extra['next_due'])).'.' : '';

        $mensagem = match ($evento) {
            HealthCareItemEvent::Created => "{$quemFez} cadastrou {$nome} de {$pessoa}: {$item->frequencyLabel()}.".$proxima,
            HealthCareItemEvent::Updated => "{$quemFez} alterou {$nome} de {$pessoa} (".implode(', ', $extra['changed'] ?? ['dados']).').'.$proxima,
            HealthCareItemEvent::Done => "{$quemFez} registrou a troca de {$nome} de {$pessoa}".(isset($extra['done_on']) ? ' em '.date('d/m/Y', strtotime($extra['done_on'])) : '').'.'.$proxima,
            HealthCareItemEvent::Paused => "{$quemFez} pausou {$nome} de {$pessoa}. Sem lembretes até ser retomado.",
            HealthCareItemEvent::Resumed => "{$quemFez} retomou {$nome} de {$pessoa}: {$item->frequencyLabel()}.".$proxima,
            HealthCareItemEvent::Removed => "{$quemFez} removeu {$nome} de {$pessoa}.",
        };

        return new self(
            $item->profile_id,
            CareLinks::careProfileId($item->profile_id),
            $item->id,
            $evento->value,
            $evento->heading().': '.$pessoa,
            $mensagem,
            $pessoa,
        );
    }

    private function link(object $notifiable): string
    {
        return CareLinks::to($this->careProfileId, 'cuidados', 'health.care.index', $this->profileId, $notifiable);
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
        return (new MailMessage)
            ->subject($this->heading)
            ->markdown('mail.engagement-notice', [
                'recipientName' => $notifiable->name,
                'heading' => $this->heading,
                'message' => $this->message,
                'url' => $this->link($notifiable),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'health_care_item_changed',
            'event' => $this->event,
            'health_care_item_id' => $this->itemId,
            'title' => $this->heading,
            'message' => $this->message,
            'member_name' => $this->memberName,
        ];
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->heading)
            ->body($this->message)
            ->data(['url' => $this->link($notifiable)]);
    }
}
