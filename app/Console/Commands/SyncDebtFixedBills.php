<?php

namespace App\Console\Commands;

use App\Models\Debt;
use App\Services\DebtFixedBillSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Põe cada dívida em andamento em Contas fixas (cria, vincula a que já existe, atualiza o valor e encerra a que acabou). A rotina diária
 * `contas-fixas` já faz isto; o comando existe para ver ANTES o que vai acontecer, sem gravar nada (é o padrão), e para fazê-lo na hora.
 *
 *   php artisan cerne:sync-debt-bills            # simulação: lista o que faria e desfaz
 *   php artisan cerne:sync-debt-bills --apply    # grava
 */
class SyncDebtFixedBills extends Command
{
    protected $signature = 'cerne:sync-debt-bills {--apply : grava de verdade (sem isto, só simula e desfaz)}';

    protected $description = 'Cria, vincula e encerra as contas fixas das dívidas (simulação por padrão)';

    public function handle(DebtFixedBillSync $sync): int
    {
        $aplicar = (bool) $this->option('apply');
        $linhas = [];

        if (! $aplicar) {
            DB::beginTransaction();
        }

        try {
            $contagem = $sync->syncAll(function (Debt $divida, array $r) use (&$linhas): void {
                if (in_array($r['acao'], ['nenhuma', 'removida'], true)) {
                    return;
                }

                $linhas[] = [$r['acao'], $divida->name, $r['conta']?->name ?? '-', $divida->profile_id];
            });
        } finally {
            if (! $aplicar) {
                DB::rollBack();
            }
        }

        if ($linhas !== []) {
            $this->table(['O que', 'Dívida', 'Conta fixa', 'Perfil'], $linhas);
        }

        $this->info(($aplicar ? 'Gravado. ' : 'Simulação (nada foi gravado). ').($contagem === [] ? 'Nenhuma dívida.' : json_encode($contagem, JSON_UNESCAPED_UNICODE)));

        return self::SUCCESS;
    }
}
