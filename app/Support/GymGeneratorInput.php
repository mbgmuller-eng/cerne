<?php

namespace App\Support;

use App\Enums\GymEquipmentAccess;
use App\Enums\GymGoal;
use App\Enums\GymLevel;
use App\Enums\GymRestriction;
use App\Enums\GymSeason;
use App\Enums\GymSport;

/** As respostas do assistente de treino automático. */
final readonly class GymGeneratorInput
{
    public const MINUTES = [30, 45, 60, 90];

    /**
     * @param  list<GymRestriction>  $restrictions
     * @param  int  $variant  0 é a escolha melhor ranqueada; outros valores rodam para as próximas opções ("sortear outras")
     * @param  list<string>  $avoid  nomes (normalizados) dos exercícios da fase anterior que o treino novo evita repetir, se houver alternativa
     * @param  list<string>  $keep  nomes (normalizados) dos que evoluíram bem e que o treino novo prefere manter
     * @param  GymSport|null  $sport  esporte que a academia reforça (nulo = treino pelo objetivo, sem foco esportivo)
     * @param  int  $sportDays  quantas vezes por semana a pessoa pratica o esporte
     * @param  list<GymRestriction>  $limitationRestrictions  regiões vindas das limitações salvas na Saúde (não são gravadas na fase: liberada a limitação, saem)
     * @param  list<\App\Enums\MovementLoad>  $avoidLoads  o que as limitações pedem para evitar
     * @param  list<string>  $excludeNames  nomes (normalizados) que o profissional marcou para evitar
     */
    public function __construct(
        public GymGoal $goal,
        public GymLevel $level,
        public int $days,
        public int $minutes,
        public GymEquipmentAccess $access,
        public array $restrictions = [],
        public int $variant = 0,
        public array $avoid = [],
        public array $keep = [],
        public ?GymSport $sport = null,
        public int $sportDays = 0,
        public ?GymSeason $season = null,
        public array $limitationRestrictions = [],
        public array $avoidLoads = [],
        public array $excludeNames = [],
    ) {}

    /** A pessoa pediu para proteger a região, ou uma limitação salva pede, e o nome do exercício indica que ele não serve. */
    public function blocks(string $nome): bool
    {
        return GymExerciseClassifier::hitsRestriction($nome, [...$this->restrictions, ...$this->limitationRestrictions])
            || \App\Enums\MovementLoad::hits($nome, $this->avoidLoads) !== []
            || in_array(GymExerciseClassifier::normalize($nome), $this->excludeNames, true);
    }
}
