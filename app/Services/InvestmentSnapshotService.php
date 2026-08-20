<?php

namespace App\Services;

use App\Models\InvestmentRecord;
use App\Models\InvestmentSnapshot;
use Carbon\CarbonImmutable;

/**
 * Foto mensal da carteira.
 *
 * Sem ela a evolução do patrimônio precisaria ser reconstruída a partir
 * do histórico de transações a cada consulta — caro e impreciso, porque
 * a valorização do ativo não passa por transação nenhuma.
 */
class InvestmentSnapshotService
{
    /**
     * Registra a posição de todos os ativos ativos numa competência.
     *
     * Idempotente pelo índice único (ativo, ano, mês). Roda sem escopo de
     * perfil: é rotina de sistema, atravessa todos os clientes.
     *
     * @return int quantas fotos foram criadas
     */
    public function captureMonth(?int $year = null, ?int $month = null): int
    {
        $hoje = CarbonImmutable::now();
        $year ??= $hoje->year;
        $month ??= $hoje->month;

        $criadas = 0;

        InvestmentRecord::withoutProfileScope()
            ->where('is_active', true)
            ->chunkById(200, function ($ativos) use ($year, $month, &$criadas): void {
                foreach ($ativos as $ativo) {
                    $existe = InvestmentSnapshot::withoutProfileScope()
                        ->where('investment_id', $ativo->id)
                        ->where('year', $year)
                        ->where('month', $month)
                        ->exists();

                    if ($existe) {
                        continue;
                    }

                    InvestmentSnapshot::withoutProfileScope()->create([
                        'profile_id' => $ativo->profile_id,
                        'investment_id' => $ativo->id,
                        'year' => $year,
                        'month' => $month,
                        'amount' => $ativo->current_amount,
                        'quantity' => $ativo->quantity,
                    ]);

                    $criadas++;
                }
            });

        return $criadas;
    }
}
