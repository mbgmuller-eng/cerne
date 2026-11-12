<?php

namespace App\Support\Debts;

use App\Models\DebtSimulation;
use Illuminate\Support\Collection;

/**
 * As propostas de acordo de uma dívida em aberto, numa lista ÚNICA: junta as propostas de todas as simulações guardadas (`DebtSimulation`, que
 * é só o recipiente: o nome, o saldo da data e as propostas) e as cadastradas uma a uma. Cada proposta é endereçada por "idDaSimulação:posição".
 *
 * Só leitura e conta pura (nenhum acesso a banco além do que a coleção já trouxe), para o painel da tela e os testes usarem a mesma regra.
 */
final class DebtProposals
{
    /**
     * @param  Collection<int, DebtSimulation>  $simulacoes
     * @return list<array<string, mixed>> do mais novo ao mais antigo (data de recebimento, depois ordem de criação)
     */
    public static function flatten(Collection $simulacoes): array
    {
        $linhas = [];

        foreach ($simulacoes as $sim) {
            $propostas = $sim->proposals ?? [];

            foreach (array_values($propostas) as $i => $p) {
                $linhas[] = [
                    'key' => $sim->id.':'.$i,
                    'sim_id' => $sim->id,
                    'index' => $i,
                    'label' => (string) ($p['label'] ?? ''),
                    'total' => (string) ($p['total'] ?? '0.00'),
                    'down' => (string) ($p['down'] ?? '0.00'),
                    'installments' => (int) ($p['installments'] ?? 1),
                    // Proposta sem data própria (as vindas de "Guardar simulação") vale a data em que foi guardada.
                    'received_on' => (string) ($p['received_on'] ?? $sim->created_at->toDateString()),
                    'note' => (string) ($p['note'] ?? ''),
                    'balance_at' => (string) $sim->balance,
                    'sim_name' => $sim->name,
                    'sim_count' => count($propostas),
                    'created_at' => $sim->created_at->getTimestamp(),
                ];
            }
        }

        usort($linhas, fn (array $a, array $b) => [$b['received_on'], $b['created_at'], $b['index']] <=> [$a['received_on'], $a['created_at'], $a['index']]);

        return $linhas;
    }

    /**
     * Compara as propostas escolhidas de duas formas: pelo saldo da ÉPOCA de cada uma (o desconto que ela representava) e pelo saldo de HOJE (quanto
     * custa em relação ao que a dívida vale agora). A parcela, a taxa implícita e os destaques (menor custo, menor parcela) saem do simulador de acordo,
     * calculados contra o saldo de hoje, para que propostas de datas diferentes fiquem na mesma régua.
     *
     * @param  list<array<string, mixed>>  $escolhidas  linhas de flatten()
     * @return array{rows: list<array<string, mixed>>, best_total: ?int, lightest_month: ?int}
     */
    public static function compare(array $escolhidas, string $saldoHoje): array
    {
        if ($escolhidas === []) {
            return ['rows' => [], 'best_total' => null, 'lightest_month' => null];
        }

        $simulado = DebtAgreementSimulator::compare($saldoHoje, array_map(fn (array $p) => [
            'label' => $p['label'], 'total' => $p['total'], 'down' => $p['down'], 'installments' => $p['installments'],
        ], $escolhidas));

        $linhas = [];

        foreach ($escolhidas as $i => $p) {
            $s = $simulado['proposals'][$i];
            $descontoEpoca = bcsub($p['balance_at'], $s['total'], 2);

            $linhas[] = $p + [
                'monthly' => $s['monthly'],
                'implicit_rate' => $s['implicit_rate'],
                'reference' => $s['reference'] ?? null,
                // Saldo da época: positivo = desconto sobre o que a dívida valia quando a proposta veio.
                'discount_then' => $descontoEpoca,
                'discount_then_percent' => bccomp($p['balance_at'], '0', 2) > 0 ? round((float) $descontoEpoca / (float) $p['balance_at'] * 100, 2) : 0.0,
                // Saldo de hoje: positivo = desconto sobre o saldo atual; negativo = custa mais que a dívida hoje.
                'discount_today' => $s['discount'],
                'discount_today_percent' => $s['discount_percent'],
            ];
        }

        return ['rows' => $linhas, 'best_total' => $simulado['best_total'], 'lightest_month' => $simulado['lightest_month']];
    }
}
