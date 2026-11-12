<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/**
 * Filtro de dado pessoal do arquivo de log (`tap` dos canais em config/logging.php).
 *
 * Por que existe: erro de banco entra no log com a consulta E os valores ligados (`... SQL: select * from users where email =
 * 'fulana@...'`), e o log fica em arquivo de texto no servidor, copiado por backup, lido por quem tiver acesso à hospedagem. Aqui
 * o texto perde e-mail, CPF, número longo e token, a consulta perde os valores literais, e a exceção vira "classe, mensagem
 * limpa, arquivo:linha e só as chamadas do próprio app" (sem argumento de função nenhum).
 *
 * É a mesma ideia do `ErrorRecorder::scrub` (relatório de erros), em pontos diferentes do caminho.
 */
class ScrubLogs
{
    public function __invoke(Logger $logger): void
    {
        $logger->getLogger()->pushProcessor(new class implements ProcessorInterface
        {
            public function __invoke(LogRecord $registro): LogRecord
            {
                return $registro->with(
                    message: ScrubLogs::text($registro->message),
                    context: ScrubLogs::context($registro->context),
                );
            }
        });
    }

    public static function text(string $texto): string
    {
        // Consulta com valores: troca os literais entre aspas e os números depois de operador, sobra só o esqueleto da consulta.
        if (str_contains($texto, 'SQL:')) {
            $texto = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", "'?'", $texto) ?? $texto;
            $texto = preg_replace('/(=|<|>|\bin\s*\(|\blike)\s*-?\d[\d.]*/i', '$1 ?', $texto) ?? $texto;
        }

        $texto = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[e-mail]', $texto) ?? $texto;
        $texto = preg_replace('/\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b/', '[cpf]', $texto) ?? $texto;
        $texto = preg_replace('/\b\d{9,}\b/', '[número]', $texto) ?? $texto;

        return preg_replace('/\b[A-Za-z0-9_\-]{40,}\b/', '[token]', $texto) ?? $texto;
    }

    /**
     * @param  array<mixed>  $contexto
     * @return array<mixed>
     */
    public static function context(array $contexto): array
    {
        foreach ($contexto as $chave => $valor) {
            if ($valor instanceof Throwable) {
                $contexto[$chave] = self::throwable($valor);
            } elseif (is_string($valor)) {
                $contexto[$chave] = self::text($valor);
            } elseif (is_array($valor)) {
                $contexto[$chave] = self::context($valor);
            }
        }

        return $contexto;
    }

    private static function throwable(Throwable $e): string
    {
        $base = str_replace('\\', '/', base_path()).'/';
        $linhas = [get_class($e).': '.self::text($e->getMessage()).' em '.self::relativo($e->getFile(), $base).':'.$e->getLine()];

        foreach ($e->getTrace() as $quadro) {
            $arquivo = str_replace('\\', '/', (string) ($quadro['file'] ?? ''));

            if ($arquivo === '' || ! str_starts_with($arquivo, $base) || str_contains($arquivo, '/vendor/')) {
                continue;
            }

            $linhas[] = '  '.self::relativo($arquivo, $base).':'.($quadro['line'] ?? 0).' '.($quadro['class'] ?? '').($quadro['type'] ?? '').($quadro['function'] ?? '');

            if (count($linhas) >= 15) {
                break;
            }
        }

        if ($e->getPrevious() !== null) {
            $linhas[] = 'Causa: '.self::throwable($e->getPrevious());
        }

        return implode("\n", $linhas);
    }

    private static function relativo(string $caminho, string $base): string
    {
        $caminho = str_replace('\\', '/', $caminho);

        return str_starts_with($caminho, $base) ? substr($caminho, strlen($base)) : $caminho;
    }
}
