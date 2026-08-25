<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Tipo de atuação profissional — define quantos meses de gasto essencial
 * a reserva de paz precisa cobrir (quanto menos estável a renda, maior o
 * colchão recomendado).
 */
enum EmploymentType: string
{
    use HasOptions;

    case PublicServant = 'public_servant';
    case Clt = 'clt';
    case SelfEmployed = 'self_employed';

    public function label(): string
    {
        return match ($this) {
            self::PublicServant => 'Funcionário público',
            self::Clt => 'CLT',
            self::SelfEmployed => 'Autônomo',
        };
    }

    /** Meses de gasto essencial que a reserva de paz precisa cobrir. */
    public function reserveMonths(): int
    {
        return match ($this) {
            self::PublicServant => 6,
            self::Clt => 9,
            self::SelfEmployed => 12,
        };
    }
}
