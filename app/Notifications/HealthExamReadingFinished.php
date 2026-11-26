<?php

namespace App\Notifications;

use App\Enums\ProcessingStatus;
use App\Models\HealthExamReading;
use App\Support\CareLinks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * A leitura de um laudo terminou (falta revisar) ou falhou. Vai só para quem enviou. Guarda o dado já resolvido, não o model (o worker não tem
 * perfil ativo), e não leva o nome do arquivo nem do exame: dizem demais sobre a saúde para um e-mail ou um aviso no aparelho.
 */
class HealthExamReadingFinished extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $readingId,
        public string $memberName,
        public bool $success,
        public ?string $careProfileId = null,
        public ?string $profileId = null,
    ) {}

    public static function forReading(HealthExamReading $leitura, string $nomeDaPessoa): self
    {
        return new self(
            $leitura->id,
            $nomeDaPessoa,
            $leitura->status === ProcessingStatus::Completed,
            CareLinks::careProfileId($leitura->profile_id),
            $leitura->profile_id,
        );
    }

    private function heading(): string
    {
        return $this->success ? 'Laudo lido: falta revisar' : 'Não deu para ler o laudo';
    }

    private function link(?object $notifiable = null): string
    {
        return CareLinks::to($this->careProfileId, 'exames', 'health.exams.index', $this->profileId, $notifiable);
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $canais = ['database'];

        if ($notifiable->notify_email_enabled) {
            $canais[] = 'mail';
        }

        if ($notifiable->notify_push_enabled) {
            $canais[] = WebPushChannel::class;
        }

        return $canais;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->heading())
            ->markdown('mail.health-exam-reading', [
                'recipientName' => $notifiable->name,
                'memberName' => $this->memberName,
                'success' => $this->success,
                'url' => $this->link($notifiable),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'health_exam_reading',
            'health_exam_reading_id' => $this->readingId,
            'title' => $this->heading(),
            'member_name' => $this->memberName,
            'success' => $this->success,
        ];
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        return (new WebPushMessage)->title('Cerne Saúde')->body($this->heading())->data(['url' => $this->link($notifiable)]);
    }
}
