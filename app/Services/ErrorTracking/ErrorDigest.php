<?php

namespace App\Services\ErrorTracking;

use App\Mail\ErrorDigestMail;
use App\Models\ErrorLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * O relatório diário de erros: o que aconteceu num dia, do mais frequente para o menos, com o que é novo e o que voltou depois de
 * resolvido em destaque. Sai uma vez por dia (índice único em `error_report_digests`) para quem estiver em `ERROR_REPORT_EMAILS` ou,
 * sem isso, para os administradores da plataforma.
 */
class ErrorDigest
{
    public function __construct(private readonly ErrorRecorder $recorder) {}

    /** Quantos tipos de erro entram na lista do e-mail; o resto vira "e mais N". */
    public const LIST_LIMIT = 15;

    /**
     * @return list<string>
     */
    public function recipients(): array
    {
        $configurados = array_values(array_filter(array_map('trim', (array) config('cerne.error_tracking.report_to'))));

        if ($configurados !== []) {
            return $configurados;
        }

        return User::query()->where('is_platform_admin', true)->where('is_active', true)->pluck('email')->all();
    }

    /**
     * Os números do dia.
     *
     * @return array{
     *     day: CarbonImmutable, occurrences: int, types: int, new: int, reopened: int, open_total: int, users: int,
     *     rows: list<array<string, mixed>>, more: int
     * }
     */
    public function build(CarbonImmutable $dia): array
    {
        $inicio = $dia->startOfDay();
        $fim = $dia->endOfDay();

        $linhas = DB::table('error_log_days as d')
            ->join('error_logs as e', 'e.id', '=', 'd.error_log_id')
            ->where('d.day', $dia->toDateString())
            ->selectRaw('e.id, e.exception_class, e.message, e.origin, e.file, e.line, e.status, e.occurrences, e.first_seen_at, e.reopened_at, e.regressions, d.count as day_count,
                (select count(*) from error_log_users u where u.error_log_id = e.id) as users')
            ->orderByDesc('d.count')
            ->orderBy('e.id')
            ->get();

        $rows = $linhas->map(fn ($l) => [
            'id' => $l->id,
            'class' => class_basename($l->exception_class),
            'message' => mb_substr((string) $l->message, 0, 220),
            'origin' => $l->origin,
            'where' => $l->file.':'.$l->line,
            'status' => $l->status,
            'day_count' => (int) $l->day_count,
            'total' => (int) $l->occurrences,
            'users' => (int) $l->users,
            'is_new' => CarbonImmutable::parse($l->first_seen_at)->betweenIncluded($inicio, $fim),
            'reopened' => $l->reopened_at !== null && CarbonImmutable::parse($l->reopened_at)->betweenIncluded($inicio, $fim),
            'regressions' => (int) $l->regressions,
        ])->all();

        return [
            'day' => $dia,
            'occurrences' => (int) $linhas->sum('day_count'),
            'types' => $linhas->count(),
            'new' => count(array_filter($rows, fn (array $r) => $r['is_new'])),
            'reopened' => count(array_filter($rows, fn (array $r) => $r['reopened'])),
            'open_total' => ErrorLog::query()->open()->count(),
            'users' => (int) DB::table('error_log_users')->whereIn('error_log_id', $linhas->pluck('id'))->distinct()->count('user_id'),
            'rows' => array_slice($rows, 0, self::LIST_LIMIT),
            'more' => max(0, count($rows) - self::LIST_LIMIT),
        ];
    }

    /**
     * Manda o relatório do dia. Sem `$forcar`, só uma vez por dia.
     *
     * @return array{status: string, to: list<string>, occurrences: int}
     */
    public function send(CarbonImmutable $dia, bool $forcar = false): array
    {
        // Erros que chegaram com o banco fora do ar entram antes de contar.
        $this->recorder->importPending();

        $destinos = $this->recipients();

        if ($destinos === []) {
            return ['status' => 'sem destinatário', 'to' => [], 'occurrences' => 0];
        }

        $dados = $this->build($dia);
        $reservou = $forcar || DB::table('error_report_digests')->insertOrIgnore([
            'sent_on' => $dia->toDateString(), 'occurrences' => $dados['occurrences'], 'created_at' => now(), 'updated_at' => now(),
        ]) === 1;

        if (! $reservou) {
            return ['status' => 'já enviado', 'to' => $destinos, 'occurrences' => $dados['occurrences']];
        }

        try {
            Mail::to($destinos)->send(new ErrorDigestMail($dados));
        } catch (Throwable $e) {
            // Solta a reserva: o próximo disparo tenta de novo em vez de achar que já foi.
            if (! $forcar) {
                DB::table('error_report_digests')->where('sent_on', $dia->toDateString())->delete();
            }

            throw $e;
        }

        return ['status' => 'enviado', 'to' => $destinos, 'occurrences' => $dados['occurrences']];
    }
}
