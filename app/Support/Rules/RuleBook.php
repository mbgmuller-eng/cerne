<?php

namespace App\Support\Rules;

use App\Enums\BodyRegion;
use App\Enums\GymGoal;
use App\Enums\GymLevel;
use App\Enums\GymMeasureType;
use App\Enums\GymMuscleGroup;
use App\Enums\MovementLoad;
use App\Models\GymExercise;
use App\Models\GymPlan;
use App\Services\Activities\ActivityWeekService;
use App\Services\GymWorkoutGenerator;
use App\Support\Activities\PlanRules;
use App\Support\Activities\ReadinessQuestions;
use App\Support\Activities\RunningLadder;
use App\Support\GymExerciseClassifier;
use App\Support\Health\BodyMass;

/**
 * As regras de atividade física do Cerne, lidas do PRÓPRIO CÓDIGO, para um profissional de educação física revisar. A "versão" de cada regra é
 * a impressão digital do conteúdo dela: mudou a regra no código, a aprovação antiga deixa de valer sozinha e a tela volta a dizer que não foi
 * revisada. Regra nova que o app use entra aqui.
 */
final class RuleBook
{
    private const EQUIPMENT_NOTE = 'A marcação é pelo NOME do exercício: o Cerne não conhece a execução nem a carga usada.';

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $memo = null;

