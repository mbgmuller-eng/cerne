<?php

namespace App\Services\Activities;

use App\Enums\ActivityRecordStatus;
use App\Enums\ActivityType;
use App\Models\ActivityCommitment;
use App\Models\ActivityProfile;
use App\Models\ActivityRecord;
use App\Models\GymSession;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

/**
 * Perfil, compromissos e diário de atividades. Tudo roda sob o ProfileContext da pessoa: o escopo de saúde pessoal filtra e a trait carimba o
 * dono, como na Academia.
 */
class ActivityService
{
    /** Teto de um lançamento de vários dias (um ano de uma atividade quase diária). */
    public const MAX_BATCH = 200;

    public const MAX_MINUTES = 600;

    /** O perfil da pessoa, ou um ainda não gravado com os padrões (abrir a tela não grava nada). */
    public function profile(): ActivityProfile
    {
        return ActivityProfile::query()->first()
            ?? new ActivityProfile(['goals' => [], 'gym_days' => [], 'session_minutes' => 60, 'consider_limitations' => true]);
    }

    /**
     * @param  list<string>  $objetivos  na ordem de prioridade
     * @param  list<int>  $diasDaAcademia  0 (segunda) a 6 (domingo)
     */
    public function saveProfile(array $objetivos, array $diasDaAcademia, int $minutos, bool $considerar): ActivityProfile
    {
        $dias = collect($diasDaAcademia)->map(fn ($d) => (int) $d)->filter(fn (int $d) => $d >= 0 && $d <= 6)->unique()->sort()->values()->all();
        $dados = [
            'goals' => collect($objetivos)->map(fn ($o) => trim((string) $o))->filter()->values()->take(10)->all(),
            'gym_days' => $dias,
            'session_minutes' => max(15, min(240, $minutos)),
            'consider_limitations' => $considerar,
        ];

        $perfil = ActivityProfile::query()->first();

        if ($perfil === null) {
            try {
                return ActivityProfile::create($dados);
            } catch (UniqueConstraintViolationException) {
                $perfil = ActivityProfile::query()->firstOrFail();
            }
        }

        $perfil->update($dados);

        return $perfil;
    }

    /** @return Collection<int, ActivityCommitment> os fixos primeiro, na ordem da semana */
    public function commitments(): Collection
    {
        return ActivityCommitment::query()->where('is_active', true)->orderByRaw('weekday IS NULL')->orderBy('weekday')->orderBy('created_at')->get();
    }

    /** @param  array{activity: string, custom_name?: ?string, weekday?: ?int, times_per_week?: ?int, minutes: int, effort: int}  $d */
    public function saveCommitment(array $d, ?string $id = null): ActivityCommitment
    {
        $tipo = ActivityType::from($d['activity']);
        $dia = $d['weekday'] ?? null;
        $dados = [
            'activity' => $tipo,
            'custom_name' => $tipo === ActivityType::Other ? (trim((string) ($d['custom_name'] ?? '')) ?: null) : null,
            'weekday' => $dia === null ? null : max(0, min(6, (int) $dia)),
            // Fixo não guarda "vezes por semana": o campo que a regra não usa vai nulo, nunca zero.
            'times_per_week' => $dia === null ? max(1, min(14, (int) ($d['times_per_week'] ?? 1))) : null,
            'minutes' => max(5, min(self::MAX_MINUTES, (int) $d['minutes'])),
            'effort' => max(0, min(10, (int) $d['effort'])),
        ];

        if ($id === null) {
            return ActivityCommitment::create($dados + ['is_active' => true]);
        }

        $compromisso = ActivityCommitment::query()->where('is_active', true)->findOrFail($id);
        $compromisso->update($dados);

        return $compromisso;
    }

    public function removeCommitment(string $id): void
    {
        ActivityCommitment::query()->findOrFail($id)->update(['is_active' => false]);
    }

    /**
     * Uma atividade feita.
     *
     * @param  array{activity: string, custom_name?: ?string, done_on: string, minutes: int, effort?: ?int, pain?: ?int, distance_meters?: ?int, heart_rate?: ?int, note?: ?string}  $d
     */
    public function record(array $d): ActivityRecord
    {
        return ActivityRecord::create($this->recordAttributes($d) + ['status' => ActivityRecordStatus::Done]);
    }

    /** Já há um registro feito desta atividade neste dia? (o nome próprio é cifrado: a comparação é no PHP) */
    public function exists(ActivityType $tipo, ?string $nome, string $data): bool
    {
        $alvo = mb_strtolower(trim((string) $nome));

        return ActivityRecord::query()->where('activity', $tipo->value)->whereDate('done_on', $data)->where('status', ActivityRecordStatus::Done->value)->get()
            ->contains(fn (ActivityRecord $r) => $tipo !== ActivityType::Other || mb_strtolower(trim((string) $r->custom_name)) === $alvo);
    }

