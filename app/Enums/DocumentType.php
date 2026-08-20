<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Tipo de documento importado.
 *
 * Cada tipo tem um destino diferente (seção 10 da especificação) e, por
 * consequência, um esquema de extração próprio — ver DocumentSchemas.
 */
enum DocumentType: string
{
    use HasOptions;

    case BankStatement = 'bank_statement';
    case CreditCardInvoice = 'credit_card_invoice';
    case InvestmentStatement = 'investment_statement';
    case BrokerageNote = 'brokerage_note';
    case PerformanceReport = 'performance_report';
    case InsurancePolicy = 'insurance_policy';
    case IncomeTax = 'income_tax';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::BankStatement => 'Extrato bancário',
            self::CreditCardInvoice => 'Fatura de cartão',
            self::InvestmentStatement => 'Extrato de investimentos',
            self::BrokerageNote => 'Nota de corretagem',
            self::PerformanceReport => 'Relatório de rentabilidade',
            self::InsurancePolicy => 'Apólice de seguro',
            self::IncomeTax => 'Declaração de IR',
            self::Other => 'Outro',
        };
    }

    /** O que este documento vira depois de confirmado. */
    public function destination(): string
    {
        return match ($this) {
            self::BankStatement => 'receitas e despesas',
            self::CreditCardInvoice => 'despesas e fatura',
            self::InvestmentStatement => 'investimentos',
            self::BrokerageNote => 'transações de investimento',
            self::PerformanceReport => 'rentabilidade',
            self::InsurancePolicy => 'apólices',
            self::IncomeTax, self::Other => 'nenhum registro automático',
        };
    }

    /** Tipos que a IA sabe extrair hoje. */
    public function isExtractable(): bool
    {
        return ! in_array($this, [self::IncomeTax, self::Other], true);
    }

}
