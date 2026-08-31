<?php

namespace App\Notifications;

use App\Models\CreditCardInvoice;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Mesmo raciocínio de FixedBillDueSoon: dado já resolvido, não o Eloquent
 * model — CreditCardInvoice/CreditCard usam BelongsToProfile, que falha
 * fechado sem ProfileContext ativo (a situação de um worker de fila real).
 */
class CreditCardInvoiceDueSoon extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $invoiceId,
        public string $cardDisplayName,
        public string $amount,
        public string $dueDate,
    ) {}

    public static function forInvoice(CreditCardInvoice $invoice): self
    {
        return new self($invoice->id, $invoice->creditCard->displayName(), $invoice->total_amount, $invoice->due_date->toDateString());
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
            ->subject("Fatura vence em breve: {$this->cardDisplayName}")
            ->line("A fatura do cartão \"{$this->cardDisplayName}\" vence em ".CarbonImmutable::parse($this->dueDate)->format('d/m').'.')
            ->line('Valor: '.Money::format($this->amount))
            ->action('Ver fatura', route('invoices.show', $this->invoiceId));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'credit_card_invoice_due_soon',
            'credit_card_invoice_id' => $this->invoiceId,
            'title' => $this->cardDisplayName,
            'due_date' => $this->dueDate,
            'amount' => $this->amount,
        ];
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('Fatura vence em breve')
            ->body("{$this->cardDisplayName} — ".Money::format($this->amount))
            ->data(['url' => route('invoices.show', $this->invoiceId)]);
    }
}
