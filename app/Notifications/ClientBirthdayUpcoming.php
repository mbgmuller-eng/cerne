<?php

namespace App\Notifications;

use App\Models\ProfileMember;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Aniversário de titular ou cônjuge chegando — vai pra TODO profissional
 * vinculado ao cliente (consultor e qualquer corretor), não só quem cuida
 * de seguro: é dado de relacionamento, não dado de apólice.
 *
 * Carrega dado já resolvido, não o Eloquent model — mesmo motivo de
 * FixedBillDueSoon: o worker da fila não tem ProfileContext ativo.
 */
class ClientBirthdayUpcoming extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $memberId,
        public string $memberName,
        public string $occurrenceDate,
        public int $turningAge,
    ) {}

    public static function forMember(ProfileMember $member, Carbon $occurrence, int $turningAge): self
    {
        return new self($member->id, $member->name, $occurrence->toDateString(), $turningAge);
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
            ->subject("Aniversário chegando: {$this->memberName}")
            ->markdown('mail.client-birthday-upcoming', [
                'recipientName' => $notifiable->name,
                'memberName' => $this->memberName,
                'occurrenceDateFormatted' => Carbon::parse($this->occurrenceDate)->format('d/m'),
                'turningAge' => $this->turningAge,
                'url' => route('consultant.portfolio.important-dates'),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'client_birthday_upcoming',
            'member_id' => $this->memberId,
            'title' => $this->memberName,
            'occurrence_date' => $this->occurrenceDate,
            'turning_age' => $this->turningAge,
        ];
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('Aniversário chegando')
            ->body("{$this->memberName} completa {$this->turningAge} anos em ".Carbon::parse($this->occurrenceDate)->format('d/m'))
            ->data(['url' => route('consultant.portfolio.important-dates')]);
    }
}
