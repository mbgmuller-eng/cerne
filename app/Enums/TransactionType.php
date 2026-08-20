<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum TransactionType: string
{
    use HasOptions;

    case Buy = 'buy';
    case Sell = 'sell';
    case Dividend = 'dividend';
    case Jcp = 'jcp';
    case Amortization = 'amortization';
    case Split = 'split';
    case Grouping = 'grouping';
    case Subscription = 'subscription';

    public function label(): string
    {
        return match ($this) {
            self::Buy => 'Compra',
            self::Sell => 'Venda',
            self::Dividend => 'Dividendo',
            self::Jcp => 'JCP',
            self::Amortization => 'Amortização',
            self::Split => 'Desdobramento',
            self::Grouping => 'Grupamento',
            self::Subscription => 'Subscrição',
        };
    }

    /** Movimenta quantidade e exige recálculo de preço médio. */
    public function affectsPosition(): bool
    {
        return in_array($this, [
            self::Buy,
            self::Sell,
            self::Split,
            self::Grouping,
            self::Subscription,
        ], true);
    }

    /** Provento: entra como rendimento, não altera o preço médio. */
    public function isIncome(): bool
    {
        return in_array($this, [self::Dividend, self::Jcp, self::Amortization], true);
    }
}
