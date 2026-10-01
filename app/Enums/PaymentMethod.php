<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * As únicas duas formas de pagamento aceitas na assinatura — sem boleto.
 * `asaasBillingType()` é a única fonte de verdade do mapeamento pro
 * parâmetro `billingType` que a Asaas espera.
 */
enum PaymentMethod: string
{
    use HasOptions;

    case CreditCard = 'credit_card';
    case Pix = 'pix';

    public function label(): string
    {
        return match ($this) {
            self::CreditCard => 'Cartão de crédito',
            self::Pix => 'Pix',
        };
    }

    public function asaasBillingType(): string
    {
        return match ($this) {
            self::CreditCard => 'CREDIT_CARD',
            self::Pix => 'PIX',
        };
    }
}
