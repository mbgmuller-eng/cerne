<?php

namespace App\Services\Activities;

use App\Models\ActivityReadiness;
use App\Models\HealthCondition;
use App\Support\Activities\ReadinessQuestions;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * A prontidão antes de um plano de atividades. Qualquer "sim" recomenda liberação médica; sem ela o plano é leve, não progride e a corrida não
 * começa. O Cerne guarda o que a pessoa informou e não confere a liberação. Nunca responde por ela.
 */
class ReadinessService
{
    public const MISSING = 'missing';

    public const EXPIRED = 'expired';

    /** Há um "sim" e a pessoa ainda não disse se tem liberação. */
    public const PENDING = 'pending';

    public const LIGHT = 'light';

    public const CLEARED = 'cleared';

    public const OK = 'ok';

    public function record(): ?ActivityReadiness
    {
        return ActivityReadiness::query()->first();
    }

    public function status(?CarbonImmutable $hoje = null): string
    {
        $r = $this->record();

        if ($r === null) {
            return self::MISSING;
        }

        $hoje ??= CarbonImmutable::today();

        if ($r->answered_on->toImmutable()->addMonths(ReadinessQuestions::VALID_MONTHS)->lt($hoje) || $this->changedSince($r)) {
            return self::EXPIRED;
        }

        if (! $r->hasAnyYes()) {
            return self::OK;
        }

        return match ($r->clearance) {
            'yes' => self::CLEARED,
            'no' => self::LIGHT,
            default => self::PENDING,
        };
    }

    /** Um problema que pede cuidado no exercício, registrado ou alterado depois das respostas, pede responder de novo. */
    private function changedSince(ActivityReadiness $r): bool
    {
        return HealthCondition::query()->where('member_id', app(ProfileContext::class)->memberId())->where('exercise_care', true)
            ->where('status', '!=', 'resolved')->where('updated_at', '>', $r->updated_at)->exists();
    }

    /** As respostas valem e dão para montar um plano (mesmo que leve). */
    public function answered(): bool
    {
        return in_array($this->status(), [self::OK, self::CLEARED, self::LIGHT], true);
    }

    public function allowsProgression(): bool
    {
        return in_array($this->status(), [self::OK, self::CLEARED], true);
    }

    /** Por que um programa que progride não pode começar nem avançar, ou nulo. */
    public function gate(): ?string
    {
        return match ($this->status()) {
            self::OK, self::CLEARED => null,
            self::MISSING => 'Responda antes às perguntas de prontidão.',
            self::EXPIRED => 'As respostas de prontidão venceram ou a sua saúde mudou. Responda de novo.',
            self::PENDING => 'Falta informar se você tem liberação médica, nas perguntas de prontidão.',
            default => 'Sem liberação médica, o programa não progride. Quando tiver a liberação, atualize as perguntas de prontidão.',
        };
    }

    /**
     * @param  array<string, bool|string|null>  $respostas  chave da pergunta => sim ou não; todas obrigatórias
     */
    public function save(array $respostas, ?string $liberacao, ?string $quem, ?string $quando): ActivityReadiness
    {
        $limpas = [];

        foreach (array_keys(ReadinessQuestions::ALL) as $chave) {
            $v = $respostas[$chave] ?? null;

            if (! in_array($v, [true, false, 'yes', 'no'], true)) {
                throw new DomainException('Responda todas as perguntas.');
            }

            $limpas[$chave] = $v === true || $v === 'yes';
        }

        $algumSim = in_array(true, $limpas, true);

        if ($algumSim && ! in_array($liberacao, ['yes', 'no'], true)) {
            throw new DomainException('Informe se você já tem liberação médica.');
        }

        if ($algumSim && $liberacao === 'yes' && (trim((string) $quem) === '' || (string) $quando === '')) {
            throw new DomainException('Informe quem liberou e quando.');
        }

        $dados = [
            'answers' => $limpas,
            'clearance' => $algumSim ? $liberacao : null,
            'cleared_by' => $algumSim && $liberacao === 'yes' ? trim((string) $quem) : null,
            'cleared_on' => $algumSim && $liberacao === 'yes' ? $quando : null,
            'answered_on' => CarbonImmutable::today()->toDateString(),
        ];

        $r = $this->record();

        if ($r === null) {
            try {
                return ActivityReadiness::create($dados);
            } catch (UniqueConstraintViolationException) {
                $r = ActivityReadiness::query()->firstOrFail();
            }
        }

        $r->fill($dados);
        // Mesmo sem mudar nada, responder de novo renova: é o `updated_at` que diz se a saúde mudou depois.
        $r->updated_at = now();
        $r->save();

        return $r;
    }
}