    /**
     * Os dias de um período que caem nos dias da semana escolhidos, sem passar de hoje.
     *
     * @param  list<int>  $diasDaSemana  0 (segunda) a 6 (domingo)
     * @return list<string> datas Y-m-d
     */
    public function datesBetween(string $de, string $ate, array $diasDaSemana, ?CarbonImmutable $hoje = null): array
    {
        $hoje ??= CarbonImmutable::today();
        $dia = CarbonImmutable::parse($de)->startOfDay();
        $fim = CarbonImmutable::parse($ate)->startOfDay()->min($hoje);
        $diasDaSemana = array_map('intval', $diasDaSemana);
        $datas = [];

        // No máximo dois anos de varredura, para um período digitado errado não travar a tela.
        for ($n = 0; $n < 731 && $dia->lte($fim) && count($datas) < self::MAX_BATCH; $n++, $dia = $dia->addDay()) {
            if (in_array($dia->dayOfWeekIso - 1, $diasDaSemana, true)) {
                $datas[] = $dia->toDateString();
            }
        }

        return $datas;
    }

    /**
     * @param  array{activity: string, custom_name?: ?string, minutes: int, effort?: ?int}  $d
     * @param  list<string>  $datas
     */
    public function recordBatch(array $d, array $datas): int
    {
        $total = 0;

        foreach (array_slice(array_values(array_unique($datas)), 0, self::MAX_BATCH) as $data) {
            $this->record($d + ['done_on' => $data]);
            $total++;
        }

        return $total;
    }

    /** Corrige duração, esforço e dor de um registro feito (inclusive o que veio do treino da academia). */
    public function updateDone(ActivityRecord $registro, int $minutos, ?int $esforco, ?int $dor): void
    {
        $registro->update([
            'minutes' => max(1, min(self::MAX_MINUTES, $minutos)),
            'effort' => $this->scale($esforco),
            'pain' => $this->scale($dor),
        ]);
    }

    public function delete(ActivityRecord $registro): void
    {
        if ($registro->gym_session_id !== null) {
            throw new DomainException('Este registro vem do treino da academia. Ele sai junto se o treino for apagado lá.');
        }

        $registro->delete();
    }

    /**
     * Cada treino terminado na Academia vira uma linha do diário, uma vez só (índice único). Roda quando a tela lê o período, o que também
     * cobre o histórico importado.
     */
    public function syncGymSessions(CarbonImmutable $de, CarbonImmutable $ate): void
    {
        if (app(ProfileContext::class)->memberId() === null) {
            return;
        }

        $faltam = GymSession::query()->whereNotNull('finished_at')
            ->whereDate('performed_on', '>=', $de->toDateString())->whereDate('performed_on', '<=', $ate->toDateString())
            ->whereNotIn('id', ActivityRecord::query()->whereNotNull('gym_session_id')->select('gym_session_id'))
            ->get();

        if ($faltam->isEmpty()) {
            return;
        }

        $padrao = $this->profile()->session_minutes ?? 60;

        foreach ($faltam as $sessao) {
            $minutos = $sessao->started_at !== null ? (int) round($sessao->started_at->diffInSeconds($sessao->finished_at) / 60) : 0;

            // Quem já tinha dito "Fiz" na semana (ou lançado o treino à mão) não ganha uma segunda linha: a sessão se liga à que existe.
            $aMao = ActivityRecord::query()->where('activity', ActivityType::Gym->value)->whereNull('gym_session_id')
                ->whereDate('done_on', $sessao->performed_on->toDateString())->orderBy('created_at')->first();

            if ($aMao !== null) {
                $aMao->update(['gym_session_id' => $sessao->id, 'status' => ActivityRecordStatus::Done]);

                continue;
            }

            try {
                ActivityRecord::create([
                    'activity' => ActivityType::Gym,
                    'done_on' => $sessao->performed_on,
                    'status' => ActivityRecordStatus::Done,
                    // Sessão esquecida aberta (ou importada sem horário) não vira um treino de um dia inteiro.
                    'minutes' => $minutos >= 5 && $minutos <= 240 ? $minutos : $padrao,
                    'gym_session_id' => $sessao->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Outra aba importou o mesmo treino.
            }
        }
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private function recordAttributes(array $d): array
    {
        $tipo = ActivityType::from($d['activity']);

        return [
            'activity' => $tipo,
            'custom_name' => $tipo === ActivityType::Other ? (trim((string) ($d['custom_name'] ?? '')) ?: null) : null,
            'done_on' => $d['done_on'],
            'minutes' => max(1, min(self::MAX_MINUTES, (int) $d['minutes'])),
            'effort' => $this->scale($d['effort'] ?? null),
            'pain' => $this->scale($d['pain'] ?? null),
            'distance_meters' => $tipo->hasDistance() && ! empty($d['distance_meters']) ? (int) $d['distance_meters'] : null,
            'heart_rate' => ! empty($d['heart_rate']) ? (int) $d['heart_rate'] : null,
            'note' => trim((string) ($d['note'] ?? '')) ?: null,
        ];
    }

    private function scale(mixed $valor): ?int
    {
        return $valor === null || $valor === '' ? null : max(0, min(10, (int) $valor));
    }
}