    /**
     * @return array<string, array{key: string, title: string, description: string, version: string, load: ?string, headers: list<string>, rows: list<list<string>>}>
     */
    public static function all(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $regras = [];

        foreach (MovementLoad::cases() as $carga) {
            $regras[] = ['key' => 'load_'.$carga->value, 'title' => 'Evitar: '.mb_strtolower($carga->label()), 'load' => $carga->value,
                'description' => self::EQUIPMENT_NOTE, 'headers' => ['Palavras no nome que marcam o exercício'], 'rows' => [[implode(', ', $carga->keywords())]]];
        }

        $regioes = ['knee' => 'Joelho', 'shoulder' => 'Ombro', 'lower_back' => 'Lombar'];
        $regras[] = self::rule('region_lists', 'Listas por região', 'Valem além do "o que evitar", só nestas três regiões. As outras não têm lista própria.', ['Região', 'Palavras'],
            collect(GymExerciseClassifier::regionKeywords())->map(fn (array $p, string $r) => [$regioes[$r] ?? $r, implode(', ', $p)])->values()->all());

        $regras[] = self::rule('region_suggestions', 'O que vem marcado por região', 'Ponto de partida que a pessoa ajusta.', ['Região', 'Vem marcado'],
            array_map(fn (BodyRegion $r) => [$r->label(), implode(', ', array_map(fn (MovementLoad $c) => mb_strtolower($c->label()), $r->suggestedAvoid())) ?: 'nada'], BodyRegion::cases()));

        $regras[] = self::rule('running', 'Programa de caminhada e corrida',
            'Cada sessão começa com '.RunningLadder::WARM_UP_MINUTES.' min de caminhada. Avança só com a semana completa, dor informada e sem passar da dor inicial. Falta repete. Dor maior volta um passo. Até 3 vezes por semana. Sem liberação médica, quando as perguntas pedem, não começa.',
            ['Passo', 'Sessão'], array_map(fn (string $t, int $i) => [(string) ($i + 1), $t], RunningLadder::all(), array_keys(RunningLadder::all())));

        $regras[] = self::rule('readiness', 'Perguntas de prontidão',
            'Qualquer "sim" recomenda liberação médica. Sem a liberação, o plano fica leve (esforço até '.PlanRules::LIGHT_EFFORT.' em 10), não progride e a corrida não começa. As respostas valem '.ReadinessQuestions::VALID_MONTHS.' meses, ou até a pessoa registrar um problema que peça cuidado no exercício.',
            ['Nº', 'Pergunta'], array_values(array_map(fn (string $p, int $i) => [(string) ($i + 1), $p], array_values(ReadinessQuestions::ALL), array_keys(array_values(ReadinessQuestions::ALL)))));

        $regras[] = self::rule('week_plan', 'Plano da semana e progressão do aeróbico', 'Os minutos de esporte contam como aproximação, porque o jogo é intermitente.', ['Regra', 'Valor'], [
            ['Ponto de partida', 'média das 4 últimas semanas do diário (sem diário, o que a pessoa informou que costuma fazer)'],
            ['Perder peso: alvo', 'o que já faz mais '.PlanRules::WEIGHT_LOSS_EXTRA.' min, entre '.PlanRules::WHO_MINIMUM.' e '.PlanRules::CEILING.' min por semana'],
            ['Saúde e condicionamento: alvo', 'ao menos '.PlanRules::WHO_MINIMUM.' min por semana'],
            ['Quem já passa do teto', 'não aumenta'],
            ['Aumento', 'até '.PlanRules::MAX_WEEKLY_INCREASE.' min por semana, e só depois de uma semana com ao menos '.PlanRules::ADHERENCE_PERCENT.'% do planejado'],
            ['Semana leve', 'a cada '.PlanRules::DELOAD_EVERY.' semanas, com '.PlanRules::DELOAD_PERCENT.'% a menos'],
            ['Musculação', PlanRules::STRENGTH_DAYS_MIN.' a '.PlanRules::STRENGTH_DAYS_MAX.' dias, evitando pernas na véspera de um esporte'],
            ['Aeróbico no fim da musculação', 'até '.PlanRules::FINISHER_MINUTES.' min'],
            ['Cada sessão de aeróbico', 'até '.PlanRules::SESSION_MAX.' min, esforço '.PlanRules::AEROBIC_EFFORT.' em 10'],
            ['Impacto', 'com limitação que pede evitar impacto, o plano só usa caminhada, bicicleta, natação e elíptico; corrida fica no programa próprio'],
        ]);

        $regras[] = self::rule('weight_loss_strength', 'Musculação no objetivo Emagrecer', 'O que o treino automático e a atualização do programa usam para quem tem nível intermediário.', ['Tipo', 'Séries, repetições e descanso'], [
            ['Composto (exemplo: supino reto)', self::prescription('Supino reto', GymMuscleGroup::Chest)],
            ['Isolado (exemplo: rosca direta)', self::prescription('Rosca direta', GymMuscleGroup::Arms)],
            ['Core', self::prescription('Prancha abdominal supra', GymMuscleGroup::Core)],
        ]);

        $regras[] = self::rule('bmi', 'IMC, faixa de peso e meta', 'A meta é opcional, só da própria pessoa, e não pode ficar abaixo do limite inferior da faixa. Quem já está abaixo não recebe meta de perda. Sem prazo prometido.', ['Idade', 'Faixa de referência (IMC)'], [
            [BodyMass::ADULT_AGE.' a '.(BodyMass::SENIOR_AGE - 1).' anos', 'de '.BodyMass::format(BodyMass::ADULT_MIN).' a '.BodyMass::format(BodyMass::ADULT_MAX)],
            [BodyMass::SENIOR_AGE.' anos ou mais', 'de '.BodyMass::format(BodyMass::SENIOR_MIN).' a '.BodyMass::format(BodyMass::SENIOR_MAX)],
            ['Menos de '.BodyMass::ADULT_AGE.' anos', 'não classifica'],
        ]);

        $regras[] = self::rule('week_warnings', 'Avisos da semana', 'O Cerne avisa e não move nada. A carga é minutos vezes esforço de 0 a 10.', ['Aviso', 'Quando'], [
            ['Pernas na véspera', 'treino com metade ou mais de exercícios de pernas e glúteos um dia antes de um esporte'],
            ['Sem descanso', 'os 7 dias com atividade'],
            ['Corrida', 'no dia seguinte ao treino de pernas, ou em dois dias seguidos'],
            ['Carga alta', 'mais de '.ActivityWeekService::LOAD_ALERT_PERCENT.'% acima da média das 4 semanas anteriores (precisa de 2 semanas com dado)'],
        ]);

        return self::$memo = collect($regras)->map(function (array $r): array {
            $r += ['load' => null];
            $r['version'] = sha1(json_encode([$r['description'], $r['headers'], $r['rows']], JSON_UNESCAPED_UNICODE));

            return $r;
        })->keyBy('key')->all();
    }

    /** @return array<string, mixed>|null */
    public static function find(string $chave): ?array
    {
        return self::all()[$chave] ?? null;
    }

    /**
     * @param  list<string>  $cabecalhos
     * @param  list<list<string>>  $linhas
     * @return array<string, mixed>
     */
    private static function rule(string $chave, string $titulo, string $descricao, array $cabecalhos, array $linhas): array
    {
        return ['key' => $chave, 'title' => $titulo, 'description' => $descricao, 'headers' => $cabecalhos, 'rows' => $linhas];
    }

    /** A prescrição que o gerador dá hoje, calculada pelo próprio gerador (nunca copiada à mão). */
    private static function prescription(string $nome, GymMuscleGroup $grupo): string
    {
        $exercicio = new GymExercise(['name' => $nome, 'muscle_group' => $grupo, 'measure_type' => GymMeasureType::LoadReps]);
        $fase = new GymPlan(['goal' => GymGoal::WeightLoss, 'level' => GymLevel::Intermediate]);
        $p = app(GymWorkoutGenerator::class)->prescriptionFor($exercicio, $fase);

        return $p['sets'].' séries, '.$p['reps_min'].' a '.$p['reps_max'].' repetições, '.$p['rest'].' s de descanso';
    }
}
