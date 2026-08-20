<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum AccountType: string
{
    use HasOptions;

    case Checking = 'checking';
    case Savings = 'savings';
    case DigitalWallet = 'digital_wallet';
    case InvestmentAccount = 'investment_account';

    public function label(): string
    {
        return match ($this) {
            self::Checking => 'Conta corrente',
            self::Savings => 'Poupança',
            self::DigitalWallet => 'Carteira digital',
            self::InvestmentAccount => 'Conta de investimento',
        };
    }
}
