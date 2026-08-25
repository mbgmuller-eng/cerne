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
     * Só os gastos essenciais da FAMÍLIA (member_id nulo em
     * expense_records) — o que é visível aos dois do casal por
     * definição, independente da configuração de privacidade. Base da
     * reserva compartilhada (ver sharedPeaceReserveTarget()).
     */
    private function sharedEssentialMonthlyAverage(): string
    {
        return $this->averageOf($this->closedMonthsEssentialTotals(onlyFamily: true));
    }

    /**
     * Só os gastos essenciais deste MEMBRO especificamente (member_id =
     * o dele) — o que, numa "vida financeira" com dado oculto, só ele
     * enxerga. Base da reserva individual dele (ver peaceReserveTarget()).
     */
    private function ownEssentialMonthlyAverage(): string
    {
        return $this->averageOf($this->closedMonthsEssentialTotals(memberId: $this->member_id));
    }

    /**
     * Totais essenciais por mês fechado, com o filtro de dono que a
     * chamada pedir — nenhum filtro (casa inteira), só família
     * (member_id nulo), ou só de um membro específico. A parte cara
     * (agregação por mês) sempre roda no banco — CLAUDE.md regra 3.
     *
     * @return Collection<int, string>
     */
    private function closedMonthsEssentialTotals(bool $onlyFamily = false, ?string $memberId = null): Collection
    {
        $hoje = CarbonImmutable::now();

        $query = ExpenseRecord::query()
            ->ofNecessity(Necessity::Essential)
            ->where(function ($query) use ($hoje) {
                $query->where('year', '<', $hoje->year)
                    ->orWhere(function ($query) use ($hoje) {
                        $query->where('year', $hoje->year)->where('month', '<', $hoje->month);
                    });
            });

        if ($onlyFamily) {
            $query->whereNull('member_id');
        } elseif ($memberId !== null) {
            $query->where('member_id', $memberId);
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
     * Duas bases possíveis:
     *   - Casal com gasto oculto entre os dois (own_only em
     *     expense_visibility): a base é só o que é PRIVADO deste membro
     *     — o que o outro não vê. Sem divisão: é gasto que só ele tem,
     *     ninguém mais está cobrindo. A fatia visível aos dois vira uma
     *     reserva À PARTE (ver sharedPeaceReserveTarget()).
     *   - Sem gasto oculto (solteiro, ou casal 100% transparente): a
     *     base é o gasto essencial da casa inteira, dividido pelo número
     *     de provedores (membros com perfil de investidor e tipo de
     *     atuação definidos) — cada um cobre sua fatia com o PRÓPRIO
     *     multiplicador.
     */
    public function peaceReserveTarget(): string
    {
        if ($this->employment_type === null) {
            return '0.00';
        }

        if ($this->hasHiddenExpenses()) {
            return bcmul($this->ownEssentialMonthlyAverage(), (string) $this->employment_type->reserveMonths(), 2);
        }

        $fatiaEssencial = bcdiv($this->essentialMonthlyAverage(), (string) $this->providersCount(), 2);

        return bcmul($fatiaEssencial, (string) $this->employment_type->reserveMonths(), 2);
    }

    /** Reserva de oportunidade DESTE membro: sempre 1/3 da reserva de paz dele. */
    public function opportunityReserveTarget(): string
    {
        return bcdiv($this->peaceReserveTarget(), '3', 2);
    }

    /**
     * Meta da reserva de paz DO CASAL (member_id nulo em
     * financial_reserves) — só existe quando há gasto oculto entre os
     * dois (senão não há "fatia visível aos dois" separada da casa
     * inteira, e cada um já cobre tudo via peaceReserveTarget()).
     *
     * A base é o gasto essencial da FAMÍLIA (visível aos dois), dividida
     * entre os provedores; cada um cobre sua fatia com o PRÓPRIO
     * multiplicador, e a reserva do casal é a SOMA das fatias — mesma
     * lógica de "dois provedores" de peaceReserveTarget(), só que o
     * resultado vira UM valor em vez de ficar em duas reservas
     * separadas. Pode ser chamado a partir do InvestorProfile de
     * qualquer um dos dois — o cálculo soma todos os provedores do
     * mesmo perfil de qualquer forma.
     */
    public function sharedPeaceReserveTarget(): string
    {
        if (! $this->hasHiddenExpenses()) {
            return '0.00';
        }

        $provedores = self::query()
            ->where('profile_id', $this->profile_id)
            ->whereNotNull('employment_type')
            ->get();

        // Reserva "do casal" só faz sentido com os dois de fato
        // rastreados como provedores — com um só, não há ninguém pra
        // dividir a fatia compartilhada com ele. Fica em 0 até o
        // segundo se cadastrar (o essencial de família nesse meio-tempo
        // simplesmente ainda não tem reserva cobrindo ele).
        if ($provedores->count() < 2) {
            return '0.00';
        }

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

    /**
     * Este casal esconde gasto essencial entre si? Domínio
     * `expense_visibility` de ProfileAccessSettings — é sobre o dado de
     * despesa em si, diferente de `investment_visibility` (que governa
     * se a reserva RESULTANTE é visível ao outro, uma questão à parte).
     * Solteiro nunca tem — não há "o outro" pra esconder de quem.
     *
     * Consulta direta por profile_id, não a relação `profile` — evitar
     * lazy load (Model::shouldBeStrict() derruba a página em ambiente
     * local se este método for chamado sem o relacionamento já
     * carregado, e não dá pra garantir isso em todo lugar que cria ou
     * busca um InvestorProfile). Sem configuração ainda salva, o padrão
     * é transparente — mesmo raciocínio de FinancialProfile::settings().
     */
    private function hasHiddenExpenses(): bool
    {
        $settings = ProfileAccessSettings::query()->where('profile_id', $this->profile_id)->first();

        return $settings !== null && ! $settings->sharesDomain('expense_visibility');
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
