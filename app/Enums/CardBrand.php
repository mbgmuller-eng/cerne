<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum CardBrand: string
{
    use HasOptions;

    case Visa = 'visa';
    case Mastercard = 'mastercard';
    case Elo = 'elo';
    case Amex = 'amex';
    case Hipercard = 'hipercard';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Visa => 'Visa',
            self::Mastercard => 'Mastercard',
            self::Elo => 'Elo',
            self::Amex => 'American Express',
            self::Hipercard => 'Hipercard',
            self::Other => 'Outra',
        };
    }
}
