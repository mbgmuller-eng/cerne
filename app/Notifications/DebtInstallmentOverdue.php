<?php

namespace App\Notifications;

use App\Models\DebtInstallment;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Parcela de dívida em atraso: um aviso para quem deve (no primeiro dia e de novo uma semana depois) e outro para o consultor
 * do cliente, depois de uma semana. Carrega dado já resolvido, não o model: o worker da fila não tem perfil ativo.
 */
class DebtInstallmentOverdue extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $profileId,
        public string $debtId,
        public string $installmentId,
        public string $title,
        public string $amount,
        public string $dueDate,
        public int $daysLate,
        public ?string $clientName = null,
    ) {}

    public static function forInstallment(DebtInstallment $parcela, int $diasDeAtraso, ?string $nomeDoCliente = null): self
    {
        $divida = $parcela->loadMissing('debt')->debt;

        return new self(
            $parcela->profile_id, $divida->id, $parcela->id,
            "{$divida->name} · parcela {$parcela->number}/{$divida->term}",
            (string) $parcela->amount, $parcela->due_date->toDateString(), $diasDeAtraso, $nomeDoCliente,
        );
    }

    /** Aviso do consultor: fala do cliente, não de "você". */
    public function isForConsultant(): bool
    {
        return $this->clientName !== null;
    }

    public function message(): string
    {
        $dias = $this->daysLate.($this->daysLate === 1 ? ' dia' : ' dias');
        $quando = 'venceu em '.CarbonImmutable::parse($this->dueDate)->format('d/m').', '.Money::format($this->amount);

        return $this->isForConsultant()
            ? "{$this->clientName}: {$this->title} está atrasada há {$dias} ({$quando})."
            : "{$this->title} está atrasada há {$dias} ({$quando}).";
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
            ->subject($this->isForConsultant() ? "Parcela de cliente atrasada: {$this->clientName}" : "Parcela atrasada: {$this->title}")
            ->markdown('mail.engagement-notice', [
                'recipientName' => $notifiable->name,
                'heading' => $this->isForConsultant() ? 'Parcela de cliente em atraso' : 'Parcela em atraso',
                'message' => $this->message(),
                'url' => route('debts.show', $this->debtId),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->isForConsultant() ? 'debt_installment_overdue_consultant' : 'debt_installment_overdue',
            'title' => $this->title,
            'message' => $this->message(),
            'profile_id' => $this->profileId,
            'debt_id' => $this->debtId,
            'debt_installment_id' => $this->installmentId,
            'amount' => $this->amount,
            'due_date' => $this->dueDate,
            'days_late' => $this->daysLate,
        ];
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->isForConsultant() ? 'Parcela de cliente atrasada' : 'Parcela atrasada')
            ->body($this->message())
            ->data(['url' => route('debts.show', $this->debtId)]);
    }
}
