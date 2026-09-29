<?php

namespace App\Console\Commands;

use App\Models\GymExerciseCatalog;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

    public function handle(): int
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
                $this->setImage($r['exercicio'], $this->uploadedFile($r['arquivo1']), $r['arquivo2'] !== null ? $this->uploadedFile($r['arquivo2']) : null);
            }
        }

        $this->components->info(($dry ? '[SIMULAÇÃO — nada foi gravado] ' : '')."Fotos vinculadas no catálogo: ".count($resolvidas));
        foreach ($resolvidas as $r) {
            $this->line('  - '.$r['nome'].($r['arquivo2'] !== null ? ' (2 quadros)' : ''));
        }

        return self::SUCCESS;
    }

    /** Mesma lógica de GymPlanService::setExerciseImage(), mas pro model do catálogo (sem member_id). */
    private function setImage(GymExerciseCatalog $exercicio, UploadedFile $file, ?UploadedFile $file2): void
    {
        $disco = Storage::disk(config('cerne.gym_images.disk'));
        $pasta = config('cerne.gym_images.path').'/catalogo';

        if ($exercicio->image_path !== null) {
            $disco->delete($exercicio->image_path);
        }
        if ($exercicio->image_path_2 !== null) {
            $disco->delete($exercicio->image_path_2);
        }

        $caminho1 = $file->storeAs($pasta, Str::uuid().'.'.$file->extension(), config('cerne.gym_images.disk'));
        $caminho2 = $file2 !== null
            ? $file2->storeAs($pasta, Str::uuid().'.'.$file2->extension(), config('cerne.gym_images.disk'))
            : null;

        $exercicio->update(['image_path' => $caminho1, 'image_path_2' => $caminho2]);
    }

    private function uploadedFile(string $path): UploadedFile
    {
        return new UploadedFile($path, basename($path), mime_content_type($path) ?: null, null, true);
    }
}
