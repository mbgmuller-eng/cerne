<?php

namespace App\Services;

use App\Enums\IncomeSource;
use App\Enums\IncomeStatus;
use App\Models\FeeAgreement;
use App\Models\IncomeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Transforma o honorário fixo combinado com um cliente nos recebimentos previstos de cada vencimento.
 *
 * Só prevê do mês atual em diante: o histórico de um contrato antigo o profissional lança à mão, senão cada mês já
 * passado nasceria como "atrasado" e ele teria que dar baixa em tudo.
 *
 * Idempotente (regra 4 do CLAUDE.md): o índice único (contrato, vencimento) segura a rotina rodando duas vezes ou em
 * paralelo, e um previsto que o profissional cancelou não ressuscita porque a linha continua lá.
 */
class FeeForecastService
{
    /** Quanto à frente se enxerga o que vai entrar. */
    public const HORIZON_MONTHS = 12;

    /** Cria os vencimentos que ainda não existem. Devolve quantos nasceram. */
    public function extend(FeeAgreement $agreement, ?CarbonImmutable $hoje = null): int
    {
        if (! $agreement->is_active) {
            return 0;
        }

        $hoje ??= CarbonImmutable::today();
        $primeiroMes = $hoje->startOfMonth();
        $limite = $hoje->addMonthsNoOverflow(self::HORIZON_MONTHS)->endOfMonth()->startOfDay();
        $fim = $agreement->ends_on !== null && $agreement->ends_on->lt($limite) ? CarbonImmutable::parse($agreement->ends_on) : $limite;
        $passo = $agreement->period->months();
        $inicio = CarbonImmutable::parse($agreement->starts_on);
        $criados = 0;

        for ($k = 0; ; $k++) {
            $vencimento = $inicio->addMonthsNoOverflow($k * $passo);

            if ($vencimento->gt($fim)) {
                break;
            }

            if ($vencimento->lt($primeiroMes)) {
                continue;
            }

            try {
                IncomeEntry::query()->create([
                    'professional_id' => $agreement->professional_id,
                    'profile_id' => $agreement->profile_id,
                    'fee_agreement_id' => $agreement->id,
                    'source' => IncomeSource::FixedFee,
                    'status' => IncomeStatus::Expected,
                    'description' => 'Honorário '.mb_strtolower($agreement->period->label()),
                    'due_on' => $vencimento->toDateString(),
                    'amount' => $agreement->amount,
                ]);
                $criados++;
            } catch (UniqueConstraintViolationException) {
                // Já existe (outra execução, ou o profissional já mexeu nele): é o que a idempotência pede.
            }
        }

        return $criados;
    }

    /**
     * Depois de mudar valor, periodicidade ou datas do contrato: o que ainda é só previsão sai e volta recalculado.
     * O que já foi recebido ou cancelado fica como está.
     */
    public function resync(FeeAgreement $agreement, ?CarbonImmutable $hoje = null): int
    {
        $hoje ??= CarbonImmutable::today();

        IncomeEntry::query()
            ->where('fee_agreement_id', $agreement->id)
            ->where('status', IncomeStatus::Expected)
            ->where('due_on', '>=', $hoje->startOfMonth()->toDateString())
            ->delete();

        return $this->extend($agreement, $hoje);
    }

    /** Rotina diária: estende o horizonte de todos os contratos ativos. */
    public function runDailyMaintenance(?CarbonImmutable $hoje = null): array
    {
        $contratos = 0;
        $criados = 0;

        FeeAgreement::query()->where('is_active', true)->orderBy('id')->chunk(200, function ($lote) use (&$contratos, &$criados, $hoje): void {
            foreach ($lote as $agreement) {
                $contratos++;
                $criados += $this->extend($agreement, $hoje);
            }
        });

        return ['contratos' => $contratos, 'previstos_criados' => $criados];
    }
}
