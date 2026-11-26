<?php

namespace App\Services\Activities;

use App\Enums\BodyRegion;
use App\Enums\CautionMark;
use App\Enums\HealthActivityKind;
use App\Enums\HealthFollowupStatus;
use App\Enums\LimitationStatus;
use App\Enums\MovementLoad;
use App\Models\GymExercise;
use App\Models\GymExerciseCaution;
use App\Models\HealthActivity;
use App\Models\HealthFollowup;
use App\Models\PhysicalLimitation;
use App\Models\ProfileMember;
use App\Services\HealthActivityService;
use App\Services\HealthFollowupService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Cadastro das limitações (na Ficha de Saúde ou dentro de um acompanhamento, sempre com a visibilidade da Agenda) e o registro das respostas
 * da consulta. Uma limitação do passado não precisa de acompanhamento, consulta nem exame.
 */
class LimitationService
{
    /** Onde os exercícios indicados na consulta são guardados quando o acompanhamento ainda não tem programa. */
    public const ACTIVITY_NAME = 'Exercícios orientados';

    public function __construct(private readonly HealthActivityService $programs, private readonly HealthFollowupService $followups) {}

    /**
     * Quando `$origem` é um acompanhamento, a limitação nasce ligada a ele; quando é a pessoa, a origem vem em `$d` (followup_id,
     * appointment_id, exam_report_id), e pode não haver nenhuma.
     *
     * @param  array{region: string, status: string, avoid?: list<string>, guidance?: ?string, professional?: ?string, professional_id?: ?string, informed_on?: ?string, since_year?: ?int, followup_id?: ?string, appointment_id?: ?string, exam_report_id?: ?string}  $d
     */
    public function save(HealthFollowup|ProfileMember $origem, array $d, ?ProfileMember $autor, ?PhysicalLimitation $limitacao = null): PhysicalLimitation
    {
        $dados = [
            'region' => BodyRegion::from($d['region'])->value,
            'status' => LimitationStatus::from($d['status']),
            'avoid' => array_map(fn (MovementLoad $c) => $c->value, MovementLoad::fromValues($d['avoid'] ?? [])),
            'guidance' => trim((string) ($d['guidance'] ?? '')) ?: null,
            'professional' => trim((string) ($d['professional'] ?? '')) ?: null,
            'professional_id' => ($d['professional_id'] ?? null) ?: null,
            'informed_on' => ($d['informed_on'] ?? null) ?: CarbonImmutable::today()->toDateString(),
        ];

        foreach (['since_year', 'followup_id', 'appointment_id', 'exam_report_id'] as $campo) {
            if (array_key_exists($campo, $d)) {
                $dados[$campo] = $d[$campo] ?: null;
            }
        }

        if ($limitacao === null) {
            return PhysicalLimitation::create($dados + [
                'member_id' => $origem instanceof HealthFollowup ? $origem->member_id : $origem->id,
                'followup_id' => $origem instanceof HealthFollowup ? $origem->id : null,
                'created_by_member_id' => $autor?->id,
            ]);
        }

        $limitacao->update($dados);

        return $limitacao;
    }

    public function delete(PhysicalLimitation $limitacao): void
    {
        $limitacao->delete();
    }

    /**
     * As respostas que a pessoa trouxe da consulta: o que pode, adaptar ou evitar no treino, os esportes, a corrida e os exercícios
     * indicados (que vão para o programa do acompanhamento, onde sempre foram digitados). Só a própria pessoa registra, porque cita o treino
     * dela. Limitação sem acompanhamento ganha um quando há exercícios indicados para guardar.
     *
     * @param  array{
     *     marks?: array<string, string>,
     *     running?: ?string,
     *     sports?: array<string, string>,
     *     guidance?: ?string,
     *     professional?: ?string,
     *     professional_id?: ?string,
     *     exercises?: list<array{name: string, sets?: ?string, reps?: ?string, per_week?: ?int}>
     * }  $d
     */
    public function registerConsultation(PhysicalLimitation $limitacao, array $d, ?ProfileMember $autor): void
    {
        $contexto = app(ProfileContext::class);

        if ($contexto->isConsultant() || $contexto->memberId() === null || $limitacao->member_id !== $contexto->memberId()) {
            throw new DomainException('Só a própria pessoa registra as respostas da sua consulta.');
        }

        DB::transaction(function () use ($limitacao, $d, $autor): void {
            $marcas = [];

            foreach ($d['marks'] ?? [] as $exercicioId => $marca) {
                $marca = CautionMark::tryFrom((string) $marca);

                if ($marca !== null && in_array($marca, CautionMark::fromProfessional(), true)) {
                    $marcas[(string) $exercicioId] = $marca;
                }
            }

            // Os ids vêm do navegador: só passam os exercícios que a própria pessoa enxerga.
            foreach (GymExercise::query()->whereIn('id', array_keys($marcas))->pluck('id') as $exercicioId) {
                GymExerciseCaution::query()->updateOrCreate(['limitation_id' => $limitacao->id, 'exercise_id' => $exercicioId], ['mark' => $marcas[$exercicioId]]);
            }

            $limitacao->update(array_filter([
                'status' => LimitationStatus::Guided,
                'answers' => [
                    'running' => in_array($d['running'] ?? null, ['yes', 'not_yet'], true) ? $d['running'] : null,
                    'sports' => array_filter($d['sports'] ?? [], fn ($v) => in_array($v, ['ok', 'care', 'stop'], true)),
                ],
                'informed_on' => CarbonImmutable::today()->toDateString(),
                'guidance' => trim((string) ($d['guidance'] ?? '')) ?: null,
                'professional' => trim((string) ($d['professional'] ?? '')) ?: null,
                'professional_id' => ($d['professional_id'] ?? null) ?: null,
            ], fn ($v) => $v !== null));

            $exercicios = array_values(array_filter($d['exercises'] ?? [], fn (array $e) => trim((string) ($e['name'] ?? '')) !== ''));

            if ($exercicios === []) {
                return;
            }

            if ($limitacao->followup_id === null) {
                $novo = $this->followups->create($limitacao->member, [
                    'title' => 'Limitação: '.$limitacao->regionLabel(),
                    'status' => HealthFollowupStatus::Investigating->value,
                    'started_on' => CarbonImmutable::today()->toDateString(),
                ], $autor);
                $limitacao->update(['followup_id' => $novo->id]);
            }

            $acompanhamento = HealthFollowup::query()->findOrFail($limitacao->followup_id);
            $vezes = max(1, min(7, (int) collect($exercicios)->max(fn (array $e) => (int) ($e['per_week'] ?? 2))));
            $atividade = HealthActivity::query()->where('followup_id', $acompanhamento->id)->where('is_active', true)->get()
                ->first(fn (HealthActivity $a) => $a->name === self::ACTIVITY_NAME)
                ?? $this->programs->create($acompanhamento, ['kind' => HealthActivityKind::Home->value, 'name' => self::ACTIVITY_NAME, 'per_week' => $vezes], $autor);

            foreach ($exercicios as $e) {
                $this->programs->addExercise($atividade, mb_substr((string) $e['name'], 0, 120), (string) ($e['sets'] ?? ''), (string) ($e['reps'] ?? ''), null, $autor);
            }
        });
    }
}
