<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum InvoiceStatus: string
{
    use HasOptions;

    case Open = 'open';
    case Closed = 'closed';
    case Paid = 'paid';
    case Overdue = 'overdue';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Aberta',
            self::Closed => 'Fechada',
            self::Paid => 'Paga',
            self::Overdue => 'Vencida',
        };
    }

    /**
     * Faturas ainda abertas aceitam novos lançamentos; a partir do
     * fechamento, uma compra nova cai no ciclo seguinte.
     */
    public function acceptsNewExpenses(): bool
    {
        return $this === self::Open;
    }
}
