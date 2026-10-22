<?php

namespace App\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Fulano pediu que você leve Maria à consulta": avisa quem foi escolhido para levar a pessoa, na hora, sem esperar o lembrete do dia anterior.
 * Carrega o texto pronto: o worker da fila não tem perfil ativo. O link passa por `care.goto`, que escolhe a pessoa antes de abrir a agenda.
 */
class HealthEscortAssigned extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $profileId,
        public string $appointmentId,
        public string $assignedBy,
        public string $memberName,
        public string $title,
        public string $scheduledAt,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $notifiable->notify_email_enabled ? ['database', 'mail'] : ['database'];
    }

    private function when(): string
    {
        return CarbonImmutable::parse($this->scheduledAt)->format('d/m \à\s H:i');
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->assignedBy} pediu que você leve {$this->memberName} à consulta")
            ->markdown('mail.engagement-notice', [
                'recipientName' => $notifiable->name,
                'heading' => 'Você vai levar '.$this->memberName,
                'message' => "{$this->assignedBy} combinou que você leva {$this->memberName} a: {$this->title}, em {$this->when()}. Você recebe o lembrete no dia anterior.",
                'url' => route('care.goto', ['profile' => $this->profileId, 'destino' => 'agenda']),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'health_escort_assigned',
            'health_appointment_id' => $this->appointmentId,
            'title' => "Você vai levar {$this->memberName}: {$this->title}",
            'scheduled_at' => $this->scheduledAt,
        ];
    }
}
