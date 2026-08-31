<?php

namespace App\Notifications;

use App\Models\FixedBillPayment;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Carrega dado já resolvido (não o Eloquent model) de propósito: uma
 * notificação ShouldQueue é reconstituída pelo worker sem ProfileContext
 * ativo — FixedBillPayment/FixedBill usam BelongsToProfile, cujo escopo
 * falha FECHADO sem perfil ativo (ver ProfileScope). Guardar o model aqui
 * faria o worker reidratar `payment` como null e quebrar na entrega.
 */
class FixedBillDueSoon extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $paymentId,
        public string $billName,
        public string $amount,
        public string $dueDate,
    ) {}

    public static function forPayment(FixedBillPayment $payment): self
    {
        return new self($payment->id, $payment->fixedBill->name, $payment->fixedBill->amount, $payment->due_date->toDateString());
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
            ->subject("Vence em breve: {$this->billName}")
            ->markdown('mail.fixed-bill-due-soon', [
                'recipientName' => $notifiable->name,
                'billName' => $this->billName,
                'dueDateFormatted' => CarbonImmutable::parse($this->dueDate)->format('d/m'),
                'amountFormatted' => Money::format($this->amount),
                'url' => route('fixedbills.index'),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'fixed_bill_due_soon',
            'fixed_bill_payment_id' => $this->paymentId,
            'title' => $this->billName,
            'due_date' => $this->dueDate,
            'amount' => $this->amount,
        ];
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('Conta vence em breve')
            ->body("{$this->billName} — ".Money::format($this->amount))
            ->data(['url' => route('fixedbills.index')]);
    }
}
