<?php

namespace App\Services\ErrorTracking;

use App\Models\ErrorLog;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Anota todo erro da plataforma, na hora, agrupado: o mesmo erro (classe + arquivo + linha) é uma linha só, com contador, contagem por dia e
 * quantos usuários bateram nele. Ligado em bootstrap/app.php (`reportable`), então pega erro de tela, de ação do Livewire, de fila e de
 * rotina agendada sem ninguém precisar chamar nada.
 *
 * Duas regras que valem mais que o resto:
 * 1. NUNCA derruba quem estava fazendo outra coisa. Qualquer falha aqui (inclusive o banco fora do ar, que é justamente quando mais
 *    se quer o registro) vira uma linha em `storage/logs/erros-pendentes.jsonl`, importada na próxima rodada do relatório.
 * 2. NUNCA guarda dado do cliente. A mensagem perde e-mail, CPF e número longo; a consulta SQL entra com `?` no lugar dos valores; a
 *    rota é o padrão (`/convite/{token}`) e não o endereço; o usuário é só o id; o corpo da requisição nem é lido.
 */
class ErrorRecorder
{
    private static bool $recording = false;

    public function record(Throwable $e): void
    {
        if (! config('cerne.error_tracking.enabled') || $this->ignored($e) || self::$recording) {
            return;
        }

        self::$recording = true;
        $dados = null;

        try {
            $dados = $this->describe($e);
            $this->store($dados);
        } catch (Throwable) {
            if ($dados !== null) {
                $this->toFallback($dados);
            }
        } finally {
            self::$recording = false;
        }
    }

