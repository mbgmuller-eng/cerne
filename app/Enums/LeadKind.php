<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * O funil em que o lead anda: consultoria financeira (reunião e proposta) ou seguros (dados, cotações, negociação,
 * emissão). Cada um tem as suas etapas (ver LeadStage::openFor()).
 */
enum LeadKind: string
{
    use HasOptions;

    case Consulting = 'consulting';
    case Insurance = 'insurance';

    public function label(): string
    {
        return match ($this) {
            self::Consulting => 'Consultoria',
            self::Insurance => 'Seguros',
        };
    }
}
