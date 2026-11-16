<?php

namespace App\Services;

use App\Enums\GoalStatus;
use App\Models\Goal;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Divide o balde "Objetivos" da regra de orçamento entre os objetivos da pessoa. Só sugere: o que vai para cada objetivo vira o "Quanto vou guardar
 * por mês" (`installment_amount`) quando a pessoa aplica, e nada é gravado antes disso.
 *
 * Dois jeitos de dividir o dinheiro entre os objetivos COM data-alvo, e o mesmo destino para a sobra:
 * - `priority`: do mais prioritário ao menos, cada um recebe o que precisa por mês para chegar na data (`Goal::monthlyNeeded`) até o dinheiro acabar;
 * - `proportional`: cada um recebe a parte do dinheiro proporcional ao que precisa, sem passar do necessário.
 * O que sobrar depois de todos terem o necessário é repartido por igual entre os objetivos SEM data-alvo (que não têm "necessário por mês"); sem
 * nenhum, fica como sobra. Objetivo alcançado, conquistado ou ligado a consórcio (parcela fixa) fica de fora.
 */
class GoalAllocationService
{
    public const METHODS = ['priority' => 'Por prioridade', 'proportional' => 'Proporcional ao que falta'];

    /**
     * @param  iterable<Goal>  $objetivos  já ordenados por prioridade e já autorizados (o que a pessoa enxerga)
     * @return array{rows: list<array<string, mixed>>, pool: string, allocated: string, leftover: string}
     */
    public function allocate(string $pool, iterable $objetivos, string $metodo = 'priority', ?CarbonImmutable $hoje = null): array
    {
        $hoje ??= CarbonImmutable::now();
        $pool = bccomp($pool, '0', 2) > 0 ? $pool : '0.00';
        $linhas = [];

        foreach ($objetivos as $g) {
            if ($g->status !== GoalStatus::Active || $g->linked_debt_id !== null || $g->isAchieved()) {
                continue;
            }

            $linhas[] = ['goal' => $g, 'needed' => $g->monthlyNeeded(), 'suggested' => '0.00'];
        }

        $comData = array_keys(array_filter($linhas, fn ($l) => $l['needed'] !== null && bccomp($l['needed'], '0', 2) > 0));
        $semData = array_keys(array_filter($linhas, fn ($l) => $l['goal']->target_date === null));
        $restante = $pool;

        if ($metodo === 'proportional') {
            $somaNecessario = '0.00';

            foreach ($comData as $i) {
                $somaNecessario = bcadd($somaNecessario, $linhas[$i]['needed'], 2);
            }

            foreach ($comData as $i) {
                $parte = bccomp($somaNecessario, '0', 2) > 0 ? Money::parse(bcdiv(bcmul($pool, $linhas[$i]['needed'], 6), $somaNecessario, 4)) : '0.00';
                $parte = bccomp($parte, $linhas[$i]['needed'], 2) > 0 ? $linhas[$i]['needed'] : $parte;
                $parte = bccomp($parte, $restante, 2) > 0 ? $restante : $parte;
                $linhas[$i]['suggested'] = $parte;
                $restante = bcsub($restante, $parte, 2);
            }
        } else {
            foreach ($comData as $i) {
                $parte = bccomp($linhas[$i]['needed'], $restante, 2) > 0 ? $restante : $linhas[$i]['needed'];
                $linhas[$i]['suggested'] = $parte;
                $restante = bcsub($restante, $parte, 2);
            }
        }

        // O que sobrou, por igual, entre os objetivos sem data-alvo; os centavos que não dividem exato ficam no último.
        if ($semData !== [] && bccomp($restante, '0', 2) > 0) {
            $partes = Money::split($restante, count($semData));

            foreach ($semData as $k => $i) {
                $linhas[$i]['suggested'] = bcadd($linhas[$i]['suggested'], $partes[$k], 2);
            }

            $restante = '0.00';
        }

        $alocado = '0.00';

        foreach ($linhas as &$l) {
            $alocado = bcadd($alocado, $l['suggested'], 2);
            $l['status'] = match (true) {
                bccomp($l['suggested'], '0', 2) <= 0 => 'none',
                $l['needed'] === null => 'leftover',
                bccomp($l['suggested'], $l['needed'], 2) >= 0 => 'full',
                default => 'partial',
            };
            $l['forecast'] = bccomp($l['suggested'], '0', 2) > 0 ? $this->forecastWith($l['goal'], $l['suggested'], $hoje) : null;
        }
        unset($l);

        return ['rows' => $linhas, 'pool' => $pool, 'allocated' => $alocado, 'leftover' => $restante];
    }

    /** A previsão do objetivo se ele recebesse `$valor` por mês (a mesma frase da lista), sem tocar no objetivo real. */
    private function forecastWith(Goal $g, string $valor, CarbonImmutable $hoje): ?string
    {
        $copia = $g->replicate();
        $copia->setRawAttributes(array_merge($g->getAttributes(), ['installment_amount' => $valor]), true);
        $copia->exists = true;

        foreach ($g->getRelations() as $nome => $relacao) {
            $copia->setRelation($nome, $relacao);
        }

        return $copia->forecastLine($hoje);
    }

    /**
     * Grava o sugerido como "Quanto vou guardar por mês" dos objetivos escolhidos. Só os que a pessoa pode editar; objetivo de outro perfil ou
     * inventado nem é encontrado (o escopo global).
     *
     * @param  array<string, string>  $valores  id do objetivo => valor
     * @return int quantos foram gravados
     */
    public function apply(array $valores): int
    {
        $gravados = 0;

        foreach ($valores as $id => $valor) {
            $g = Goal::query()->find($id);

            if ($g === null || ! $g->isEditableByCurrentMember() || $g->linked_debt_id !== null || bccomp($valor, '0', 2) <= 0) {
                continue;
            }

            $g->update(['installment_amount' => $valor]);
            $gravados++;
        }

        return $gravados;
    }
}
