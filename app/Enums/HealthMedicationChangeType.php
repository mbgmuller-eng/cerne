<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * O que mudou num remédio. É a "linha do tempo" que a ficha de saúde
 * existe pra dar: 3 filhos que revezam levar a mãe ao médico enxergam a
 * mesma alteração, sem precisar perguntar um pro outro.
 */
enum HealthMedicationChangeType: string
{
    use HasOptions;

    case Started = 'started';
    case DoseChanged = 'dose_changed';
    case Suspended = 'suspended';
    case Resumed = 'resumed';
    case Note = 'note';

    public function label(): string
    {
        return match ($this) {
            self::Started => 'Iniciado',
            self::DoseChanged => 'Dose alterada',
            self::Suspended => 'Suspenso',
            self::Resumed => 'Retomado',
            self::Note => 'Observação',
        };
    }
}
