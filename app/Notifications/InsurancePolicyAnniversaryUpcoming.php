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
 * Apólice completando mais um ano de vigência (renovação) — vai só pro
 * consultor e pro corretor DAQUELA apólice específica (broker_id), nunca
 * corretor de outro produto do mesmo cliente.
 */
class InsurancePolicyAnniversaryUpcoming extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $policyId,
        public string $insurerName,
        public ?string $personLabel,
        public string $occurrenceDate,
        public int $yearsCompleting,
        public string $currentPremium,
    ) {}

    public static function forPolicy(InsurancePolicy $policy, Carbon $occurrence, int $yearsCompleting): self
    {
        return new self(
            $policy->id, $policy->insurer_name, $policy->personLabel(),
            $occurrence->toDateString(), $yearsCompleting, $policy->monthly_premium,
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
            ->subject("Apólice renovando: {$this->insurerName}")
            ->markdown('mail.insurance-policy-anniversary-upcoming', [
                'recipientName' => $notifiable->name,
                'insurerName' => $this->insurerName,
                'personLabel' => $this->personLabel,
                'occurrenceDateFormatted' => Carbon::parse($this->occurrenceDate)->format('d/m'),
                'yearsCompleting' => $this->yearsCompleting,
                'premiumFormatted' => Money::format($this->currentPremium),
                'url' => route('consultant.portfolio.important-dates'),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'insurance_policy_anniversary_upcoming',
            'insurance_policy_id' => $this->policyId,
            'title' => $this->insurerName,
            'occurrence_date' => $this->occurrenceDate,
            'years_completing' => $this->yearsCompleting,
        ];
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('Apólice renovando')
            ->body("{$this->insurerName}".($this->personLabel ? " ({$this->personLabel})" : '')." completa {$this->yearsCompleting} anos em ".Carbon::parse($this->occurrenceDate)->format('d/m'))
            ->data(['url' => route('consultant.portfolio.important-dates')]);
    }
}
