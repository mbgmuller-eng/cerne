<?php

namespace App\Notifications;

use App\Enums\HealthAppointmentEvent;
use App\Models\HealthAppointment;
use App\Support\CareLinks;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Aviso a quem acompanha a pessoa quando algo muda numa consulta ou exame: agendada, remarcada, alterada, cancelada, confirmada como realizada
 * (ou não), removida, ou quando se define quem vai levar. Quem fez a mudança não recebe o próprio aviso.
 *
 * Carrega o texto pronto: o worker da fila não tem perfil ativo e a consulta pode já ter mudado (ou sumido) quando o aviso sair.
 */
class HealthAppointmentChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $profileId,
        public ?string $careProfileId,
        public string $appointmentId,
        public string $event,
        public string $heading,
        public string $message,
        public string $memberName,
        public string $scheduledAt,
    ) {}

    /**
     * @param  array{old_at?: string, changed?: list<string>, escort?: string, previous_escort?: string}  $extra
     */
    public static function make(HealthAppointment $c, HealthAppointmentEvent $evento, string $quemFez, array $extra = []): self
    {
        $quando = self::when($c->scheduled_at->toIso8601String());
        $pessoa = $c->member->name;
        $o = $c->title;

        $mensagem = match ($evento) {
            HealthAppointmentEvent::Created => "{$quemFez} agendou {$o} de {$pessoa}, em {$quando}.",
            HealthAppointmentEvent::Rescheduled => "{$quemFez} remarcou {$o} de {$pessoa}: era ".self::when($extra['old_at'] ?? $c->scheduled_at->toIso8601String()).", agora é {$quando}.",
            HealthAppointmentEvent::Updated => "{$quemFez} alterou {$o} de {$pessoa} (".implode(', ', $extra['changed'] ?? ['dados']).'). Data: '.$quando.'.',
            HealthAppointmentEvent::Cancelled => "{$quemFez} cancelou {$o} de {$pessoa}, que seria em {$quando}.",
            HealthAppointmentEvent::Done => "{$quemFez} confirmou que {$o} de {$pessoa} foi realizada ({$quando}).",
            HealthAppointmentEvent::NotDone => "{$quemFez} confirmou que {$o} de {$pessoa} NÃO foi realizada ({$quando}).",
            HealthAppointmentEvent::Reopened => "{$quemFez} voltou {$o} de {$pessoa} para agendada ({$quando}).",
            HealthAppointmentEvent::Removed => "{$quemFez} removeu {$o} de {$pessoa} (era em {$quando}).",
            HealthAppointmentEvent::EscortAssigned => ($extra['escort'] ?? $quemFez)." vai levar {$pessoa} a {$o}, em {$quando}.",
            HealthAppointmentEvent::EscortReleased => ($extra['previous_escort'] ?? $quemFez)." não vai mais levar {$pessoa} a {$o} ({$quando}). Ninguém está definido para levar.",
        };

        return new self(
            $c->profile_id,
            CareLinks::careProfileId($c->profile_id),
            $c->id,
            $evento->value,
            $evento->heading().': '.$pessoa,
            $mensagem,
            $pessoa,
            $c->scheduled_at->toIso8601String(),
        );
    }

    private static function when(string $iso): string
    {
        return CarbonImmutable::parse($iso)->format('d/m \à\s H:i');
    }

    private function link(object $notifiable): string
    {
        return CareLinks::to($this->careProfileId, 'agenda', 'health.appointments.index', $this->profileId, $notifiable);
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
            'kind' => 'health_appointment_changed',
            'event' => $this->event,
            'health_appointment_id' => $this->appointmentId,
            'title' => $this->heading,
            'message' => $this->message,
            'member_name' => $this->memberName,
            'scheduled_at' => $this->scheduledAt,
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
