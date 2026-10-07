<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Tipos de item de saúde que precisam de troca, renovação ou revisão periódica.
 * Remédio de uso contínuo NÃO entra aqui: já mora na Ficha de Saúde
 * (HealthMedication), com histórico de mudança de dose.
 */
enum HealthCareCategory: string
{
    use HasOptions;

    case HearingAid = 'hearing_aid';
    case Orthotic = 'orthotic';
    case Eyewear = 'eyewear';
    case Prosthesis = 'prosthesis';
    case CompressionStockings = 'compression_stockings';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::HearingAid => 'Aparelho auditivo e acessórios',
            self::Orthotic => 'Palmilhas e órteses',
            self::Eyewear => 'Óculos e lentes',
            self::Prosthesis => 'Próteses',
            self::CompressionStockings => 'Meias de compressão',
            self::Other => 'Outro',
        };
    }
}
