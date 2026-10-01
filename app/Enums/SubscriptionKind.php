<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Quem a assinatura cobre. `Direct` é o cliente pagando por si — só
 * libera o próprio perfil. `Professional` é o consultor/corretor pagando
 * por um pacote que cobre, de graça, todo cliente vinculado e ATIVO dele
 * (ver ConsultantClient::active(), já usado em FinancialProfilePolicy) —
 * sem teto de quantidade por ora (ver plano, seção "fora de escopo").
 */
enum SubscriptionKind: string
{
    use HasOptions;

    case Direct = 'direct';
    case Professional = 'professional';

    public function label(): string
    {
        return match ($this) {
            self::Direct => 'Direta',
            self::Professional => 'Profissional',
        };
    }
}
