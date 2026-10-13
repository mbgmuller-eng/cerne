<?php

namespace App\Models;

use App\Enums\LeadKind;
use App\Enums\LeadLostReason;
use App\Enums\LeadSource;
use App\Enums\LeadStage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Contato que ainda não é cliente — a etapa que falta antes de
 * ConsultantInvite/ConsultantClient (ver LeadService::convert()). Não
 * pertence a um perfil (não existe perfil nenhum ainda), só ao consultor
 * dono do relacionamento — ver TenancyCoverageTest::EXEMPT.
 *
 * Anda em um de dois funis (`kind`): consultoria ou seguros. O de seguros guarda o que se precisa para cotar
 * (interesses e dados por tipo) e as cotações feitas (LeadQuote), cada uma com a simulação em PDF.
 */
#[Fillable([
    'consultant_id', 'client_profile_id', 'source_policy_id', 'automation_key', 'kind', 'name', 'email', 'phone', 'interests', 'quote_data', 'birth_date', 'source', 'source_detail',
    'stage', 'notes', 'lost_reason', 'lost_category', 'lost_at', 'converted_at', 'next_action_at',
])]
class Lead extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'kind' => LeadKind::class,
            'stage' => LeadStage::class,
            'source' => LeadSource::class,
            'lost_category' => LeadLostReason::class,
            'interests' => 'array',
            'quote_data' => 'array',
            'birth_date' => 'date',
            'lost_at' => 'datetime',
            'converted_at' => 'datetime',
            'next_action_at' => 'datetime',
        ];
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consultant_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(LeadQuote::class)->orderBy('created_at');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(LeadAttachment::class);
    }

    /** Ainda em andamento — não convertido nem perdido. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('stage', [LeadStage::Converted->value, LeadStage::Lost->value]);
    }

    /** Lead que o Cerne criou a partir de um cliente que o profissional já atende (renovação, venda cruzada). */
    public function isClientDeal(): bool
    {
        return $this->client_profile_id !== null;
    }

    public function stageLabel(): string
    {
        return $this->stage->labelFor($this->kind ?? LeadKind::Consulting);
    }

    /**
     * A cotação que representa este lead no funil: a aceita ou, sem ela, a mais barata ainda na mesa (nem recusada,
     * nem vencida) — estimativa conservadora ("a partir de"), para o valor do funil não somar alternativas da mesma
     * venda como se fossem vendas diferentes. Precisa de `quotes` carregadas para não consultar o banco de novo.
     */
    public function estimatedQuote(?CarbonImmutable $hoje = null): ?LeadQuote
    {
        $comPremio = $this->quotes->filter(fn (LeadQuote $q) => $q->premium !== null);

        $aceita = $comPremio->first(fn (LeadQuote $q) => $q->status === \App\Enums\LeadQuoteStatus::Accepted);

        if ($aceita !== null) {
            return $aceita;
        }

        return $comPremio
            ->filter(fn (LeadQuote $q) => $q->isActive($hoje))
            ->sortBy(fn (LeadQuote $q) => (float) $q->annualPremium())
            ->first();
    }

    /** Link do WhatsApp com o telefone (DDD + número; 55 do Brasil quando falta). Nulo se não parece um telefone. */
    public function whatsappUrl(): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $this->phone);

        if (in_array(strlen($digitos), [10, 11], true)) {
            $digitos = '55'.$digitos;
        }

        return in_array(strlen($digitos), [12, 13], true) && str_starts_with($digitos, '55')
            ? 'https://wa.me/'.$digitos
            : null;
    }

    /** Dias desde o último contato registrado (ou, sem nenhum, desde que o lead entrou). Para o aviso de "esfriando". */
    public function daysSinceContact(?CarbonImmutable $hoje = null): int
    {
        $ultimo = $this->activities->first()?->occurred_at ?? $this->created_at;

        // CarbonImmutable::instance: o valor do model é mutável, e startOfDay() alteraria o horário do contato na tela.
        return max(0, (int) CarbonImmutable::instance($ultimo)->startOfDay()->diffInDays(($hoje ?? CarbonImmutable::now())->startOfDay()));
    }
}
