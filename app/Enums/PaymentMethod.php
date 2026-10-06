<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * As formas de pagamento aceitas na assinatura — sem boleto. Pix Automático é
 * o débito mensal autorizado uma vez no banco do cliente (ver
 * PixAutomaticBillingService); `Pix` é o comum, pago a cada ciclo.
 * `asaasBillingType()` é a única fonte de verdade do mapeamento pro
 * parâmetro `billingType` que a Asaas espera.
 */
enum PaymentMethod: string
{
    use HasOptions;

    case CreditCard = 'credit_card';
    case Pix = 'pix';
    case PixAutomatic = 'pix_automatic';

    /**
     * O que a pessoa pode ESCOLHER hoje: o Pix Automático só entra com a chave
     * de config/billing.php ligada (cases() continua tendo todos, porque
     * assinaturas existentes podem usar qualquer um).
     *
     * @return list<self>
     */
    public static function available(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $metodo): bool => $metodo !== self::PixAutomatic || config('billing.pix_automatic_enabled'),
        ));
    }

    public function label(): string
    {
        return match ($this) {
            self::CreditCard => 'Cartão de crédito',
            self::Pix => 'Pix',
            self::PixAutomatic => 'Pix Automático',
        };
    }

    public function asaasBillingType(): string
    {
        return match ($this) {
            self::CreditCard => 'CREDIT_CARD',
            self::Pix, self::PixAutomatic => 'PIX',
        };
    }
}
