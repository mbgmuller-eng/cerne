<?php

namespace App\Support\Health;

use App\Models\HealthAppointment;
use App\Models\HealthExamReport;
use App\Models\HealthFollowup;
use Illuminate\Database\Eloquent\Model;

/**
 * De onde veio um problema de saúde ou uma limitação: uma consulta, um exame, um acompanhamento ou o passado, informado pela pessoa. O valor
 * vem do navegador ('past', 'followup:ID', 'appointment:ID', 'exam:ID') e só vale se o registro for da MESMA pessoa e visível a quem grava.
 */
final class HealthOrigins
{
    public const PAST = 'past';

    private const KINDS = ['followup' => [HealthFollowup::class, 'followup_id'], 'appointment' => [HealthAppointment::class, 'appointment_id'], 'exam' => [HealthExamReport::class, 'exam_report_id']];

    /** @return array<string, string> valor => rótulo, para o <select> */
    public static function options(string $membroId): array
    {
        $opcoes = [self::PAST => 'Do passado, informado por mim'];

        foreach (HealthFollowup::query()->where('member_id', $membroId)->orderByDesc('started_on')->limit(30)->get() as $a) {
            $opcoes['followup:'.$a->id] = self::describe($a);
        }

        foreach (HealthAppointment::query()->where('member_id', $membroId)->where('scheduled_at', '<=', now())->where('scheduled_at', '>=', now()->subYears(3))->orderByDesc('scheduled_at')->limit(30)->get() as $c) {
            $opcoes['appointment:'.$c->id] = self::describe($c);
        }

        foreach (HealthExamReport::query()->where('member_id', $membroId)->orderByDesc('performed_on')->limit(30)->get() as $e) {
            $opcoes['exam:'.$e->id] = self::describe($e);
        }

        return $opcoes;
    }

    /**
     * As três colunas de origem. Um valor que não é da pessoa (ou não existe) é recusado com 404.
     *
     * @return array{followup_id: ?string, appointment_id: ?string, exam_report_id: ?string}
     */
    public static function columns(string $valor, string $membroId): array
    {
        $colunas = ['followup_id' => null, 'appointment_id' => null, 'exam_report_id' => null];
        [$tipo, $id] = array_pad(explode(':', $valor, 2), 2, null);

        if ($id === null || ! isset(self::KINDS[$tipo])) {
            return $colunas;
        }

        [$classe, $coluna] = self::KINDS[$tipo];
        $colunas[$coluna] = $classe::query()->where('member_id', $membroId)->findOrFail($id)->getKey();

        return $colunas;
    }

    /** O valor do <select> a partir do que está gravado. */
    public static function valueOf(Model $registro): string
    {
        foreach (self::KINDS as $tipo => [, $coluna]) {
            if ($registro->getAttribute($coluna) !== null) {
                return $tipo.':'.$registro->getAttribute($coluna);
            }
        }

        return self::PAST;
    }

    /** O texto da origem de um registro. Quem não enxerga a origem lê só "informado no Cerne". */
    public static function labelOf(Model $registro): string
    {
        $valor = self::valueOf($registro);

        if ($valor === self::PAST) {
            return 'Do passado, informado por quem registrou';
        }

        [$tipo, $id] = explode(':', $valor, 2);
        $origem = self::KINDS[$tipo][0]::query()->find($id);

        return $origem !== null ? self::describe($origem) : 'Informado no Cerne';
    }

    private static function describe(Model $origem): string
    {
        return match (true) {
            $origem instanceof HealthFollowup => 'Acompanhamento: '.$origem->title,
            $origem instanceof HealthAppointment => 'Consulta ou exame de '.$origem->scheduled_at->format('d/m/Y').': '.$origem->title,
            $origem instanceof HealthExamReport => 'Resultado de exame de '.($origem->performed_on?->format('d/m/Y') ?? 'data não informada').($origem->exam_name ? ': '.$origem->exam_name : ''),
            default => 'Informado no Cerne',
        };
    }
}
