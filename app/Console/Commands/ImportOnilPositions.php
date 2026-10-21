<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Investments\OnilPositionsImporter;
use Illuminate\Console\Command;

/**
 * Posição dos clientes da Onil (saldo + saque em processamento, em reais, USDT, BTC e ETH) como investimentos na carteira do consultor.
 * O JSON traz um item por cliente: onil_id, nome, email, saldo{BRL,USDT,BTC,ETH}, saque{...} e, opcionalmente, base{moeda: depositos,
 * valor_inicial_contratos, performance_contratos, saques_aprovados} (aba "Base e performance"). Sem --apply nada é gravado.
 */
class ImportOnilPositions extends Command
{
    protected $signature = 'cerne:import-onil
        {json : Caminho do JSON com os clientes da Onil}
        {--consultant= : E-mail do consultor dono da carteira}
        {--merge=* : Junta um cliente em outro, no formato origem@email:destino@email (soma moeda a moeda)}
        {--skip=* : E-mail que fica de fora desta carga}
        {--apply : Grava de verdade (sem isto é só simulação)}';

    protected $description = 'Cadastra os clientes da Onil que faltam e grava a posição de cada um (reais, USDT, BTC, ETH) como investimentos';

    public function handle(OnilPositionsImporter $importador): int
    {
        $consultor = User::query()->where('email', $this->option('consultant'))->first();

        if ($consultor === null || ! $consultor->isConsultant()) {
            $this->error('Consultor não encontrado: informe --consultant=email de um consultor.');

            return self::FAILURE;
        }

        $linhas = json_decode((string) @file_get_contents($this->argument('json')), true);

        if (! is_array($linhas) || $linhas === []) {
            $this->error('JSON ilegível ou vazio.');

            return self::FAILURE;
        }

        $mesclar = [];

        foreach ((array) $this->option('merge') as $par) {
            [$de, $para] = array_pad(explode(':', (string) $par, 2), 2, '');

            if ($de === '' || $para === '') {
                $this->error("Mesclagem inválida: {$par} (use origem@email:destino@email).");

                return self::FAILURE;
            }

            $mesclar[$de] = $para;
        }

        $aplicar = (bool) $this->option('apply');

        try {
            $relatorio = $importador->run($consultor, $linhas, $mesclar, (array) $this->option('skip'), $aplicar);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['E-mail', 'Conta', 'Vínculo', 'BRL', 'USDT', 'BTC', 'ETH', 'Aviso'],
            array_map(fn (array $l) => [
                $l['email'], $l['conta'], $l['vinculo'],
                ...array_map(fn (string $m) => isset($l['posicoes'][$m]) ? $this->celula($l['posicoes'][$m]) : '-', ['BRL', 'USDT', 'BTC', 'ETH']),
                $l['aviso'] ?? '',
            ], $relatorio),
        );

        $this->line($aplicar ? 'Gravado.' : 'Simulação: nada foi gravado. Rode de novo com --apply para aplicar.');

        return self::SUCCESS;
    }

    /** @param  array{total: string, acao: string, base?: ?string}  $p */
    private function celula(array $p): string
    {
        if (bccomp($p['total'], '0', 10) === 0 && $p['acao'] === 'sem saldo') {
            return '-';
        }

        return rtrim(rtrim($p['total'], '0'), '.').' ('.$p['acao'].(isset($p['base']) ? ' · base: '.$p['base'] : '').')';
    }
}
