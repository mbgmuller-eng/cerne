<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\GymHistoryImporter;
use App\Support\ProfileContext;
use Illuminate\Console\Command;

/**
 * Importa um histórico de treino (JSON revisado) para a conta de UMA
 * pessoa. É comando de servidor, não tela: o arquivo tem dado de saúde e
 * só quem tem acesso ao servidor deve poder aplicá-lo.
 *
 * Sempre rode primeiro com --dry-run: mostra o que seria criado sem
 * gravar nada, e a validação acusa o arquivo inteiro de uma vez.
 */
class ImportGymHistory extends Command
{
    protected $signature = 'cerne:gym-import {file : Caminho do JSON} {--email= : E-mail da pessoa dona do treino} {--dry-run : Só mostra o que seria importado}';

    protected $description = 'Importa histórico de academia (JSON revisado) para a conta de uma pessoa';

    public function handle(GymHistoryImporter $importer, ProfileContext $context): int
    {
        $arquivo = (string) $this->argument('file');
        $email = (string) $this->option('email');

        if ($email === '') {
            $this->components->error('Informe --email=<conta dona do treino>.');

            return self::FAILURE;
        }

        if (! is_file($arquivo)) {
            $this->components->error("Arquivo não encontrado: {$arquivo}");

            return self::FAILURE;
        }

        $dados = json_decode((string) file_get_contents($arquivo), true);
        if (! is_array($dados)) {
            $this->components->error('O arquivo não é um JSON válido: '.json_last_error_msg());

            return self::FAILURE;
        }

        $usuario = User::query()->where('email', $email)->first();
        // Titular usa o perfil próprio; cônjuge (sem perfil próprio) o perfil do casal — mesma regra do SetProfileContext.
        $perfil = $usuario?->ownedProfiles()->first()
            ?? $usuario?->memberships()->where('is_active', true)->first()?->profile;
        $membro = $perfil?->memberFor($usuario);

        if ($membro === null) {
            $this->components->error("Não achei um perfil com membro para {$email}. O treino é pessoal: precisa ser a conta da própria pessoa (titular ou cônjuge com login).");

            return self::FAILURE;
        }

        // Mesmo contexto que a própria pessoa teria logada: o escopo de
        // saúde e o carimbo de dono valem igual — nada é gravado "por cima".
        $context->set($perfil, $membro);

        $erros = $importer->validate($dados);
        if ($erros !== []) {
            $this->components->error('Arquivo inválido — nada foi gravado:');
            foreach ($erros as $erro) {
                $this->line("  - {$erro}");
            }

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $r = $importer->import($dados, $dry);

        $this->components->info(($dry ? '[SIMULAÇÃO — nada foi gravado] ' : '')."Importação para {$usuario->name} ({$email})");
        $this->table(['Item', 'Quantidade'], [
            ['Plano novo', $r['plan_created'] ? 'sim' : 'não (já existia)'],
            ['Treinos novos', $r['workouts']],
            ['Exercícios novos no catálogo', $r['exercises']],
            ['Exercícios novos nos treinos', $r['plan_exercises']],
            ['Equipamentos novos', $r['equipment']],
            ['Sessões importadas', $r['sessions']],
            ['Sessões puladas (já existiam)', $r['skipped_sessions']],
            ['Séries importadas', $r['sets']],
        ]);

        return self::SUCCESS;
    }
}
