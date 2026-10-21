<?php

namespace App\Console\Commands;

use App\Casts\Sensitive;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Cifra o que ainda está em texto puro nos campos sensíveis (CPF/CNPJ e dado de saúde). Idempotente: o que já está cifrado fica como está,
 * então pode rodar de novo sem problema (e é o que se faz depois de importar dado antigo). Escreve direto na tabela, sem passar pelo model:
 * não gera registro de auditoria nem mexe em `updated_at`.
 */
class EncryptSensitiveData extends Command
{
    protected $signature = 'cerne:encrypt-sensitive {--dry-run : Só conta o que seria cifrado}';

    protected $description = 'Cifra CPF/CNPJ e dados de saúde que ainda estão em texto puro';

    /** @var array<string, list<string>> */
    public const COLUMNS = [
        'users' => ['cpf_cnpj'],
        'billing_details' => ['document'],
        'health_allergies' => ['description'],
        'health_conditions' => ['description'],
        'health_medications' => ['name', 'dose', 'schedule', 'reason', 'prescriber'],
        'health_appointments' => ['title', 'professional_name', 'specialty', 'notes'],
        'health_medication_changes' => ['old_value', 'new_value', 'note'],
    ];

    /** Quantas linhas ainda têm algum campo sensível em texto puro (o cerne:check avisa enquanto for maior que zero). */
    public static function plaintextRows(): int
    {
        $total = 0;

        foreach (self::COLUMNS as $tabela => $colunas) {
            DB::table($tabela)->orderBy('id')->chunkById(500, function ($linhas) use ($colunas, &$total): void {
                foreach ($linhas as $linha) {
                    foreach ($colunas as $coluna) {
                        if (! Sensitive::isEncrypted($linha->{$coluna})) {
                            $total++;

                            break;
                        }
                    }
                }
            });
        }

        return $total;
    }

    public function handle(): int
    {
        $simular = (bool) $this->option('dry-run');
        $total = 0;

        foreach (self::COLUMNS as $tabela => $colunas) {
            $feitos = 0;

            DB::table($tabela)->orderBy('id')->chunkById(200, function ($linhas) use ($tabela, $colunas, $simular, &$feitos): void {
                foreach ($linhas as $linha) {
                    $novos = [];

                    foreach ($colunas as $coluna) {
                        $valor = $linha->{$coluna};

                        if (! Sensitive::isEncrypted($valor)) {
                            $novos[$coluna] = Crypt::encryptString((string) $valor);
                        }
                    }

                    if ($novos !== []) {
                        $feitos++;

                        if (! $simular) {
                            DB::table($tabela)->where('id', $linha->id)->update($novos);
                        }
                    }
                }
            });

            $this->line("  {$tabela}: {$feitos} ".($simular ? 'a cifrar' : 'cifrados'));
            $total += $feitos;
        }

        $this->components->info(($simular ? '[SIMULAÇÃO] ' : '')."Linhas com campo sensível em texto puro: {$total}.");

        return self::SUCCESS;
    }
}
