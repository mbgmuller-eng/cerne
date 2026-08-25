<?php

namespace App\Models;

use App\Enums\ReserveType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\RespectsMemberPrivacy;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'profile_id', 'member_id', 'reserve_type', 'target_amount',
    'current_amount', 'linked_investment_id',
])]
class FinancialReserve extends Model
{
    use Auditable, BelongsToProfile, HasFactory, HasUuids, RespectsMemberPrivacy;

    protected static string $privacyDomain = 'investment_visibility';

    private ?string $targetAmountCache = null;

    private ?float $progressPercentageCache = null;

    protected function casts(): array
    {
        return [
            'reserve_type' => ReserveType::class,
            'target_amount' => 'decimal:2',
            'current_amount' => 'decimal:2',
        ];
    }

    /** member_id nulo é sentinela numa constante fixa — nunca colide com um UUID real (HasUuids é v7, ordenado no tempo). */
    private const SHARED_MEMBER_KEY = '00000000-0000-0000-0000-000000000000';

    protected static function booted(): void
    {
        // member_key mantém a unicidade (profile_id, member_key, reserve_type)
        // funcionando mesmo com member_id nulo — MySQL trata todo NULL como
        // distinto num índice único, então duas "reservas da família" caberiam
        // ao mesmo tempo sem isso. Nunca setado à mão (ver #[Fillable] acima,
        // member_key não está lá).
        static::saving(function (self $reserva): void {
            $reserva->member_key = $reserva->member_id ?? self::SHARED_MEMBER_KEY;
        });
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(ProfileMember::class, 'member_id');
    }

    public function linkedInvestment(): BelongsTo
    {
        return $this->belongsTo(InvestmentRecord::class, 'linked_investment_id');
    }

    /** Reserva da família, não de uma pessoa — visível aos dois do casal. */
    public function isShared(): bool
    {
        return $this->member_id === null;
    }

    /**
     * Valor efetivo: quando há investimento vinculado, ele é a fonte da
     * verdade — manter dois números manualmente é garantia de divergirem.
     */
    public function effectiveAmount(): string
    {
        return $this->linkedInvestment?->current_amount ?? $this->current_amount;
    }

    /**
     * Meta efetiva: calculada a partir do perfil de investidor do membro
     * (gasto essencial médio x meses do tipo de atuação, ver
     * InvestorProfile::peaceReserveTarget()/opportunityReserveTarget()).
     * `target_amount` fica só como fallback pra membro sem perfil de
     * investidor ainda cadastrado — mesmo raciocínio de effectiveAmount().
     *
     * Memoizado: a tela consulta isso (e progressPercentage(), que
     * depende disso) várias vezes por card — cor da barra, cor do texto,
     * percentual exibido — e sem cache cada chamada refaz a consulta ao
     * InvestorProfile à toa.
     */
    public function targetAmount(): string
    {
        return $this->targetAmountCache ??= (function () {
            if ($this->isShared()) {
                // Não pertence a uma pessoa — pega qualquer perfil de
                // investidor do mesmo perfil pra consultar a fatia
                // compartilhada (o cálculo soma todos os provedores de
                // qualquer forma, não importa por qual InvestorProfile
                // se entra). Ver InvestorProfile::sharedPeaceReserveTarget().
                $qualquerPerfil = InvestorProfile::query()
                    ->where('profile_id', $this->profile_id)
                    ->whereNotNull('employment_type')
                    ->first();

                if ($qualquerPerfil === null) {
                    return $this->target_amount;
                }

                return match ($this->reserve_type) {
                    ReserveType::Paz => $qualquerPerfil->sharedPeaceReserveTarget(),
                    ReserveType::Oportunidade => $qualquerPerfil->sharedOpportunityReserveTarget(),
                };
            }

            $perfil = InvestorProfile::query()->where('member_id', $this->member_id)->first();

            if ($perfil === null) {
                return $this->target_amount;
            }

            return match ($this->reserve_type) {
                ReserveType::Paz => $perfil->peaceReserveTarget(),
                ReserveType::Oportunidade => $perfil->opportunityReserveTarget(),
            };
        })();
    }

    public function progressPercentage(): float
    {
        return $this->progressPercentageCache ??= min(100, Money::percentageOf($this->effectiveAmount(), $this->targetAmount()));
    }

    public function isComplete(): bool
    {
        return bccomp($this->effectiveAmount(), $this->targetAmount(), 2) >= 0;
    }

    /**
     * Escala de cor por quanto falta pra reserva ficar pronta — não pelo
     * tipo (paz/oportunidade). Cinza (0–25%) → vermelho leve (25–50%) →
     * amarelo (50–75%) → verde (75%+): o visual já entrega, de relance,
     * quem tá longe e quem tá quase lá.
     */
    public function progressBarColorClass(): string
    {
        return match (true) {
            $this->progressPercentage() >= 75 => 'bg-emerald-600 dark:bg-emerald-400',
            $this->progressPercentage() >= 50 => 'bg-amber-500 dark:bg-amber-400',
            $this->progressPercentage() >= 25 => 'bg-red-300 dark:bg-red-400/70',
            default => 'bg-slate-300 dark:bg-slate-500',
        };
    }

    /** Mesma escala do progressBarColorClass(), em cor de texto. */
    public function progressTextColorClass(): string
    {
        return match (true) {
            $this->progressPercentage() >= 75 => 'text-emerald-700 dark:text-emerald-400',
            $this->progressPercentage() >= 50 => 'text-amber-700 dark:text-amber-300',
            $this->progressPercentage() >= 25 => 'text-red-500 dark:text-red-300',
            default => 'text-slate-500 dark:text-slate-400',
        };
    }
}
