<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum DebtKind: string
{
    use HasOptions;

    case Financing = 'financing';
    case Loan = 'loan';
    case Consortium = 'consortium';
    case Debt = 'debt';

    /** Os tipos que podem ser cadastrados. */
    public static function creatable(): array
    {
        return self::cases();
    }

    public function label(): string
    {
        return match ($this) {
            self::Financing => 'Financiamento',
            self::Loan => 'Empréstimo',
            self::Consortium => 'Consórcio',
            self::Debt => 'Dívida em aberto',
        };
    }
}
