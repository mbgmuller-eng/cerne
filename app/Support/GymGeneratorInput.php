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
    ) {}
}
