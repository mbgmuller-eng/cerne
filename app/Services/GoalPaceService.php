<?php

namespace App\Services;

use App\Enums\GoalStatus;
use App\Models\FinancialProfile;
use App\Models\Goal;
use App\Models\GoalContribution;
use App\Models\ProfileMember;
use App\Models\User;
use App\Notifications\GoalBehindPace;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Ritmo real x planejado de um objetivo: o que a pessoa realmente guardou nos últimos meses fechados contra o "quanto vou guardar por mês"
 * (`installment_amount`). Serve a duas coisas: a faixa "abaixo do planejado" na lista de Objetivos e o aviso mensal (`notifyBehind`).
 *
 * Regras que evitam alarme falso:
 * - só conta mês FECHADO: o mês de hoje ainda não acabou, então "guardou pouco" no dia 3 não quer dizer nada;
 * - só conta mês DEPOIS do mês em que o objetivo foi criado (o mês da criação é parcial) e até 3 meses; com menos de 2 meses medidos, é cedo demais;
 * - só julga quem usa o histórico (tem ao menos um lançamento): quem nunca registrou aporte talvez guarde por fora, e avisar seria ruído;
 * - atrasado é guardar menos de 70% do planejado na média (um mês fraco entre três não dispara).
 *
 * Não grava nada. Quem percorre vários objetivos usa `forGoals` (duas consultas para todos, soma em SQL).
 */
class GoalPaceService
{
    /** Quantos meses fechados entram na média. */
    public const WINDOW_MONTHS = 3;

    /** Menos que isso de meses medidos e a média não diz nada. */
    public const MIN_MONTHS = 2;

    /** Abaixo desta parte do planejado (em %), o objetivo está atrasado. */
    public const LATE_BELOW_PERCENT = 70;

    public function __construct(private readonly ReminderLedger $livro) {}

    /**
     * @param  iterable<Goal>  $objetivos  objetivos já carregados (e já autorizados) por quem chama; percorridos duas vezes, então precisa ser lista ou coleção
     * @return array<string, array{late: bool, months: int, planned: string, average: string, total: string, share: float}> indexado pelo id do objetivo;
     *                                                                                                                       objetivo que não pode ser medido (sem planejado, sem histórico, cedo demais, alcançado) fica de fora
     */
    public function forGoals(iterable $objetivos, ?CarbonImmutable $hoje = null): array
    {
        $hoje ??= CarbonImmutable::now();
        $fimDaJanela = $hoje->startOfMonth()->subDay()->toDateString();
        $candidatos = [];

        foreach ($objetivos as $objetivo) {
            if ($objetivo->status !== GoalStatus::Active || ! $objetivo->acceptsContributions() || $objetivo->isAchieved()) {
                continue;
            }

            if ($objetivo->installment_amount === null || bccomp((string) $objetivo->installment_amount, '0', 2) <= 0) {
                continue;
            }

            $meses = $this->measuredMonths($objetivo, $hoje);

            if ($meses >= self::MIN_MONTHS) {
                $candidatos[$objetivo->id] = $meses;
            }
        }

        if ($candidatos === []) {
            return [];
        }

        // Duas consultas para todos, qualquer que seja o número de objetivos (regra 3, soma em SQL): quantos lançamentos cada um já teve (quem usa o
        // histórico) e a soma por mês dos meses fechados da janela mais larga; cada objetivo soma só os meses que lhe cabem. Sem os escopos de
        // propósito: os objetivos já vieram autorizados de quem chama e o aviso roda no cron, sem perfil ativo.
        $ids = array_keys($candidatos);
        $entradas = GoalContribution::withoutGlobalScopes()->whereIn('goal_id', $ids)
            ->selectRaw('goal_id, COUNT(*) as entradas')->groupBy('goal_id')->pluck('entradas', 'goal_id');
        $inicioLargo = $hoje->startOfMonth()->subMonthsNoOverflow(self::WINDOW_MONTHS)->toDateString();
        $porMes = GoalContribution::withoutGlobalScopes()->whereIn('goal_id', $ids)->whereBetween('contributed_on', [$inicioLargo, $fimDaJanela])
            ->selectRaw('goal_id, YEAR(contributed_on) as ano, MONTH(contributed_on) as mes, SUM(amount) as total')
            ->groupBy('goal_id', 'ano', 'mes')->get()->groupBy('goal_id');

        $resultado = [];

        foreach ($objetivos as $objetivo) {
            $meses = $candidatos[$objetivo->id] ?? null;

            if ($meses === null || (int) ($entradas[$objetivo->id] ?? 0) === 0) {
                continue;
            }

            $inicio = $hoje->startOfMonth()->subMonthsNoOverflow($meses);
            $total = '0.00';

            foreach ($porMes[$objetivo->id] ?? [] as $linha) {
                if (CarbonImmutable::create((int) $linha->ano, (int) $linha->mes, 1)->greaterThanOrEqualTo($inicio)) {
                    $total = bcadd($total, Money::parse((string) $linha->total), 2);
                }
            }

            $total = bccomp($total, '0', 2) > 0 ? $total : '0.00';
            $planejado = bcmul((string) $objetivo->installment_amount, (string) $meses, 2);

            $resultado[$objetivo->id] = [
                // total/planejado < 70%  <=>  total*100 < planejado*70, sem divisão
                'late' => bccomp(bcmul($total, '100', 2), bcmul($planejado, (string) self::LATE_BELOW_PERCENT, 2), 2) < 0,
                'months' => $meses,
                'planned' => (string) $objetivo->installment_amount,
                'average' => Money::parse(bcdiv($total, (string) $meses, 4)),
                'total' => $total,
                'share' => Money::percentageOf($total, $planejado),
            ];
        }

        return $resultado;
    }

