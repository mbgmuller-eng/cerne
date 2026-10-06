<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A Asaas criou a assinatura com uma forma de pagamento DIFERENTE da pedida
 * (ex.: pedimos PIX e voltou BOLETO). Boleto não é forma de pagamento aceita
 * no Cerne: quem lança isto já desfez a assinatura na Asaas antes.
 */
class AsaasBillingTypeMismatch extends RuntimeException
{
    public function __construct(public readonly string $pedido, public readonly string $recebido)
    {
        parent::__construct("A Asaas criou a assinatura como {$recebido} em vez de {$pedido}.");
    }
}
