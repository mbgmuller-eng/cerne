<?php

namespace App\Services;

use App\Enums\HealthActivityKind;
use App\Models\GymPlan;
use App\Models\GymSession;
use App\Models\HealthActivity;
use App\Models\HealthActivityExercise;
use App\Models\HealthActivitySession;
use App\Models\HealthFollowup;
use App\Models\ProfileMember;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Programa de atividades de um acompanhamento: o que o profissional orientou, os exercícios cadastrados à mão e as presenças com nota de dor.
 * O Cerne organiza, lembra e mede; não escolhe exercício para um problema de saúde.
 */
class HealthActivityService
{
    /** @param  array{kind: string, name?: ?string, per_week: int, total_sessions?: ?int, gym_plan_id?: ?string}  $dados */
    public function create(HealthFollowup $acompanhamento, array $dados, ?ProfileMember $autor): HealthActivity
    {
        $tipo = HealthActivityKind::from($dados['kind']);
        $nome = trim((string) ($dados['name'] ?? ''));

        return HealthActivity::create([
            'member_id' => $acompanhamento->member_id,
            'followup_id' => $acompanhamento->id,
            'kind' => $tipo,
            'name' => $nome !== '' ? $nome : $tipo->label(),
            'per_week' => max(1, min(14, (int) $dados['per_week'])),
            'total_sessions' => ($dados['total_sessions'] ?? null) ?: null,
            'gym_plan_id' => $tipo === HealthActivityKind::Gym ? $this->ownGymPlan($acompanhamento, $dados['gym_plan_id'] ?? null) : null,
            'is_active' => true,
            'created_by_member_id' => $autor?->id,
        ]);
    }

    /** Encerrar tira a atividade do que está em curso; o histórico de presenças fica. */
    public function setActive(HealthActivity $atividade, bool $ativa): void
    {
        $atividade->update(['is_active' => $ativa]);
    }

    public function delete(HealthActivity $atividade): void
    {
        $atividade->delete();
    }

    /** As fases da Academia que podem ser ligadas: só as da própria pessoa que está olhando, e só no acompanhamento dela mesma. */
    public function linkableGymPlans(HealthFollowup $acompanhamento): Collection
    {
        if (! $this->isOwn($acompanhamento)) {
            return collect();
        }

        return GymPlan::query()->orderByDesc('is_active')->orderByDesc('started_on')->get(['id', 'name', 'is_active']);
    }

    private function isOwn(HealthFollowup $acompanhamento): bool
    {
        $contexto = app(ProfileContext::class);

        return ! $contexto->isConsultant() && $contexto->memberId() !== null && $contexto->memberId() === $acompanhamento->member_id;
    }

    /** O id vem do navegador: só passa se for uma fase da própria pessoa (o escopo da Academia não devolve as de mais ninguém). */
    private function ownGymPlan(HealthFollowup $acompanhamento, ?string $planoId): ?string
    {
        if ($planoId === null || $planoId === '') {
            return null;
        }

        $id = $this->isOwn($acompanhamento) ? GymPlan::query()->whereKey($planoId)->value('id') : null;

        if ($id === null) {
            throw new InvalidArgumentException('Escolha uma fase da sua Academia.');
        }

        return $id;
    }

    public function addExercise(HealthActivity $atividade, string $nome, ?string $series, ?string $repeticoes, ?string $observacao, ?ProfileMember $autor): HealthActivityExercise
    {
        $dose = implode(' × ', array_filter([trim((string) $series), trim((string) $repeticoes)], fn (string $v) => $v !== ''));

        return HealthActivityExercise::create([
            'member_id' => $atividade->member_id,
            'activity_id' => $atividade->id,
            'name' => trim($nome),
            'dose' => $dose !== '' ? $dose : null,
            'note' => trim((string) $observacao) !== '' ? trim((string) $observacao) : null,
            'position' => (int) $atividade->exercises()->max('position') + 1,
            'created_by_member_id' => $autor?->id,
        ]);
    }

    public function removeExercise(HealthActivityExercise $exercicio): void
    {
        $exercicio->delete();
    }

