<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Investments\BrokerPackageImporter;
use Illuminate\Console\Command;

/**
 * Carga dos investimentos de uma corretora (ver BrokerPackageImporter). O JSON ("pacote") traz os ativos com as compras, os
 * proventos e os eventos lidos das notas de corretagem e do extrato de preço médio, a posição que o relatório da corretora
 * declara (para conferir) e a carteira mês a mês.
 *
 * Sem --apply nada é gravado: a simulação percorre o mesmo caminho da gravação e desfaz tudo no fim, e o relatório mostra o que
 * seria criado e as diferenças em relação ao que a corretora declara ter. Dado lido de documento não entra sem olho humano.
 */
class ImportBrokerInvestments extends Command
{
    protected $signature = 'cerne:import-investments
        {json : Caminho do JSON com o pacote de investimentos}
        {--email= : E-mail do dono do perfil que recebe os investimentos}
        {--member= : Nome (ou parte) do membro do perfil dono dos ativos; sem isto, o titular}
        {--apply : Grava de verdade (sem isto é só simulação)}';

    protected $description = 'Cria ativos, compras, proventos e a carteira mensal a partir dos documentos da corretora';

    public function handle(BrokerPackageImporter $importador): int
    {
        $usuario = User::query()->where('email', $this->option('email'))->first();

        if ($usuario === null) {
            $this->error('Usuário não encontrado: informe --email.');

            return self::FAILURE;
        }

        $pacote = json_decode((string) @file_get_contents($this->argument('json')), true);

        if (! is_array($pacote) || empty($pacote['ativos'])) {
            $this->error('JSON ilegível ou sem ativos.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');

        try {
            $linhas = $importador->run($usuario, $pacote, $apply, $this->option('member'));
        } catch (\Throwable $e) {
            $this->error('Falhou, nada foi gravado: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Ativo', 'Classe', 'Situação', 'Criadas', 'Já existiam', 'Cotas', 'Preço médio', 'Custo', 'Valor atual'],
            array_map(fn (array $l) => [
                $l['ativo'], $l['classe'], $l['status'], $l['criadas'], $l['puladas'],
                $l['posicao']['quantidade'] ?? '-', $l['posicao']['preco_medio'] ?? '-', $l['posicao']['custo'] ?? '-', $l['posicao']['valor_atual'] ?? '-',
            ], $linhas),
        );

        $achados = 0;

        foreach ($linhas as $l) {
            foreach ($l['avisos'] as $aviso) {
                $this->warn("{$l['ativo']}: $aviso");
                $achados++;
            }

            foreach ($l['diferencas'] as $diferenca) {
                $this->warn("{$l['ativo']}: $diferenca");
                $achados++;
            }
        }

        $this->line($achados === 0 ? 'Tudo bate com o que a corretora declara.' : "$achados ponto(s) para conferir antes de aplicar.");
        $this->line($apply ? 'Gravado.' : 'Simulação: nada foi gravado. Rode de novo com --apply para aplicar.');

        return self::SUCCESS;
    }
}
