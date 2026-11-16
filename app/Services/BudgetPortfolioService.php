<?php

namespace App\Services;

use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\User;
use App\Support\ClientDisplayName;
use App\Support\ProfileContext;
use Throwable;

/**
 * A regra de orçamento de TODOS os clientes ativos de um consultor, num mês: quem tem regra, a receita, e como está cada área. O cálculo é o mesmo
 * da tela do cliente (`BudgetRuleService`), rodado sob o contexto de consultor de cada perfil (que enxerga tudo, inclusive o privado entre o casal),
 * e só para cliente com vínculo ATIVO: vínculo pendente ou encerrado não dá acesso ao perfil (FinancialProfilePolicy), então nem é calculado.
 *
 * O contexto do perfil é um singleton: cada cliente monta o seu e solta ao fim, e o que havia antes é restaurado.
 */
class BudgetPortfolioService
{
    public function __construct(private readonly BudgetRuleService $regras, private readonly ProfileContext $contexto) {}

    /**
     * @return array{rows: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function overview(User $consultor, int $ano, int $mes): array
    {
        $perfis = FinancialProfile::query()
            ->whereIn('id', ConsultantClient::activeProfileIds($consultor))
            ->with(['owner', 'members.user'])
            ->get();

        $antes = [$this->contexto->profile(), $this->contexto->member(), $this->contexto->isConsultant()];
        $linhas = [];

        try {
            foreach ($perfis as $perfil) {
                $linhas[] = $this->row($perfil, $ano, $mes);
            }
        } finally {
            $this->contexto->clear();

            if ($antes[0] !== null) {
                $this->contexto->set($antes[0], $antes[1], $antes[2]);
            }
        }

        usort($linhas, fn ($a, $b) => [$a['has_rule'] ? 0 : 1, $a['name']] <=> [$b['has_rule'] ? 0 : 1, $b['name']]);

        return ['rows' => $linhas, 'totals' => [
            'clients' => count($linhas),
            'with_rule' => count(array_filter($linhas, fn ($l) => $l['has_rule'])),
            'limits_ok' => count(array_filter($linhas, fn ($l) => $l['has_rule'] && $l['limits_ok'])),
            'investment_reached' => count(array_filter($linhas, fn ($l) => $l['has_rule'] && $l['investment_reached'])),
        ]];
    }

    /** @return array<string, mixed> */
    private function row(FinancialProfile $perfil, int $ano, int $mes): array
    {
        $base = ['profile_id' => $perfil->id, 'name' => ClientDisplayName::forProfile($perfil), 'has_rule' => false, 'income' => '0.00', 'areas' => [], 'limits_ok' => false, 'investment_reached' => false, 'error' => false];

        $this->contexto->set($perfil, null, true);

        try {
            $relatorio = $this->regras->report($ano, $mes);
        } catch (Throwable $e) {
            // Um cliente com dado estranho não derruba a carteira inteira: aparece sinalizado e o erro vai para o acompanhamento de erros.
            report($e);

            return $base + ['error' => true];
        } finally {
            $this->contexto->clear();
        }

        if ($relatorio === null) {
            return $base;
        }

        $areas = [];

        foreach ($relatorio['areas'] as $chave => $a) {
            $areas[$chave] = ['name' => $a['name'], 'percent' => $a['percent'], 'target' => $a['target'], 'realized' => $a['realized'], 'status' => $a['status'], 'missing' => $a['missing'], 'over' => $a['over'], 'direction' => $a['direction']];
        }

        return array_merge($base, [
            'has_rule' => true,
            'income' => $relatorio['income']['total'],
            'areas' => $areas,
            'limits_ok' => $areas['essential']['status'] === 'ok' && $areas['discretionary']['status'] === 'ok',
            'investment_reached' => $areas['investment']['status'] === 'reached',
        ]);
    }
}
