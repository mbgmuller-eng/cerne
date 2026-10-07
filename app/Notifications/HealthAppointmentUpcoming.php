<?php

namespace App\Notifications;

use App\Models\HealthAppointment;
use App\Support\MapLinks;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Guarda dado já resolvido, não o Eloquent model — mesmo motivo de
 * FixedBillDueSoon: o worker da fila reconstitui a notificação sem
 * ProfileContext ativo, e HealthAppointment falha fechado sem ele.
 */
class HealthAppointmentUpcoming extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $appointmentId,
        public string $kindLabel,
        public string $title,
        public ?string $location,
        public string $scheduledAt,
        public string $memberName,
        public ?string $address = null,
        public ?string $phone = null,
        public ?string $bookedWith = null,
    ) {}

    public static function forAppointment(HealthAppointment $appointment): self
    {
        return new self(
            $appointment->id,
            $appointment->kind->label(),
            $appointment->title,
            $appointment->location,
            $appointment->scheduled_at->toIso8601String(),
            $appointment->member->name,
            $appointment->address,
            $appointment->phone,
            $appointment->booked_with_name,
        );
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
        $quando = CarbonImmutable::parse($this->scheduledAt);
        $destino = MapLinks::destination($this->location, $this->address);

        return (new MailMessage)
            ->subject("{$this->kindLabel} em breve: {$this->title}")
            ->markdown('mail.health-appointment-upcoming', [
                'recipientName' => $notifiable->name,
                'memberName' => $this->memberName,
                'kindLabel' => $this->kindLabel,
                'title' => $this->title,
                'location' => $this->location,
                'address' => $this->address,
                'phone' => $this->phone,
                'bookedWith' => $this->bookedWith,
                'mapsUrl' => $destino ? MapLinks::googleMaps($destino) : null,
                'wazeUrl' => $destino ? MapLinks::waze($destino) : null,
                'dateFormatted' => $quando->format('d/m'),
                'timeFormatted' => $quando->format('H:i'),
                'url' => route('health.appointments.index'),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'health_appointment_upcoming',
            'health_appointment_id' => $this->appointmentId,
            'title' => "{$this->kindLabel}: {$this->title}",
            'member_name' => $this->memberName,
            'scheduled_at' => $this->scheduledAt,
        ];
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        $quando = CarbonImmutable::parse($this->scheduledAt);

        return (new WebPushMessage)
            ->title("{$this->kindLabel} em breve — {$this->memberName}")
            ->body("{$this->title} em {$quando->format('d/m')} às {$quando->format('H:i')}")
            ->data(['url' => route('health.appointments.index')]);
    }
}
