<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Resumo diário do que pede atenção nos leads do profissional: cotações vencendo, contatos sem retorno e próximos
 * passos atrasados. Carrega só números, sem model: o worker da fila não tem contexto de perfil.
 */
class LeadFollowUpDigest extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $quotesExpiring,
        public int $staleLeads,
        public int $overdueActions,
    ) {}

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

    /** As linhas do resumo, só as que têm algo a dizer. @return list<string> */
    public function lines(): array
    {
        return self::linesFor($this->quotesExpiring, $this->staleLeads, $this->overdueActions);
    }

    /** @return list<string> */
    public static function linesFor(int $quotesExpiring, int $staleLeads, int $overdueActions): array
    {
        return array_values(array_filter([
            $quotesExpiring > 0 ? $quotesExpiring.($quotesExpiring === 1 ? ' cotação vence' : ' cotações vencem').' nos próximos dias' : null,
            $staleLeads > 0 ? $staleLeads.($staleLeads === 1 ? ' contato está' : ' contatos estão').' sem retorno há dias' : null,
            $overdueActions > 0 ? $overdueActions.($overdueActions === 1 ? ' próximo passo está atrasado' : ' próximos passos estão atrasados') : null,
        ]));
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Leads que pedem atenção hoje')
            ->markdown('mail.lead-follow-up-digest', [
                'recipientName' => $notifiable->name,
                'lines' => $this->lines(),
                'url' => route('consultant.leads'),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'lead_follow_up_digest',
            'title' => 'Leads que pedem atenção',
            'quotes_expiring' => $this->quotesExpiring,
            'stale_leads' => $this->staleLeads,
            'overdue_actions' => $this->overdueActions,
        ];
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('Leads que pedem atenção')
            ->body(implode('; ', $this->lines()))
            ->data(['url' => route('consultant.leads')]);
    }
}
