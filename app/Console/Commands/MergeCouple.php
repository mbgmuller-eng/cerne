<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\CoupleMergeService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Junta dois clientes com perfis individuais separados num único perfil de casal (ver CoupleMergeService).
 *
 * Sem --apply é só simulação: confere tudo e mostra o que mudaria, sem gravar nada. Com --apply exige --backup,
 * grava antes a cópia dos registros que dariam para desfazer à mão e só então unifica, numa transação.
 */
class MergeCouple extends Command
{
    protected $signature = 'cerne:merge-couple
        {principal : E-mail da pessoa principal (o perfil dela vira o do casal)}
        {conjuge : E-mail da outra pessoa (entra no perfil da principal)}
        {--apply : Grava de verdade (sem isto é só simulação)}
        {--backup= : Arquivo JSON onde guardar a cópia de segurança (obrigatório com --apply)}';

    protected $description = 'Unifica dois clientes com perfis individuais em um perfil de casal';

    public function handle(CoupleMergeService $servico): int
    {
        $principal = User::query()->where('email', $this->argument('principal'))->first();
        $conjuge = User::query()->where('email', $this->argument('conjuge'))->first();

        if ($principal === null || $conjuge === null) {
            $this->error('Não achei '.($principal === null ? $this->argument('principal') : $this->argument('conjuge')).'.');

            return self::FAILURE;
        }

        $aplicar = (bool) $this->option('apply');

        if ($aplicar && ! $this->option('backup')) {
            $this->error('Com --apply informe --backup=ARQUIVO.json: é a cópia para desfazer, se for preciso.');

            return self::FAILURE;
        }

        try {
            $plano = $servico->plan($principal, $conjuge);
        } catch (RuntimeException $e) {
            $this->error('Não dá para unificar: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line("Perfil do casal: <info>{$plano['perfilPrincipal']->profile_name}</info> (principal: {$principal->email})");
        $this->line("Entra no perfil: <info>{$plano['membroConjuge']->name}</info> ({$conjuge->email}), como cônjuge");
        $this->newLine();

        $this->line('Linhas que mudam de perfil:');
        $this->table(['Tabela', 'Linhas'], collect($plano['linhas'])->map(fn ($n, $t) => [$t, $n])->values()->all() ?: [['(nenhuma)', 0]]);

        foreach ($plano['regrasDuplicadas'] as $tabela => $n) {
            $this->warn("{$n} regra(s) de {$tabela} da cônjuge repetem uma da principal e serão descartadas (vale a da principal).");
        }
        foreach ($plano['descartadas'] as $tabela => $n) {
            $this->warn("{$n} linha(s) de {$tabela} do perfil antigo serão descartadas junto com ele.");
        }
        $this->line('Vínculos com consultor que saem da Carteira (a principal continua): '.count($plano['vinculosRemovidos']));

        if (! $aplicar) {
            $this->newLine();
            $this->info('Simulação: nada foi gravado. Rode com --apply --backup=ARQUIVO.json para unificar.');

            return self::SUCCESS;
        }

        $copia = $servico->backup($plano);
        $caminho = (string) $this->option('backup');

        if (@file_put_contents($caminho, json_encode($copia, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
            $this->error("Não consegui gravar a cópia em {$caminho}. Nada foi alterado.");

            return self::FAILURE;
        }

        try {
            $servico->merge($principal, $conjuge);
        } catch (RuntimeException $e) {
            $this->error('A unificação foi desfeita: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Unificado. Cópia de segurança em {$caminho}.");

        return self::SUCCESS;
    }
}
