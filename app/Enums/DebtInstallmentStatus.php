<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum DebtInstallmentStatus: string
{
    use HasOptions;

    case Open = 'open';
    case Paid = 'paid';
    /** Parcela que não foi paga e teve o valor diluído nas seguintes (consórcio): não está em aberto nem conta como paga. */
    case Diluted = 'diluted';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Em aberto',
            self::Paid => 'Paga',
            self::Diluted => 'Diluída',
        };
    }
}
