<?php

namespace App\Models;

use App\Enums\EmploymentType;
use App\Enums\InvestorType;
use App\Enums\Necessity;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToProfile;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable(['profile_id', 'member_id', 'investor_type', 'employment_type'])]
class InvestorProfile extends Model
{
    use Auditable, BelongsToProfile, HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'investor_type' => InvestorType::class,
            'employment_type' => EmploymentType::class,
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(ProfileMember::class, 'member_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(RecommendedAllocation::class);
    }

    /**
     * Média dos gastos essenciais da casa (TODOS, de qualquer membro) nos
     * meses FECHADOS em que há lançamento — até 12 meses, os mais
     * recentes. Nunca o mês corrente (ainda pode receber lançamento, a
     * média oscilaria dia a dia) nem mês futuro (parcela já programada
     * não é gasto essencial médio de verdade). Usada quando não há
     * privacidade a respeitar (solteiro, ou casal com despesas 100%
     * visíveis aos dois) — ver hasHiddenExpenses().
     */
    public function essentialMonthlyAverage(): string
    {
        return $this->averageOf($this->closedMonthsEssentialTotals());
    }

    /**
     * Gastos essenciais VISÍVEIS AOS DOIS — família (member_id nulo) +
     * qualquer lançamento de um membro que ELE NÃO marcou como oculto.
     * Base da reserva compartilhada (ver sharedPeaceReserveTarget()):
     * é sempre seguro somar, porque nada aqui é privado de ninguém.
     */
    private function sharedEssentialMonthlyAverage(): string
    {
        return $this->averageOf($this->closedMonthsEssentialTotals(onlyVisible: true));
    }

    /**
     * Só os gastos essenciais que ESTE MEMBRO marcou como ocultos do
     * cônjuge — a parte que a reserva do casal (baseada no que é visível
     * aos dois) não cobre. Base da reserva individual dele (ver
     * peaceReserveTarget()).
     */
    private function ownEssentialMonthlyAverage(): string
    {
        return $this->averageOf($this->closedMonthsEssentialTotals(memberId: $this->member_id, onlyPrivate: true));
    }

    /**
     * Totais essenciais por mês fechado, com o filtro que a chamada pedir
     * — nenhum filtro (casa inteira, todo mundo, oculto ou não), só o
     * que é visível aos dois (família + não marcado como oculto), ou só
     * o oculto de um membro específico. A parte cara (agregação por mês)
     * sempre roda no banco — CLAUDE.md regra 3.
     *
     * Ignora o escopo de privacidade da própria query (withoutPrivacyScope):
     * este cálculo PRECISA enxergar os lançamentos ocultos de ambos os
     * membros pra somar a fatia certa — quem vê o RESULTADO é decidido à
     * parte, pela reserva ficar oculta ou não (ver hasHiddenExpenses()).
     *
     * @return Collection<int, string>
     */
    private function closedMonthsEssentialTotals(bool $onlyVisible = false, ?string $memberId = null, bool $onlyPrivate = false): Collection
    {
        $hoje = CarbonImmutable::now();

        $query = ExpenseRecord::withoutPrivacyScope()
            ->ofNecessity(Necessity::Essential)
            ->where(function ($query) use ($hoje) {
                $query->where('year', '<', $hoje->year)
                    ->orWhere(function ($query) use ($hoje) {
                        $query->where('year', $hoje->year)->where('month', '<', $hoje->month);
                    });
            });

        if ($onlyVisible) {
            $query->where(fn ($q) => $q->whereNull('member_id')->orWhere('is_private', false));
        } elseif ($memberId !== null) {
            $query->where('member_id', $memberId);

            if ($onlyPrivate) {
                $query->where('is_private', true);
            }
        }

        return $query->selectRaw('SUM(amount) as total')
            ->groupBy('year', 'month')
            ->orderByDesc('year')->orderByDesc('month')
            ->limit(12)
            ->pluck('total');
    }

    /** @param  Collection<int, string>  $porMes */
    private function averageOf(Collection $porMes): string
    {
        if ($porMes->isEmpty()) {
            return '0.00';
        }

        return bcdiv(Money::sum($porMes), (string) $porMes->count(), 2);
    }

