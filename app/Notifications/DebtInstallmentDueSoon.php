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
 * Parcela de financiamento ou empréstimo a vencer. Carrega dado já resolvido, não o model: o worker da fila não tem perfil
 * ativo e o model falharia fechado (mesmo motivo de FixedBillDueSoon).
 */
class DebtInstallmentDueSoon extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $profileId,
        public string $debtId,
        public string $installmentId,
        public string $title,
        public string $amount,
        public string $dueDate,
    ) {}

    public static function forInstallment(DebtInstallment $parcela): self
    {
        $divida = $parcela->loadMissing('debt')->debt;

        return new self(
            $parcela->profile_id, $divida->id, $parcela->id,
            "{$divida->name} · parcela {$parcela->number}/{$divida->term}",
            (string) $parcela->amount, $parcela->due_date->toDateString(),
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
        return (new MailMessage)
            ->subject("Vence em breve: {$this->title}")
            ->markdown('mail.debt-installment-due-soon', [
                'recipientName' => $notifiable->name,
                'title' => $this->title,
                'amount' => Money::format($this->amount),
                'dueDate' => CarbonImmutable::parse($this->dueDate)->format('d/m/Y'),
                'url' => route('debts.show', $this->debtId),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'debt_installment_due_soon',
            'title' => $this->title,
            'profile_id' => $this->profileId,
            'debt_id' => $this->debtId,
            'debt_installment_id' => $this->installmentId,
            'amount' => $this->amount,
            'due_date' => $this->dueDate,
        ];
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('Parcela a vencer')
            ->body("{$this->title} vence em ".CarbonImmutable::parse($this->dueDate)->format('d/m').' ('.Money::format($this->amount).')')
            ->data(['url' => route('debts.show', $this->debtId)]);
    }
}