    /** Presença exige a nota de dor; falta não tem nota. */
    public function registerSession(HealthActivity $atividade, string $data, bool $presente, ?int $dor, ?string $observacao, ?ProfileMember $autor): HealthActivitySession
    {
        if ($presente && $dor === null) {
            throw new InvalidArgumentException('Escolha a nota de dor de 0 a 10.');
        }

        return HealthActivitySession::create([
            'member_id' => $atividade->member_id,
            'activity_id' => $atividade->id,
            'done_on' => $data,
            'attended' => $presente,
            'pain' => $presente ? max(0, min(10, (int) $dor)) : null,
            'note' => trim((string) $observacao) !== '' ? trim((string) $observacao) : null,
            'created_by_member_id' => $autor?->id,
        ]);
    }

    /** A nota de dor de uma presença que já existe (a que veio sozinha do treino da Academia). */
    public function setPain(HealthActivitySession $presenca, int $dor): void
    {
        $presenca->update(['pain' => max(0, min(10, $dor)), 'changed_by_user_id' => auth()->id()]);
    }

    public function deleteSession(HealthActivitySession $presenca): void
    {
        $presenca->delete();
    }

    /**
     * Terminou um treino da Academia: cada atividade ligada à fase ganha a presença (uma só por treino, pelo índice único). A nota de dor fica em
     * branco até a pessoa informar.
     *
     * @return Collection<int, HealthActivitySession>
     */
    public function recordGymSession(GymSession $treino): Collection
    {
        $treino->loadMissing('workout');
        $planoId = $treino->workout?->plan_id;

        if ($planoId === null) {
            return collect();
        }

        return HealthActivity::query()
            ->where('gym_plan_id', $planoId)
            ->where('member_id', $treino->member_id)
            ->where('is_active', true)
            ->get()
            ->map(function (HealthActivity $atividade) use ($treino): ?HealthActivitySession {
                $chave = ['activity_id' => $atividade->id, 'gym_session_id' => $treino->id];

                try {
                    return HealthActivitySession::query()->where($chave)->first() ?? HealthActivitySession::create($chave + [
                        'member_id' => $atividade->member_id,
                        'done_on' => $treino->performed_on,
                        'attended' => true,
                        'created_by_member_id' => $treino->member_id,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    return HealthActivitySession::query()->where($chave)->first();
                }
            })
            ->filter()
            ->values();
    }

    /**
     * Sessões feitas e dor média por semana (segunda a domingo), das últimas `$semanas`, com as semanas sem registro valendo zero sessões e dor
     * em branco. Somado no banco.
     *
     * @param  list<string>  $atividades  ids das atividades do programa
     * @return list<array{week: CarbonImmutable, sessions: int, pain: ?float}>
     */
    public function weekly(array $atividades, int $semanas = 8, ?CarbonImmutable $hoje = null): array
    {
        $hoje ??= CarbonImmutable::today();
        $inicio = $hoje->startOfWeek()->subWeeks($semanas - 1);

        if ($atividades === []) {
            return [];
        }

        $linhas = HealthActivitySession::query()
            ->whereIn('activity_id', $atividades)
            ->where('done_on', '>=', $inicio->toDateString())
            ->selectRaw('DATE_SUB(done_on, INTERVAL WEEKDAY(done_on) DAY) as semana, SUM(attended) as feitas, AVG(pain) as dor')
            ->groupBy('semana')
            ->toBase()
            ->get()
            ->keyBy(fn (object $l) => substr((string) $l->semana, 0, 10));

        if ($linhas->isEmpty()) {
            return [];
        }

        // Começa na primeira semana com registro: oito colunas vazias antes do começo do programa não dizem nada.
        $primeira = CarbonImmutable::parse($linhas->keys()->sort()->first());
        $saida = [];

        for ($semana = $primeira; $semana->lte($hoje); $semana = $semana->addWeek()) {
            $l = $linhas->get($semana->toDateString());
            $saida[] = [
                'week' => $semana,
                'sessions' => (int) ($l->feitas ?? 0),
                'pain' => $l !== null && $l->dor !== null ? round((float) $l->dor, 1) : null,
            ];
        }

        return $saida;
    }
}
