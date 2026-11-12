<?php

namespace App\Models;

use App\Enums\FundingMethod;
use App\Enums\GoalStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\InvalidatesDashboard;
use App\Models\Concerns\RespectsMemberPrivacy;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'profile_id', 'member_id', 'name', 'priority', 'estimated_value', 'target_date',
    'funding_method', 'installment_amount', 'current_amount', 'linked_investment_id', 'linked_debt_id',
    'status', 'notes', 'created_by_user_id', 'is_private',
])]
class Goal extends Model
{
    use Auditable, BelongsToProfile, InvalidatesDashboard, HasFactory, HasUuids, RespectsMemberPrivacy, \App\Models\Concerns\VisibleToFamily;

    protected function casts(): array
    {
        return [
            'funding_method' => FundingMethod::class,
            'status' => GoalStatus::class,
            'priority' => 'integer',
            'estimated_value' => 'decimal:2',
            'installment_amount' => 'decimal:2',
            'current_amount' => 'decimal:2',
            'target_date' => 'date',
            'is_private' => 'boolean',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(ProfileMember::class, 'member_id');
    }

    public function linkedInvestment(): BelongsTo
    {
        return $this->belongsTo(InvestmentRecord::class, 'linked_investment_id');
    }

    /** O consórcio que financia este objetivo: o progresso é pelas parcelas pagas, não pelo dinheiro. */
    public function linkedDebt(): BelongsTo
    {
        return $this->belongsTo(Debt::class, 'linked_debt_id');
    }

    /** Aportes e retiradas registrados (só valem para o objetivo sem ligação a investimento ou consórcio). */
    public function contributions(): HasMany
    {
        return $this->hasMany(GoalContribution::class)->orderByDesc('contributed_on')->orderByDesc('created_at');
    }

    /**
     * Traz a soma dos aportes numa só consulta (`contributions_total`): a lista, o painel e a tela da família percorrem vários objetivos, e uma
     * soma por objetivo seria uma consulta a mais para cada um.
     */
    public function scopeWithContributionTotal(Builder $query): Builder
    {
        return $query->withSum('contributions as contributions_total', 'amount');
    }

    /** Este objetivo guarda histórico de aportes? Não quando o avanço vem de investimento ou de consórcio. */
    public function acceptsContributions(): bool
    {
        return $this->linked_investment_id === null && $this->linked_debt_id === null;
    }

    public function usesInstallments(): bool
    {
        return $this->linked_debt_id !== null && $this->linkedDebt !== null && $this->linkedDebt->term > 0;
    }

    /** @return array{paid: int, total: int} */
    public function installmentProgress(): array
    {
        return ['paid' => $this->linkedDebt->paidCount(), 'total' => (int) $this->linkedDebt->term];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', GoalStatus::Active);
    }

    public function scopeByPriority(Builder $query): Builder
    {
        return $query->orderBy('priority')->orderBy('target_date');
    }

    /**
     * Valor acumulado. Havendo investimento vinculado, ele é a fonte da
     * verdade — manter dois números à mão é garantia de divergirem.
     */
    public function accumulated(): string
    {
        // Objetivo ligado a consórcio: o avanço é a fração das parcelas já pagas, aplicada ao valor do objetivo. Não é dinheiro
        // guardado (o que se paga no consórcio não vira patrimônio); serve para o progresso e os totais seguirem coerentes.
        if ($this->usesInstallments()) {
            $p = $this->installmentProgress();

            return $p['total'] > 0 ? Money::parse(bcdiv(bcmul($this->estimated_value, (string) $p['paid'], 4), (string) $p['total'], 4)) : '0.00';
        }

        if ($this->linkedInvestment !== null) {
            return $this->linkedInvestment->current_amount;
        }

        // Sem ligação: o saldo inicial (`current_amount`) mais os aportes registrados, nunca abaixo de zero.
        $aportes = $this->getAttribute('contributions_total') ?? ($this->exists ? $this->contributions()->sum('amount') : 0);
        $total = bcadd((string) $this->current_amount, Money::parse((string) $aportes), 2);

        return bccomp($total, '0', 2) > 0 ? $total : '0.00';
    }

    public function progressPercentage(): float
    {
        return min(100, Money::percentageOf($this->accumulated(), $this->estimated_value));
    }

    public function remaining(): string
    {
        $falta = bcsub($this->estimated_value, $this->accumulated(), 2);

        return bccomp($falta, '0', 2) > 0 ? $falta : '0.00';
    }

    /**
     * Quanto guardar por mês para chegar na data-alvo.
     *
     * Devolve null quando não há data ou o prazo já passou — nesses casos
     * não existe parcela mensal a sugerir, e inventar um número seria
     * pior do que não mostrar nada.
     */
    public function monthlyNeeded(): ?string
    {
        if ($this->target_date === null || $this->target_date->isPast()) {
            return null;
        }

        $meses = max(1, (int) ceil(now()->diffInMonths($this->target_date)));

        return Money::parse(bcdiv($this->remaining(), (string) $meses, 2));
    }

    public function isAchieved(): bool
    {
        if ($this->usesInstallments() && $this->status !== GoalStatus::Achieved) {
            $p = $this->installmentProgress();

            return $p['total'] > 0 && $p['paid'] >= $p['total'];
        }

        return $this->status === GoalStatus::Achieved
            || bccomp($this->accumulated(), $this->estimated_value, 2) >= 0;
    }

    /**
     * Previsão no ritmo de "quanto vou guardar por mês" (a coluna `installment_amount`, que a tela chama assim): em que mês o objetivo chega
     * guardando esse valor todo mês, se isso é antes ou depois da data-alvo e quanto falta aumentar para chegar a tempo. Não conta rendimento.
     *
     * Nulo quando não há o que prever: sem aporte informado, objetivo já alcançado ou ligado a consórcio (aí o avanço é pelas parcelas pagas).
     *
     * @return array{months: int, date: CarbonImmutable, on_track: ?bool, months_late: ?int, extra_needed: ?string, too_long: bool}|null
     */
    public function forecast(?CarbonImmutable $hoje = null): ?array
    {
        if ($this->usesInstallments() || $this->isAchieved()) {
            return null;
        }

        $mensal = $this->installment_amount;
        $falta = $this->remaining();

        if ($mensal === null || bccomp((string) $mensal, '0', 2) <= 0 || bccomp($falta, '0', 2) <= 0) {
            return null;
        }

        $hoje ??= CarbonImmutable::now();

        // Quantos aportes cheios cabem no que falta, arredondado para cima (bcmath: é conta de dinheiro).
        $meses = bcdiv($falta, (string) $mensal, 0);

        if (bccomp(bcmul($meses, (string) $mensal, 2), $falta, 2) < 0) {
            $meses = bcadd($meses, '1', 0);
        }

        $meses = max(1, (int) $meses);
        $dataAlvo = $this->target_date !== null ? CarbonImmutable::instance($this->target_date) : null;
        $noRitmo = null;
        $atraso = null;
        $faltaAumentar = null;

        if ($dataAlvo !== null) {
            if ($dataAlvo->isFuture()) {
                // Mesma contagem de monthlyNeeded(): meses inteiros até a data-alvo, no mínimo 1.
                $mesesAteAlvo = max(1, (int) ceil($hoje->diffInMonths($dataAlvo)));
                $noRitmo = $meses <= $mesesAteAlvo;

                if (! $noRitmo) {
                    $atraso = $meses - $mesesAteAlvo;
                    $preciso = Money::parse(bcdiv($falta, (string) $mesesAteAlvo, 2));
                    $extra = bcsub($preciso, (string) $mensal, 2);
                    $faltaAumentar = bccomp($extra, '0', 2) > 0 ? $extra : null;
                }
            } else {
                // Data-alvo que já passou: não há "a tempo" a medir, só a previsão.
                $noRitmo = false;
            }
        }

        return [
            'months' => $meses,
            'date' => $hoje->addMonthsNoOverflow($meses),
            'on_track' => $noRitmo,
            'months_late' => $atraso,
            'extra_needed' => $faltaAumentar,
            'too_long' => $meses > 600,
        ];
    }

    /** A previsão em uma frase para a lista e para o aviso "Ao salvar"; nulo quando não há previsão. */
    public function forecastLine(?CarbonImmutable $hoje = null): ?string
    {
        $f = $this->forecast($hoje);

        if ($f === null) {
            return null;
        }

        $ritmo = 'No ritmo de '.Money::format((string) $this->installment_amount).' por mês';

        if ($f['too_long']) {
            return $ritmo.', o objetivo levaria mais de 50 anos. Aumente o valor guardado por mês.';
        }

        $linha = $ritmo.', chega em '.$f['date']->format('m/Y');

        if ($f['on_track'] === true) {
            return $linha.', antes da data-alvo.';
        }

        if ($f['months_late'] !== null) {
            $linha .= ', '.$f['months_late'].' '.($f['months_late'] === 1 ? 'mês' : 'meses').' depois da data-alvo.';

            return $f['extra_needed'] !== null ? $linha.' Para chegar a tempo, guarde '.Money::format($f['extra_needed']).' por mês.' : $linha;
        }

        return $linha.($f['on_track'] === false ? ', depois da data-alvo.' : '.');
    }
}