    /**
     * Meta da reserva de paz DESTE membro. Sem tipo de atuação definido,
     * não há meta — o consultor ainda precisa informar.
     *
     * Três casos:
     *   - Solteiro, ou casal com só um provedor rastreado: não há com
     *     quem dividir nem "reserva do casal" — a meta é sempre o total
     *     essencial próprio, não importa se algo está marcado como
     *     oculto (não há "o outro" pra esconder de quem).
     *   - Casal com 2+ provedores e NINGUÉM esconde nada: a base é o
     *     gasto essencial da casa inteira, dividido pelo número de
     *     provedores — cada um cobre sua fatia com o PRÓPRIO
     *     multiplicador. Sem reserva do casal separada (ver
     *     sharedPeaceReserveTarget()).
     *   - Casal com 2+ provedores e ALGUÉM esconde algo: a fatia
     *     VISÍVEL aos dois já é coberta pela reserva do casal — este
     *     membro só precisa de reserva individual se ELE PRÓPRIO tem
     *     gasto oculto, cobrindo exatamente essa parte.
     */
    public function peaceReserveTarget(): string
    {
        if ($this->employment_type === null) {
            return '0.00';
        }

        if ($this->providersCount() < 2) {
            return bcmul($this->essentialMonthlyAverage(), (string) $this->employment_type->reserveMonths(), 2);
        }

        if (! $this->householdHasHiddenExpenses()) {
            $fatiaEssencial = bcdiv($this->essentialMonthlyAverage(), (string) $this->providersCount(), 2);

            return bcmul($fatiaEssencial, (string) $this->employment_type->reserveMonths(), 2);
        }

        if (! $this->hasHiddenExpenses()) {
            // Alguém no casal esconde algo, mas não este membro — a
            // fatia dele já está coberta pela reserva do casal.
            return '0.00';
        }

        return bcmul($this->ownEssentialMonthlyAverage(), (string) $this->employment_type->reserveMonths(), 2);
    }

    /** Reserva de oportunidade DESTE membro: sempre 1/3 da reserva de paz dele. */
    public function opportunityReserveTarget(): string
    {
        return bcdiv($this->peaceReserveTarget(), '3', 2);
    }

    /**
     * Meta da reserva de paz DO CASAL (member_id nulo em
     * financial_reserves) — só existe com 2+ provedores E alguém
     * escondendo algo (senão não há "fatia visível aos dois" separada da
     * casa inteira, e cada um já cobre tudo via peaceReserveTarget()).
     *
     * A base é o gasto essencial VISÍVEL AOS DOIS (família + o que
     * ninguém marcou como oculto), dividida entre os provedores; cada um
     * cobre sua fatia com o PRÓPRIO multiplicador, e a reserva do casal
     * é a SOMA das fatias — mesma lógica de "dois provedores" de
     * peaceReserveTarget(), só que o resultado vira UM valor em vez de
     * ficar em duas reservas separadas. Pode ser chamado a partir do
     * InvestorProfile de qualquer um dos dois — o cálculo soma todos os
     * provedores do mesmo perfil de qualquer forma.
     */
    public function sharedPeaceReserveTarget(): string
    {
        if ($this->providersCount() < 2 || ! $this->householdHasHiddenExpenses()) {
            return '0.00';
        }

        $provedores = self::query()
            ->where('profile_id', $this->profile_id)
            ->whereNotNull('employment_type')
            ->get();

        $fatia = bcdiv($this->sharedEssentialMonthlyAverage(), (string) $provedores->count(), 2);

        return $provedores->reduce(
            fn (string $total, self $provedor) => bcadd($total, bcmul($fatia, (string) $provedor->employment_type->reserveMonths(), 2), 2),
            '0.00',
        );
    }

    /** Reserva de oportunidade DO CASAL: sempre 1/3 da reserva de paz do casal. */
    public function sharedOpportunityReserveTarget(): string
    {
        return bcdiv($this->sharedPeaceReserveTarget(), '3', 2);
    }

    /** Algum provedor deste perfil (qualquer um, não só este membro) tem
     * gasto essencial marcado como oculto? Decide se o casal entra no
     * modo "reserva do casal + individuais" ou continua no modo simples
     * (fatia da casa inteira dividida, sem reserva separada). */
    private function householdHasHiddenExpenses(): bool
    {
        return ExpenseRecord::withoutPrivacyScope()
            ->ofNecessity(Necessity::Essential)
            ->where('profile_id', $this->profile_id)
            ->where('is_private', true)
            ->exists();
    }

    /** ESTE membro específico tem gasto essencial marcado como oculto? */
    private function hasHiddenExpenses(): bool
    {
        return ExpenseRecord::withoutPrivacyScope()
            ->ofNecessity(Necessity::Essential)
            ->where('member_id', $this->member_id)
            ->where('is_private', true)
            ->exists();
    }

    /**
     * Quantos membros deste perfil são provedores de fato — têm perfil
     * de investidor com tipo de atuação definido. Sempre pelo menos 1
     * (o próprio, já que peaceReserveTarget() só chega aqui depois de
     * garantir employment_type !== null).
     */
    private function providersCount(): int
    {
        return max(1, InvestorProfile::query()
            ->where('profile_id', $this->profile_id)
            ->whereNotNull('employment_type')
            ->count());
    }

    /** A alocação recomendada precisa somar 100%. */
    public function allocationTotal(): string
    {
        return (string) $this->allocations()->sum('target_percentage');
    }

    public function allocationIsValid(): bool
    {
        return bccomp($this->allocationTotal(), '100', 2) === 0;
    }
}