    /** O ritmo de UM objetivo; nulo quando não dá para medir (ver `forGoals`). */
    public function forGoal(Goal $objetivo, ?CarbonImmutable $hoje = null): ?array
    {
        return $this->forGoals([$objetivo], $hoje)[$objetivo->id] ?? null;
    }

    /** A frase que a lista e o aviso mostram; só faz sentido para objetivo atrasado. */
    public function line(Goal $objetivo, array $ritmo, ?CarbonImmutable $hoje = null): string
    {
        $meses = $ritmo['months'].' '.($ritmo['months'] === 1 ? 'mês fechado' : 'meses fechados');
        $linha = bccomp($ritmo['average'], '0', 2) > 0
            ? "Nos últimos {$meses} você guardou em média ".Money::format($ritmo['average']).' por mês, abaixo dos '.Money::format($ritmo['planned']).' planejados.'
            : "Nos últimos {$meses} nenhum aporte foi registrado, e o planejado era ".Money::format($ritmo['planned']).' por mês.';

        $falta = $objetivo->remaining();

        if ($objetivo->target_date !== null && $objetivo->target_date->isFuture() && bccomp($falta, '0', 2) > 0) {
            $hoje ??= CarbonImmutable::now();
            $mesesAteAlvo = max(1, (int) ceil($hoje->diffInMonths(CarbonImmutable::instance($objetivo->target_date))));
            $preciso = Money::parse(bcdiv($falta, (string) $mesesAteAlvo, 2));

            $linha .= ' Para chegar na data-alvo, guarde '.Money::format($preciso).' por mês.';
        }

        return $linha;
    }

    /**
     * Quantos meses FECHADOS entram na medida: até 3, e só a partir do mês seguinte ao da criação do objetivo.
     */
    private function measuredMonths(Goal $objetivo, CarbonImmutable $hoje): int
    {
        $primeiro = CarbonImmutable::instance($objetivo->created_at)->startOfMonth()->addMonthNoOverflow();
        $ultimo = $hoje->startOfMonth()->subMonthNoOverflow();

        if ($primeiro->greaterThan($ultimo)) {
            return 0;
        }

        $disponiveis = ($ultimo->year - $primeiro->year) * 12 + ($ultimo->month - $primeiro->month) + 1;

        return min(self::WINDOW_MONTHS, $disponiveis);
    }

    /**
     * Rotina diária: avisa, no máximo uma vez por mês, quem tem objetivo abaixo do planejado. Roda sem perfil ativo, então lê sem os
     * escopos e reproduz a privacidade à mão (objetivo privado só avisa o dono). Idempotente pelo livro de lembretes: o assunto é o
     * objetivo e o tipo leva o mês (`behind_2026-11`), então o agendador repetido nunca avisa em dobro e o mês seguinte lembra de novo.
     *
     * @return int quantos avisos foram enviados
     */
    public function notifyBehind(?CarbonImmutable $hoje = null): int
    {
        $hoje ??= CarbonImmutable::now();
        $enviados = 0;
        $mes = $hoje->format('Y-m');

        Goal::withoutGlobalScopes()->active()->whereNull('linked_investment_id')->whereNull('linked_debt_id')
            ->whereNotNull('installment_amount')->where('installment_amount', '>', 0)
            ->withContributionTotal()
            ->chunkById(200, function ($objetivos) use (&$enviados, $hoje, $mes): void {
                $ritmos = $this->forGoals($objetivos, $hoje);

                foreach ($objetivos as $objetivo) {
                    $ritmo = $ritmos[$objetivo->id] ?? null;

                    if ($ritmo === null || ! $ritmo['late']) {
                        continue;
                    }

                    $mensagem = null;

                    foreach ($this->recipientsFor($objetivo->profile_id, $objetivo->member_id, (bool) $objetivo->is_private) as $usuario) {
                        if (! $this->livro->claim($usuario->id, 'goal', $objetivo->id, 'behind_'.$mes)) {
                            continue;
                        }

                        $mensagem ??= $this->line($objetivo, $ritmo, $hoje);
                        $usuario->notify(new GoalBehindPace($objetivo->profile_id, $objetivo->id, $objetivo->name, $mensagem));
                        $enviados++;
                    }
                }
            });

        return $enviados;
    }

    /** @return Collection<int, User> */
    private function recipientsFor(string $profileId, ?string $memberId, bool $privada): Collection
    {
        if ($memberId === null || ! $privada) {
            return FinancialProfile::find($profileId)
                ?->activeMembers()->whereNotNull('user_id')->with('user')->get()
                ->pluck('user')->filter()->values() ?? collect();
        }

        return collect([ProfileMember::find($memberId)?->user])->filter();
    }
}
