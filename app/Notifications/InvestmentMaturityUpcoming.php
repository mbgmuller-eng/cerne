<?php

namespace App\Notifications;

use App\Models\InvestmentRecord;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Investimento de renda fixa com vencimento cadastrado se aproximando — só
 * pro CONSULTOR: corretor não tem acesso a nenhuma tela de investimento
 * (mesma régua de PortfolioInvestments/InvestmentsIndex), então nunca é
 * despachada pra ele.
 */
class InvestmentMaturityUpcoming extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $investmentId,
        public string $displayName,
        public string $maturityDate,
        public string $currentAmount,
    ) {}

    public static function forInvestment(InvestmentRecord $investment): self
    {
        return new self($investment->id, $investment->displayName(), $investment->maturity_date->toDateString(), $investment->current_amount);
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
            ->subject("Investimento vencendo: {$this->displayName}")
            ->markdown('mail.investment-maturity-upcoming', [
                'recipientName' => $notifiable->name,
                'displayName' => $this->displayName,
                'maturityDateFormatted' => Carbon::parse($this->maturityDate)->format('d/m/Y'),
                'amountFormatted' => Money::format($this->currentAmount),
                'url' => route('consultant.portfolio.important-dates'),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'investment_maturity_upcoming',
            'investment_id' => $this->investmentId,
            'title' => $this->displayName,
            'maturity_date' => $this->maturityDate,
        ];
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('Investimento vencendo')
            ->body("{$this->displayName} vence em ".Carbon::parse($this->maturityDate)->format('d/m/Y'))
            ->data(['url' => route('consultant.portfolio.important-dates')]);
    }
}
