<?php

namespace App\Support;

use App\Enums\GymEquipmentAccess;
use App\Enums\GymGoal;
use App\Enums\GymLevel;
use App\Enums\GymRestriction;

/** As respostas do assistente de treino automático. */
final readonly class GymGeneratorInput
{
    public const MINUTES = [30, 45, 60, 90];

    /**
     * @param  list<GymRestriction>  $restrictions
     * @param  int  $variant  0 é a escolha melhor ranqueada; outros valores rodam para as próximas opções ("sortear outras")
     */
    public function __construct(
        public GymGoal $goal,
        public GymLevel $level,
        public int $days,
        public int $minutes,
        public GymEquipmentAccess $access,
        public array $restrictions = [],
        public int $variant = 0,
    ) {}
}
