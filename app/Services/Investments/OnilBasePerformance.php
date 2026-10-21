<?php

namespace App\Services\Investments;

/**
 * Base (quanto a pessoa colocou) e resgates já feitos de uma carteira na Onil, por moeda, a partir do que a corretora informa:
 * depósitos aprovados, valor inicial e performance dos contratos e saques aprovados. O desempenho é medido na própria moeda.
 *
 * Regra: a base são os depósitos da moeda; sem depósito (a moeda veio de conversão), é o valor inicial dos contratos. O resultado é
 * "quantidade de hoje + já resgatado - base". Quando esse resultado concorda com a performance que os contratos informam (até 5% da
 * base), vale ele. Quando não concorda, houve conversão entre moedas (o depósito entrou em reais e virou BTC, por exemplo) e o fluxo
 * deixa de medir a moeda: vale a performance dos contratos e a base é o que fecha a conta. Sem nenhuma das duas, a base é o valor de
 * hoje (resultado zero) e a linha sai marcada "sem base".
 */
final class OnilBasePerformance
{
    /** Quanto o resultado por fluxo pode se afastar da performance dos contratos, em fração da base, e ainda ser considerado limpo. */
    public const TOLERANCE = '0.05';

    public const FLOW = 'fluxo';

    public const CONTRACT_BASE = 'contrato inicial';

    public const CONTRACTS = 'contratos';

    public const NONE = 'sem base';

    /**
     * @param  string  $atual  saldo + saques em processamento, na moeda
     * @param  array{depositos?: string, valor_inicial_contratos?: string, performance_contratos?: string, saques_aprovados?: string}  $b
     * @return array{invested: string, realized: string, method: string}
     */
    public static function resolve(string $atual, array $b): array
    {
        $d = (string) ($b['depositos'] ?? '0');
        $f = (string) ($b['valor_inicial_contratos'] ?? '0');
        $g = (string) ($b['performance_contratos'] ?? '0');
        $l = (string) ($b['saques_aprovados'] ?? '0');

        $total = bcadd($atual, $l, 10);
        $base = bccomp($d, '0', 10) > 0 ? $d : $f;
        $metodoLimpo = bccomp($d, '0', 10) > 0 ? self::FLOW : self::CONTRACT_BASE;

        if (bccomp($base, '0', 10) > 0) {
            $resultado = bcsub($total, $base, 10);
            $folga = bcmul($base, self::TOLERANCE, 10);

            if (bccomp(self::abs(bcsub($resultado, $g, 10)), $folga, 10) <= 0) {
                return ['invested' => $base, 'realized' => $l, 'method' => $metodoLimpo];
            }
        }

        // Os contratos informam um ganho e o saldo comporta esse ganho: a base é o que fecha a conta.
        if (bccomp($g, '0', 10) > 0 && bccomp(bcsub($total, $g, 10), '0', 10) > 0) {
            return ['invested' => bcsub($total, $g, 10), 'realized' => $l, 'method' => self::CONTRACTS];
        }

        return ['invested' => $atual, 'realized' => '0', 'method' => self::NONE];
    }

    private static function abs(string $n): string
    {
        return ltrim($n, '-');
    }
}