    /** O que não é falha nossa: validação, login, permissão, página que não existe e afins (qualquer resposta abaixo de 500). */
    public function ignored(Throwable $e): bool
    {
        if ($e instanceof ValidationException || $e instanceof AuthenticationException || $e instanceof AuthorizationException || $e instanceof TokenMismatchException) {
            return true;
        }

        return $e instanceof HttpExceptionInterface && $e->getStatusCode() < 500;
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(Throwable $e): array
    {
        // Web é o que tem rota (ou o que não roda no terminal): num comando artisan o "request" é só um enfeite sem rota.
        $request = app()->bound('request') ? request() : null;
        $rota = $request?->route();
        $web = $request !== null && ($rota !== null || ! app()->runningInConsole());

        return [
            'fingerprint' => sha1(get_class($e).'|'.$this->relative($e->getFile()).'|'.$e->getLine()),
            'exception_class' => mb_substr(get_class($e), 0, 255),
            'message' => $this->message($e),
            'file' => mb_substr($this->relative($e->getFile()), 0, 500),
            'line' => $e->getLine(),
            'trace' => $this->trace($e),
            // Nome da rota, ou o padrão do endereço: nunca o endereço de verdade (tem token de convite, id de cliente...).
            'origin' => $web ? mb_substr((string) ($rota?->getName() ?? ($rota !== null ? '/'.ltrim($rota->uri(), '/') : 'sem rota')), 0, 255) : 'artisan '.mb_substr((string) ($_SERVER['argv'][1] ?? ''), 0, 120),
            'http_method' => $web ? $request?->method() : null,
            'status_code' => $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500,
            'context' => $web ? 'web' : 'console',
            'user_id' => $this->userId(),
            'occurred_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * Grava (ou soma). Uma única instrução atômica por erro: o índice único da impressão digital resolve a corrida entre dois processos,
     * e um erro RESOLVIDO que volta reabre sozinho e conta como regressão.
     *
     * @param  array<string, mixed>  $d
     */
    public function store(array $d): void
    {
        $quando = CarbonImmutable::parse($d['occurred_at'] ?? now());
        $agora = $quando->toDateTimeString();

        // A ordem dos SET importa (o banco avalia da esquerda para a direita): regressão e reabertura olham o status ANTES de ele mudar.
        DB::statement(
            "INSERT INTO error_logs (fingerprint, exception_class, message, file, line, trace, origin, http_method, status_code, context, occurrences, first_seen_at, last_seen_at, status, regressions, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, 'new', 0, ?, ?)
             ON DUPLICATE KEY UPDATE
                occurrences = occurrences + 1,
                last_seen_at = VALUES(last_seen_at),
                message = VALUES(message),
                trace = VALUES(trace),
                origin = VALUES(origin),
                status_code = VALUES(status_code),
                regressions = regressions + (status = 'resolved'),
                reopened_at = IF(status = 'resolved', VALUES(last_seen_at), reopened_at),
                resolved_at = IF(status = 'resolved', NULL, resolved_at),
                status = IF(status = 'resolved', 'new', status),
                updated_at = VALUES(updated_at)",
            [
                $d['fingerprint'], $d['exception_class'], $d['message'], $d['file'], $d['line'], $d['trace'], $d['origin'], $d['http_method'],
                $d['status_code'], $d['context'], $agora, $agora, $agora, $agora,
            ],
        );

        $id = DB::table('error_logs')->where('fingerprint', $d['fingerprint'])->value('id');

        DB::statement(
            'INSERT INTO error_log_days (error_log_id, day, count) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE count = count + 1',
            [$id, $quando->toDateString()],
        );

        if (! empty($d['user_id'])) {
            DB::table('error_log_users')->insertOrIgnore(['error_log_id' => $id, 'user_id' => $d['user_id']]);
        }
    }

    /**
     * Importa o que ficou no arquivo de reserva (erros que chegaram com o banco fora do ar) e esvazia o arquivo.
     *
     * @return int quantas ocorrências voltaram para o banco
     */
    public function importPending(): int
    {
        $arquivo = $this->fallbackPath();

        if (! is_file($arquivo)) {
            return 0;
        }

        // Renomeia antes de ler: uma ocorrência que chegue durante a importação cai num arquivo novo, nunca se perde.
        $trabalho = $arquivo.'.importando';
        if (! @rename($arquivo, $trabalho)) {
            return 0;
        }

        $importados = 0;
        $restantes = [];

        foreach (file($trabalho, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linha) {
            $d = json_decode($linha, true);

            if (! is_array($d) || ! isset($d['fingerprint'])) {
                continue;
            }

            try {
                $this->store($d);
                $importados++;
            } catch (Throwable) {
                $restantes[] = $linha;
            }
        }

        @unlink($trabalho);

        foreach ($restantes as $linha) {
            @file_put_contents($arquivo, $linha."\n", FILE_APPEND | LOCK_EX);
        }

        return $importados;
    }

    /**
     * Apaga o que já foi tratado há muito tempo: resolvido e ignorado depois de 90 dias parados, aberto depois de 180.
     *
     * @return int quantos tipos de erro saíram
     */
    public function prune(?CarbonImmutable $hoje = null): int
    {
        $hoje ??= CarbonImmutable::now();

        $fechados = ErrorLog::query()->whereIn('status', [ErrorLog::RESOLVED, ErrorLog::IGNORED])->where('last_seen_at', '<', $hoje->subDays(90))->delete();
        $abertos = ErrorLog::query()->where('status', ErrorLog::NEW)->where('last_seen_at', '<', $hoje->subDays(180))->delete();

        return $fechados + $abertos;
    }

    // --- Limpeza do que vai para o banco -----------------------------------------------------------------------------------

    private function message(Throwable $e): string
    {
        // A mensagem de erro de consulta traz a SQL com os valores no lugar dos `?`: pode ser o e-mail ou o saldo de alguém. Fica só o
        // motivo do banco e a SQL com os `?`.
        $mensagem = $e instanceof QueryException
            ? Str::before($e->getMessage(), ' (Connection:').' [SQL: '.$e->getSql().']'
            : $e->getMessage();

        return $this->scrub($mensagem);
    }

    /** Tira o que identifica gente: e-mail, CPF, número longo (conta, cartão, telefone) e token. */
    public function scrub(string $texto): string
    {
        $texto = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[e-mail]', $texto) ?? $texto;
        $texto = preg_replace('/\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b/', '[cpf]', $texto) ?? $texto;
        $texto = preg_replace('/\b\d{9,}\b/', '[número]', $texto) ?? $texto;
        $texto = preg_replace('/\b[A-Za-z0-9_\-]{40,}\b/', '[token]', $texto) ?? $texto;

        return mb_substr($texto, 0, 600);
    }

    /** Só o caminho dentro do app (arquivo:linha e quem chamou), sem argumento nenhum. */
    private function trace(Throwable $e): string
    {
        $base = str_replace('\\', '/', base_path()).'/';
        $linhas = [$this->relative($e->getFile()).':'.$e->getLine()];

        foreach ($e->getTrace() as $quadro) {
            $arquivo = str_replace('\\', '/', (string) ($quadro['file'] ?? ''));

            if ($arquivo === '' || ! str_starts_with($arquivo, $base) || str_contains($arquivo, '/vendor/')) {
                continue;
            }

            $linhas[] = $this->relative($arquivo).':'.($quadro['line'] ?? 0).'  '.($quadro['class'] ?? '').($quadro['type'] ?? '').($quadro['function'] ?? '');

            if (count($linhas) >= 14) {
                break;
            }
        }

        return implode("\n", $linhas);
    }

    private function relative(string $caminho): string
    {
        $base = str_replace('\\', '/', base_path()).'/';
        $caminho = str_replace('\\', '/', $caminho);

        return str_starts_with($caminho, $base) ? substr($caminho, strlen($base)) : $caminho;
    }

    private function userId(): ?string
    {
        try {
            $id = auth()->id();

            return $id === null ? null : (string) $id;
        } catch (Throwable) {
            return null;
        }
    }

    // --- Reserva (banco fora do ar) -----------------------------------------------------------------------------------------

    /** @param  array<string, mixed>  $dados */
    private function toFallback(array $dados): void
    {
        try {
            @file_put_contents($this->fallbackPath(), json_encode($dados, JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // Sem banco e sem disco não há o que fazer; o log diário padrão do Laravel ainda tem o erro.
        }
    }

    public function fallbackPath(): string
    {
        return storage_path('logs/erros-pendentes.jsonl');
    }
}
