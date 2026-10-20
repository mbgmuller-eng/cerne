<?php

namespace App\Console\Commands;

use App\Services\GymLibraryImporter;
use Illuminate\Console\Command;

/**
 * Passo a passo e exercícios novos da biblioteca pública no catálogo compartilhado da Academia. Roda em simulação primeiro
 * (`--dry-run`) e só então grava; as fotos vêm de uma pasta enviada à parte (não entram no repositório).
 */
class ImportGymLibrary extends Command
{
    protected $signature = 'cerne:gym-library-import
        {--file= : JSON da biblioteca (padrão: database/data/gym-exercise-library.json)}
        {--images= : Pasta com uma subpasta por exercício novo, cada uma com 0.jpg e 1.jpg}
        {--dry-run : Só mostra o que seria feito}
        {--force : Reescreve o passo a passo que já existe}';

    protected $description = 'Importa a biblioteca de exercícios (passo a passo e fotos) para o catálogo compartilhado da Academia';

    public function handle(GymLibraryImporter $importador): int
    {
        $arquivo = (string) ($this->option('file') ?: database_path('data/gym-exercise-library.json'));
        $pasta = $this->option('images') ?: null;

        if (! is_file($arquivo)) {
            $this->components->error("Arquivo não encontrado: {$arquivo}");

            return self::FAILURE;
        }

        if ($pasta !== null && ! is_dir($pasta)) {
            $this->components->error("Pasta de fotos não encontrada: {$pasta}");

            return self::FAILURE;
        }

        $entradas = json_decode((string) file_get_contents($arquivo), true);

        if (! is_array($entradas)) {
            $this->components->error('O arquivo não é um JSON válido: '.json_last_error_msg());

            return self::FAILURE;
        }

        $simular = (bool) $this->option('dry-run');
        $r = $importador->import($entradas, $pasta, $simular, (bool) $this->option('force'));

        $this->components->info(($simular ? '[SIMULAÇÃO — nada foi gravado] ' : '').'Biblioteca de exercícios');
        $this->line("  Passo a passo gravado em exercícios que já existiam: {$r['instrucoes']}");
        $this->line("  Exercícios novos cadastrados: {$r['criados']}");
        $this->line("  Exercícios que já existiam sem a biblioteca e foram adotados: {$r['adotados']}");
        $this->line("  Exercícios já importados antes (nada a fazer): {$r['ja_importados']}");
        $this->line("  Exercícios importados antes liberados para o treino automático: {$r['liberados']}");
        $this->line("  Exercícios com as duas fotos vinculadas: {$r['fotos']}");

        if ($r['sem_par'] !== []) {
            $this->components->warn('Sem par no catálogo: '.implode(', ', $r['sem_par']));
        }

        if ($r['sem_foto'] !== []) {
            $this->components->warn(count($r['sem_foto']).' exercícios sem foto na pasta (os primeiros: '.implode(', ', array_slice($r['sem_foto'], 0, 5)).')');
        }

        return self::SUCCESS;
    }
}
