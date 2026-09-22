<?php

namespace App\Notifications;

use App\Models\InsurancePolicy;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Apólice com vencimento FIXO (expiry_date preenchido) se aproximando —
 * diferente da renovação anual (InsurancePolicyAnniversaryUpcoming), aqui é
 * um fim de vigência de verdade, sem continuidade automática. Mesmo
 * alcance: consultor + corretor daquela apólice (broker_id).
 */
class InsurancePolicyExpiringUpcoming extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $policyId,
        public string $insurerName,
        public ?string $personLabel,
        public string $expiryDate,
        public string $currentPremium,
    ) {}

    public static function forPolicy(InsurancePolicy $policy): self
    {
        return new self($policy->id, $policy->insurer_name, $policy->personLabel(), $policy->expiry_date->toDateString(), $policy->monthly_premium);
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
            ->subject("Apólice vencendo: {$this->insurerName}")
            ->markdown('mail.insurance-policy-expiring-upcoming', [
                'recipientName' => $notifiable->name,
                'insurerName' => $this->insurerName,
                'personLabel' => $this->personLabel,
                'expiryDateFormatted' => Carbon::parse($this->expiryDate)->format('d/m/Y'),
                'premiumFormatted' => Money::format($this->currentPremium),
                'url' => route('consultant.portfolio.important-dates'),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'insurance_policy_expiring_upcoming',
            'insurance_policy_id' => $this->policyId,
            'title' => $this->insurerName,
            'expiry_date' => $this->expiryDate,
        ];
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('Apólice vencendo')
            ->body("{$this->insurerName}".($this->personLabel ? " ({$this->personLabel})" : '')." vence em ".Carbon::parse($this->expiryDate)->format('d/m/Y'))
            ->data(['url' => route('consultant.portfolio.important-dates')]);
    }
}
