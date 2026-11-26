<?php

namespace App\Notifications;

use App\Models\HealthRoutine;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/** Rotina preventiva (check-up) vencendo. Guarda o dado já resolvido, não o model (o worker não tem perfil ativo; ver HealthCareItemDue). */
class HealthRoutineDue extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $routineId,
        public string $routineName,
        public string $memberName,
        public string $dueOn,
        public int $daysLeft,
        public ?string $careProfileId = null,
        public ?string $profileId = null,
    ) {}

    public static function forRoutine(HealthRoutine $rotina, CarbonImmutable $hoje): self
    {
        return new self(
            $rotina->id,
            $rotina->name,
            $rotina->member->name,
            $rotina->next_due_on->toDateString(),
            (int) $hoje->startOfDay()->diffInDays($rotina->next_due_on->startOfDay(), false),
            \App\Support\CareLinks::careProfileId($rotina->profile_id),
            $rotina->profile_id,
        );
    }

    private function link(?object $notifiable = null): string
    {
        return \App\Support\CareLinks::to($this->careProfileId, 'rotinas', 'health.routines.index', $this->profileId, $notifiable);
    }

    public function whenLabel(): string
    {
        return $this->daysLeft <= 0 ? 'vence hoje' : "vence em {$this->daysLeft} dias";
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
        // O nome da rotina pode dizer algo sobre a saúde (ex.: uma especialidade): o assunto do e-mail não o leva.
        return (new MailMessage)
            ->subject('Rotina de saúde para marcar')
            ->markdown('mail.health-routine-due', [
                'recipientName' => $notifiable->name,
                'memberName' => $this->memberName,
                'routineName' => $this->routineName,
                'whenLabel' => $this->whenLabel(),
                'dueFormatted' => CarbonImmutable::parse($this->dueOn)->format('d/m/Y'),
                'url' => $this->link($notifiable),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'health_routine_due',
            'health_routine_id' => $this->routineId,
            'title' => $this->routineName,
            'member_name' => $this->memberName,
            'due_on' => $this->dueOn,
            'days_left' => $this->daysLeft,
        ];
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('Rotina de saúde')
            ->body("{$this->memberName}: uma rotina {$this->whenLabel()}")
            ->data(['url' => $this->link($notifiable)]);
    }
}
