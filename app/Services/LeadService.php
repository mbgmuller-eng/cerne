<?php

namespace App\Services;

use App\Enums\LeadActivityType;
use App\Enums\LeadStage;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Lead é a etapa antes de qualquer coisa existir de verdade: sem perfil,
 * sem convite, às vezes só um nome e um telefone. Converter não duplica a
 * lógica de convite — reaproveita ClientInviteService::send(), que já
 * lida com token, e-mail e o vínculo consultor↔cliente.
 */
class LeadService
{
    public function __construct(
        private readonly ClientInviteService $invites,
    ) {}

    /**
     * @param  array<string, mixed>  $dados
     *
     * Estágio default explícito aqui em vez de confiar no DEFAULT da
     * coluna: o valor de volta de create() é o que o resto do código (e a
     * tela) usa na hora — um DEFAULT só de banco fica invisível no model
     * recém-criado até um refresh().
     */
    public function create(array $dados): Lead
    {
        $dados['stage'] ??= LeadStage::NewContact->value;

        return Lead::create($dados);
    }

    /** @param  array<string, mixed>  $dados */
    public function update(Lead $lead, array $dados): Lead
    {
        $lead->update($dados);

        return $lead;
    }

    public function logActivity(
        Lead $lead,
        LeadActivityType $type,
        ?string $description,
        CarbonImmutable $occurredAt,
        ?string $userId,
    ): LeadActivity {
        return LeadActivity::create([
            'lead_id' => $lead->id,
            'type' => $type,
            'description' => $description,
            'occurred_at' => $occurredAt,
            'created_by_user_id' => $userId,
        ]);
    }

    public function markLost(Lead $lead, string $reason): Lead
    {
        $lead->update([
            'stage' => LeadStage::Lost,
            'lost_reason' => $reason,
        ]);

        return $lead;
    }

    /**
     * Emite o convite de cliente de verdade e marca o lead como convertido.
     * A partir daqui quem manda é o fluxo de sempre (ConsultantInvite →
     * ClientOnboardingService::acceptInvite()) — o lead só guarda o
     * histórico de como o contato começou.
     *
     * @return string o link do convite, mesmo retorno de ClientInviteService::send()
     */
    public function convert(Lead $lead, User $consultant): string
    {
        if (blank($lead->email)) {
            throw new InvalidArgumentException('Lead sem e-mail não pode ser convertido — o convite precisa de um e-mail pra enviar.');
        }

        $link = $this->invites->send($consultant, $lead->name, $lead->email);

        $lead->update(['stage' => LeadStage::Converted]);

        return $link;
    }
}
