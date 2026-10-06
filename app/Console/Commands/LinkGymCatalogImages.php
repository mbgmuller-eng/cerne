<?php

namespace App\Console\Commands;

use App\Models\GymExerciseCatalog;
use App\Services\GymCatalogImageService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;

/**
 * Vincula fotos de referência ao CATÁLOGO COMPARTILHADO — mesma ideia de
 * cerne:gym-link-images, mas aqui não tem dono: um exercício do catálogo
 * é visível pra todo mundo, então não pede --email nem passa por
 * ProfileContext nenhum.
 *
 * Mapeamento: [{"exercise": "Nome exato no catálogo", "file": "caminho",
 * "file2": "caminho (opcional)"}, ...]. Valida tudo antes de gravar.
 */
class LinkGymCatalogImages extends Command
{
    protected $signature = 'cerne:gym-catalog-link-images {mapping : Caminho do JSON de mapeamento} {--dry-run : Só mostra o que seria vinculado}';

    protected $description = 'Vincula fotos de referência aos exercícios do catálogo compartilhado da Academia';

    public function handle(GymCatalogImageService $imagens): int
    {
        $arquivo = (string) $this->argument('mapping');

        if (! is_file($arquivo)) {
            $this->components->error("Arquivo não encontrado: {$arquivo}");

            return self::FAILURE;
        }

        $linhas = json_decode((string) file_get_contents($arquivo), true);
        if (! is_array($linhas)) {
            $this->components->error('O arquivo não é um JSON válido: '.json_last_error_msg());

            return self::FAILURE;
        }

        $erros = [];
        $resolvidas = [];
        foreach ($linhas as $i => $linha) {
            $nome = trim((string) ($linha['exercise'] ?? ''));
            $arquivo1 = (string) ($linha['file'] ?? '');
            $arquivo2 = isset($linha['file2']) ? (string) $linha['file2'] : null;

            if ($nome === '') {
                $erros[] = "[$i]: falta \"exercise\".";

                continue;
            }
            if (! is_file($arquivo1)) {
                $erros[] = "$nome: arquivo não encontrado ({$arquivo1}).";

                continue;
            }
            if ($arquivo2 !== null && ! is_file($arquivo2)) {
                $erros[] = "$nome: segundo arquivo não encontrado ({$arquivo2}).";

                continue;
            }

            $exercicio = GymExerciseCatalog::query()->whereNull('profile_id')->where('name', $nome)->first();
            if ($exercicio === null) {
                $erros[] = "$nome: não existe no catálogo (rode o seeder primeiro).";

                continue;
            }

            $resolvidas[] = ['exercicio' => $exercicio, 'nome' => $nome, 'arquivo1' => $arquivo1, 'arquivo2' => $arquivo2];
        }

        if ($erros !== []) {
            $this->components->error('Mapeamento inválido — nada foi vinculado:');
            foreach ($erros as $erro) {
                $this->line("  - {$erro}");
            }

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');

        if (! $dry) {
            foreach ($resolvidas as $r) {
                $imagens->replacePair($r['exercicio'], $this->uploadedFile($r['arquivo1']), $r['arquivo2'] !== null ? $this->uploadedFile($r['arquivo2']) : null);
            }
        }

        $this->components->info(($dry ? '[SIMULAÇÃO — nada foi gravado] ' : '')."Fotos vinculadas no catálogo: ".count($resolvidas));
        foreach ($resolvidas as $r) {
            $this->line('  - '.$r['nome'].($r['arquivo2'] !== null ? ' (2 quadros)' : ''));
        }

        return self::SUCCESS;
    }

    private function uploadedFile(string $path): UploadedFile
    {
        return new UploadedFile($path, basename($path), mime_content_type($path) ?: null, null, true);
    }
}
