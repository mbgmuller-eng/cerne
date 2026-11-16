<?php

namespace App\Services;

use App\Models\BudgetRule;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Notifications\BudgetInvestmentGap;
use App\Support\Money;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;

/**
 * Rotina `regra-de-orcamento`: a partir do dia 20, avisa UMA vez por mês cada pessoa de um perfil com regra quando falta para a meta de investimentos
 * do mês. O valor é calculado sob o contexto da PRÓPRIA pessoa (privacidade do casal: cada um recebe o número que enxerga), e o livro de lembretes
 * (índice único: usuário + perfil + tipo com o mês) garante que o agendador repetido ou concorrente nunca avisa em dobro (regra 4).
 *
 * Só avisa quando há receita no mês (sem ela a meta é zero) e a meta ainda não foi batida. Consultor não recebe: o aviso é de quem investe.
 */
class BudgetGapNotifier
{
    /** A partir de que dia do mês o aviso passa a valer (antes disso o mês ainda está no começo). */
    public const FROM_DAY = 20;

    public function __construct(private readonly BudgetRuleService $regras, private readonly ReminderLedger $livro, private readonly ProfileContext $contexto) {}

    /** @return int quantos avisos foram enviados */
    public function notifyShortfalls(?CarbonImmutable $hoje = null): int
    {
        $hoje ??= CarbonImmutable::now();

        if ($hoje->day < self::FROM_DAY) {
            return 0;
        }

        $enviados = 0;
        $mes = $hoje->format('Y-m');

        BudgetRule::withoutProfileScope()->select('profile_id')->distinct()->orderBy('profile_id')->pluck('profile_id')->chunk(100)->each(function ($ids) use (&$enviados, $hoje, $mes): void {
            foreach (FinancialProfile::query()->whereIn('id', $ids)->with('members.user')->get() as $perfil) {
                foreach ($perfil->members as $membro) {
                    if ($membro->is_active && $membro->user !== null) {
                        $enviados += $this->notifyMember($perfil, $membro, $hoje, $mes) ? 1 : 0;
                    }
                }
            }
        });

        return $enviados;
    }

    private function notifyMember(FinancialProfile $perfil, ProfileMember $membro, CarbonImmutable $hoje, string $mes): bool
    {
        $this->contexto->set($perfil, $membro);

        try {
            $relatorio = $this->regras->report($hoje->year, $hoje->month);
        } finally {
            $this->contexto->clear();
        }

        if ($relatorio === null || bccomp($relatorio['income']['total'], '0', 2) <= 0) {
            return false;
        }

        $investimento = $relatorio['areas']['investment'];

        if (bccomp($investimento['missing'], '0', 2) <= 0 || bccomp($investimento['target'], '0', 2) <= 0) {
            return false;
        }

        if (! $this->livro->claim($membro->user->id, 'budget_rule', $perfil->id, 'gap_'.$mes)) {
            return false;
        }

        $nomeMes = $hoje->locale('pt_BR')->translatedFormat('F');
        $membro->user->notify(new BudgetInvestmentGap(
            $perfil->id,
            "Meta de investimentos de {$nomeMes}",
            'Faltam '.Money::format($investimento['missing']).' para a meta de investimentos de '.$nomeMes.' ('.Money::format($investimento['target']).', '
                .rtrim(rtrim(number_format((float) $investimento['percent'], 2, ',', ''), '0'), ',').'% da receita). Já foram '.Money::format($investimento['realized']).'.',
        ));

        return true;
    }
}
