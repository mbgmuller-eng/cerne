<?php

namespace App\Console\Commands;

use App\Models\GymExercise;
use App\Models\User;
use App\Services\GymPlanService;
use App\Support\ProfileContext;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;

/**
 * Vincula fotos de referência (baixadas de um banco de imagens de domínio
 * público, revisadas por fora antes de chegar aqui — mesma regra do dado
 * extraído por IA: revisão humana antes de virar dado do app) aos
 * exercícios já cadastrados de UMA pessoa.
 *
 * O mapeamento é um JSON: [{"exercise": "Nome exato", "file": "caminho",
 * "file2": "caminho (opcional, segundo quadro)"}, ...]. Valida tudo —
 * nome existe, arquivo existe — antes de gravar uma linha.
 */
class LinkGymExerciseImages extends Command
{
    protected $signature = 'cerne:gym-link-images {mapping : Caminho do JSON de mapeamento} {--email= : E-mail da pessoa dona dos exercícios} {--dry-run : Só mostra o que seria vinculado}';

    protected $description = 'Vincula fotos de referência aos exercícios já cadastrados de uma pessoa';

    public function handle(GymPlanService $service, ProfileContext $context): int
    {
        $arquivo = (string) $this->argument('mapping');
        $email = (string) $this->option('email');

        if ($email === '') {
            $this->components->error('Informe --email=<conta dona dos exercícios>.');

            return self::FAILURE;
        }

        if (! is_file($arquivo)) {
            $this->components->error("Arquivo não encontrado: {$arquivo}");

            return self::FAILURE;
        }

        $linhas = json_decode((string) file_get_contents($arquivo), true);
        if (! is_array($linhas)) {
            $this->components->error('O arquivo não é um JSON válido: '.json_last_error_msg());

            return self::FAILURE;
        }

        $usuario = User::query()->where('email', $email)->first();
        // Titular usa o perfil próprio; cônjuge (sem perfil próprio) o do casal — mesma regra do SetProfileContext.
        $perfil = $usuario?->ownedProfiles()->first()
            ?? $usuario?->memberships()->where('is_active', true)->first()?->profile;
        $membro = $perfil?->memberFor($usuario);

        if ($membro === null) {
            $this->components->error("Não achei um perfil com membro para {$email}.");

            return self::FAILURE;
        }

        $context->set($perfil, $membro);

        // Valida tudo primeiro: nome tem que existir no catálogo da pessoa,
        // arquivo tem que existir no disco — nada é gravado se algo faltar.
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

            $exercicio = GymExercise::query()->where('name', $nome)->first();
            if ($exercicio === null) {
                $erros[] = "$nome: não existe no catálogo de {$usuario->name}.";

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
                $service->setExerciseImage(
                    $r['exercicio'],
                    $this->uploadedFile($r['arquivo1']),
                    $r['arquivo2'] !== null ? $this->uploadedFile($r['arquivo2']) : null,
                );
            }
        }

        $this->components->info(($dry ? '[SIMULAÇÃO — nada foi gravado] ' : '')."Fotos vinculadas para {$usuario->name} ({$email}): ".count($resolvidas));
        foreach ($resolvidas as $r) {
            $this->line('  - '.$r['nome'].($r['arquivo2'] !== null ? ' (2 quadros)' : ''));
        }

        return self::SUCCESS;
    }

    private function uploadedFile(string $path): UploadedFile
    {
        // `test: true` deixa usar um arquivo comum como se fosse um upload
        // de formulário — sem isso o UploadedFile exige uma requisição
        // HTTP de verdade por trás.
        return new UploadedFile($path, basename($path), mime_content_type($path) ?: null, null, true);
    }
}
