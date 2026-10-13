<?php

namespace App\Services;

use App\Enums\LeadKind;
use App\Enums\LeadLostReason;
use App\Enums\LeadQuoteStatus;
use App\Enums\LeadSource;
use App\Enums\LeadStage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Números do funil de um profissional. Uma só definição, fácil de explicar: o conjunto de contatos que ENTRARAM no
 * período e o que aconteceu com cada um até hoje (fechou, foi perdido ou ainda está em andamento). Períodos recentes
 * parecem piores porque parte dos contatos ainda não decidiu; a tela avisa disso.
 *
 * Prêmio e comissão são somados no banco (SUM), sobre as cotações aceitas dos contatos que fecharam: lead com várias
 * cotações aceitas (linhas diferentes da mesma venda) soma todas.
 */
class LeadMetricsService
{
    /**
     * @return array{
     *     entered: int, issued: int, lost: int, open: int,
     *     conversion: ?string, avg_days_to_issue: ?string,
     *     premium: string, commission: string, issued_without_quote: int,
     *     by_source: list<array{source: ?LeadSource, entered: int, issued: int, lost: int, open: int, conversion: ?string}>,
     *     lost_reasons: list<array{reason: ?LeadLostReason, total: int}>
     * }
     */
    public function funnel(User $profissional, LeadKind $kind, CarbonImmutable $de, CarbonImmutable $ate): array
    {
        $base = fn () => DB::table('leads')
            ->where('consultant_id', $profissional->id)
            ->where('kind', $kind->value)
            ->whereBetween('created_at', [$de->startOfDay()->toDateTimeString(), $ate->endOfDay()->toDateTimeString()]);

        $porEtapa = $base()->selectRaw('stage, COUNT(*) AS total')->groupBy('stage')->pluck('total', 'stage');

        $entrou = (int) $porEtapa->sum();
        $emitidos = (int) ($porEtapa[LeadStage::Converted->value] ?? 0);
        $perdidos = (int) ($porEtapa[LeadStage::Lost->value] ?? 0);

        $dias = $emitidos === 0 ? null : $base()
            ->where('stage', LeadStage::Converted->value)
            ->whereNotNull('converted_at')
            ->selectRaw('AVG(DATEDIFF(converted_at, created_at)) AS dias')
            ->value('dias');

        [$premio, $comissao, $comCotacao] = $this->issuedValue($profissional, $kind, $de, $ate);

        return [
            'entered' => $entrou,
            'issued' => $emitidos,
            'lost' => $perdidos,
            'open' => $entrou - $emitidos - $perdidos,
            'conversion' => $this->rate($emitidos, $emitidos + $perdidos),
            'avg_days_to_issue' => $dias === null ? null : number_format((float) $dias, 1, '.', ''),
            'premium' => $premio,
            'commission' => $comissao,
            'issued_without_quote' => max(0, $emitidos - $comCotacao),
            'by_source' => $this->bySource($base),
            'lost_reasons' => $this->lostReasons($base),
        ];
    }

    /**
     * Prêmio anual e comissão do 1º ano das cotações aceitas dos contatos que fecharam.
     *
     * @return array{0: string, 1: string, 2: int} prêmio, comissão e quantos contatos fechados tinham cotação aceita
     */
    private function issuedValue(User $profissional, LeadKind $kind, CarbonImmutable $de, CarbonImmutable $ate): array
    {
        $anual = "(q.premium * CASE q.payment_frequency WHEN 'monthly' THEN 12 WHEN 'quarterly' THEN 4 ELSE 1 END)";

        $linha = DB::table('lead_quotes AS q')
            ->join('leads AS l', 'l.id', '=', 'q.lead_id')
            ->where('l.consultant_id', $profissional->id)
            ->where('l.kind', $kind->value)
            ->where('l.stage', LeadStage::Converted->value)
            ->whereBetween('l.created_at', [$de->startOfDay()->toDateTimeString(), $ate->endOfDay()->toDateTimeString()])
            ->where('q.status', LeadQuoteStatus::Accepted->value)
            ->selectRaw("COALESCE(SUM({$anual}), 0) AS premio")
            ->selectRaw("COALESCE(SUM(CASE WHEN q.commission_type = 'amount' THEN COALESCE(q.commission_amount, 0) ELSE ROUND({$anual} * COALESCE(q.commission_percent, 0) / 100, 2) END), 0) AS comissao")
            ->selectRaw('COUNT(DISTINCT l.id) AS leads')
            ->first();

        return [
            number_format((float) $linha->premio, 2, '.', ''),
            number_format((float) $linha->comissao, 2, '.', ''),
            (int) $linha->leads,
        ];
    }

    private function bySource(\Closure $base): array
    {
        return $base()
            ->selectRaw('source, COUNT(*) AS entrou')
            ->selectRaw("SUM(stage = 'converted') AS emitidos")
            ->selectRaw("SUM(stage = 'lost') AS perdidos")
            ->groupBy('source')
            ->get()
            ->map(function ($l): array {
                $emitidos = (int) $l->emitidos;
                $perdidos = (int) $l->perdidos;

                return [
                    'source' => $l->source === null ? null : LeadSource::tryFrom($l->source),
                    'entered' => (int) $l->entrou,
                    'issued' => $emitidos,
                    'lost' => $perdidos,
                    'open' => (int) $l->entrou - $emitidos - $perdidos,
                    'conversion' => $this->rate($emitidos, $emitidos + $perdidos),
                ];
            })
            ->sortByDesc('entered')
            ->values()
            ->all();
    }

    private function lostReasons(\Closure $base): array
    {
        return $base()
            ->where('stage', LeadStage::Lost->value)
            ->selectRaw('lost_category, COUNT(*) AS total')
            ->groupBy('lost_category')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($l): array => ['reason' => $l->lost_category === null ? null : LeadLostReason::tryFrom($l->lost_category), 'total' => (int) $l->total])
            ->all();
    }

    /** Percentual com uma casa, ou nulo quando ainda não há ninguém decidido (evita "0%" enganoso). */
    private function rate(int $parte, int $total): ?string
    {
        return $total === 0 ? null : number_format($parte * 100 / $total, 1, '.', '');
    }
}
