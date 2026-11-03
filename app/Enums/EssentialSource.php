<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * De onde vem o gasto essencial mensal que serve de base à meta da reserva de paz: calculado pelo fluxo de caixa
 * (média dos meses fechados) ou informado à mão, para quem usa o Cerne só nos investimentos.
 */
enum EssentialSource: string
{
    use HasOptions;

    case Auto = 'auto';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Auto => 'Calcular pelo fluxo de caixa',
            self::Manual => 'Informar o valor',
        };
    }
}