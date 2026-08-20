<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum PaymentMethodType: string
{
    use HasOptions;

    case BankAccount = 'bank_account';
    case CreditCard = 'credit_card';
    case Cash = 'cash';
    case Pix = 'pix';

    public function label(): string
    {
        return match ($this) {
            self::BankAccount => 'Conta bancária',
            self::CreditCard => 'Cartão de crédito',
            self::Cash => 'Dinheiro',
            self::Pix => 'Pix',
        };
    }
}
