<?php

namespace App\Notifications;

use App\Models\HealthCareItem;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Guarda dado já resolvido, não o Eloquent model: o worker da fila reconstitui a
 * notificação sem ProfileContext ativo, e HealthCareItem falha fechado sem ele (mesmo
 * motivo de HealthAppointmentUpcoming).
 */
class HealthCareItemDue extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $itemId,
        public string $itemName,
        public ?string $deviceName,
        public string $memberName,
        public string $dueOn,
        public int $daysLeft,
    ) {}

    public static function forItem(HealthCareItem $item, CarbonImmutable $hoje): self
    {
        return new self(
            $item->id,
            $item->name,
            $item->device_name,
            $item->member->name,
            $item->next_due_on->toDateString(),
            (int) $hoje->startOfDay()->diffInDays($item->next_due_on->startOfDay(), false),
        );
    }

    /** "é hoje" ou "é amanhã". */
    public function whenLabel(): string
    {
        return match (true) {
            $this->daysLeft <= 0 => 'é hoje',
            $this->daysLeft === 1 => 'é amanhã',
            default => "é em {$this->daysLeft} dias",
        };
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
            ->subject("Cuidado de saúde: {$this->itemName} {$this->whenLabel()}")
            ->markdown('mail.health-care-item-due', [
                'recipientName' => $notifiable->name,
                'memberName' => $this->memberName,
                'itemName' => $this->itemName,
                'deviceName' => $this->deviceName,
                'whenLabel' => $this->whenLabel(),
                'dueFormatted' => CarbonImmutable::parse($this->dueOn)->format('d/m/Y'),
                'url' => route('health.care.index'),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'health_care_item_due',
            'health_care_item_id' => $this->itemId,
            'title' => $this->itemName,
            'device_name' => $this->deviceName,
            'member_name' => $this->memberName,
            'due_on' => $this->dueOn,
            'days_left' => $this->daysLeft,
        ];
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title("Cuidado de saúde — {$this->memberName}")
            ->body("{$this->itemName} {$this->whenLabel()}")
            ->data(['url' => route('health.care.index')]);
    }
}
