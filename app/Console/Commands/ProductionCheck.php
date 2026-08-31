<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Checklist de produção que se verifica sozinho.
 *
 * Uma lista num documento é lida uma vez e esquecida. Um comando roda
 * antes de cada publicação e falha alto quando algo regrediu — como um
 * APP_DEBUG=true esquecido depois de depurar um problema no servidor.
 */
class ProductionCheck extends Command
{
    protected $signature = 'cerne:check {--strict : Sai com erro se algum item falhar}';

    protected $description = 'Verifica se a instalação está pronta para uso real';

    private int $falhas = 0;

    public function handle(): int
    {
        $this->newLine();
        $this->components->info('Cerne — verificação da instalação');
        $this->newLine();

        $this->secao('Ambiente');
        $this->item('APP_ENV é production', app()->environment('production'),
            'Fora de production o app mostra detalhes internos em erros.');
        $this->item('APP_DEBUG está desligado', ! config('app.debug'),
            'Com debug ligado, um erro expõe variáveis de ambiente e trechos de código.');
        $this->item('APP_URL usa https', str_starts_with((string) config('app.url'), 'https://'),
            'Sessão e cookies seguros dependem de HTTPS.');
        $this->item('APP_KEY definida', filled(config('app.key')),
            'Sem ela a sessão e os dados criptografados não funcionam.');
        $this->item('Fuso horário do Brasil', config('app.timezone') === 'America/Sao_Paulo',
            'Vencimentos e competências dependem do fuso.');

        $this->secao('Sessão e cookies');
        $this->item('Cookie de sessão marcado como seguro', (bool) config('session.secure'),
            'Sem isso o cookie trafega em texto puro se alguém forçar http.');
        $this->item('Cookie de sessão HttpOnly', (bool) config('session.http_only'),
            'Impede que script no navegador leia o cookie.');
        $this->item('SameSite definido', in_array(config('session.same_site'), ['lax', 'strict'], true),
            'Protege contra requisições forjadas de outros sites.');

        $this->secao('Banco de dados');
        $this->item('Conexão com o banco', $this->bancoResponde(),
            'O app não sobe sem banco.');
        $this->item('Migrations aplicadas', $this->migrationsEmDia(),
            'Tabela faltando derruba a primeira tela que a use.');
        $this->item('Sem contas de demonstração', $this->semContasDemo(),
            'ana@cerne.test com senha "password" num servidor real é porta aberta.');

        $this->secao('Filas e agendador');
        $this->item('Fila usa driver de banco', config('queue.default') === 'database',
            'O driver sync travaria a requisição durante a extração de PDF.');
        $this->item('Agendador batendo', $this->agendadorRodou(),
            'Último batimento: '.$this->ultimoBatimento().'. Sem o cron, contas fixas e faturas não avançam sozinhas. Ver DEPLOY.md.');
        $this->item('Fila sem jobs presos', $this->filaSaudavel(),
            'Jobs acumulados indicam que o queue:work não está rodando.');

        $this->secao('Importação por IA');
        $this->item('ANTHROPIC_API_KEY configurada', filled(config('cerne.ai.api_key')),
            'Sem ela os PDFs ficam na fila para sempre.', aviso: true);
        $this->item('Disco de documentos gravável', $this->discoGravavel(),
            'O upload falha silenciosamente sem permissão de escrita.');
        $this->item('Documentos fora da pasta pública', ! str_contains(
            (string) config('filesystems.disks.'.config('cerne.documents.disk').'.root'),
            'public'
        ), 'Extrato bancário em pasta pública é acessível por URL.');

        $this->secao('Notificações');
        $this->item('Chaves VAPID configuradas', filled(config('webpush.vapid.public_key')) && filled(config('webpush.vapid.private_key')),
            'Sem elas, notificação push falha silenciosamente mesmo com o usuário inscrito.', aviso: true);

        $this->secao('Cache');
        $this->item('Configuração em cache', file_exists(base_path('bootstrap/cache/config.php')),
            'Sem cache o Laravel lê e analisa todos os arquivos de config a cada requisição.');
        $this->item('Rotas em cache', file_exists(base_path('bootstrap/cache/routes-v7.php')),
            'Mesmo motivo — e a hospedagem compartilhada tem CPU contada.');

        $this->newLine();

        if ($this->falhas === 0) {
            $this->components->info('Tudo certo.');

            return self::SUCCESS;
        }

        $this->components->error("{$this->falhas} item(ns) precisam de atenção.");

        return $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }

    // -----------------------------------------------------------------

    private function secao(string $titulo): void
    {
        $this->line("  <fg=cyan;options=bold>{$titulo}</>");
    }

    private function item(string $rotulo, bool $ok, string $porque, bool $aviso = false): void
    {
        if ($ok) {
            $this->line("    <fg=green>✓</> {$rotulo}");

            return;
        }

        if ($aviso) {
            $this->line("    <fg=yellow>!</> {$rotulo}");
            $this->line("      <fg=gray>{$porque}</>");

            return;
        }

        $this->falhas++;
        $this->line("    <fg=red>✗</> {$rotulo}");
        $this->line("      <fg=gray>{$porque}</>");
    }

    private function bancoResponde(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function migrationsEmDia(): bool
    {
        try {
            $aplicadas = DB::table('migrations')->count();
            $arquivos = count(glob(database_path('migrations/*.php')));

            return $aplicadas >= $arquivos;
        } catch (\Throwable) {
            return false;
        }
    }

    private function semContasDemo(): bool
    {
        try {
            return ! DB::table('users')->where('email', 'like', '%@cerne.test')->exists();
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * O batimento é carimbado a cada minuto pelo agendador (ver
     * routes/console.php). Cinco minutos de tolerância cobrem planos cujo
     * cron mínimo é de 5 em 5 minutos.
     */
    private function agendadorRodou(): bool
    {
        $batimento = \Illuminate\Support\Facades\Cache::get('cerne:scheduler:heartbeat');

        if ($batimento === null) {
            return false;
        }

        try {
            return \Carbon\CarbonImmutable::parse($batimento)->greaterThan(now()->subMinutes(6));
        } catch (\Throwable) {
            return false;
        }
    }

    /** Quando foi o último batimento — mostrado quando falha. */
    private function ultimoBatimento(): string
    {
        $batimento = \Illuminate\Support\Facades\Cache::get('cerne:scheduler:heartbeat');

        return $batimento === null
            ? 'nunca'
            : \Carbon\CarbonImmutable::parse($batimento)->diffForHumans();
    }

    private function filaSaudavel(): bool
    {
        try {
            $presos = DB::table('jobs')
                ->where('created_at', '<', now()->subHours(2)->timestamp)
                ->count();

            return $presos === 0;
        } catch (\Throwable) {
            return true;
        }
    }

    private function discoGravavel(): bool
    {
        try {
            $disco = Storage::disk(config('cerne.documents.disk'));
            $sonda = config('cerne.documents.path').'/.sonda';
            $disco->put($sonda, 'ok');
            $lido = $disco->get($sonda) === 'ok';
            $disco->delete($sonda);

            return $lido;
        } catch (\Throwable) {
            return false;
        }
    }
}
