<?php

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * Teto para e-mail de convite e de pedido que o Cerne manda a um ENDEREÇO DE FORA.
 *
 * Sem isto, qualquer conta (ou um profissional mal-intencionado) usava o Cerne para encher a caixa de uma pessoa de e-mails
 * com a nossa marca, ou para queimar a reputação do remetente (e a cota da hospedagem). Dois limites, ambos folgados para
 * quem usa de verdade: por DESTINO (o mesmo endereço não recebe convite demais na mesma hora) e por REMETENTE (por dia).
 * Quem estoura recebe uma RuntimeException com texto para a tela, o mesmo canal de erro que os serviços de convite já usam.
 */
class MailThrottle
{
    public const PER_DESTINATION_PER_HOUR = 4;

    public const PER_SENDER_PER_DAY = 100;

    /** @param  string|null  $senderId  quem pede o envio (nulo: administrador da plataforma sem usuário, ou rotina) */
    public static function guard(?string $senderId, string $email): void
    {
        $destino = 'mail-dest:'.sha1(mb_strtolower(trim($email)));
        $remetente = $senderId === null ? null : 'mail-sender:'.$senderId;

        if (RateLimiter::tooManyAttempts($destino, self::PER_DESTINATION_PER_HOUR)) {
            throw new RuntimeException('Já enviamos convites demais para este e-mail na última hora. Tente novamente mais tarde.');
        }

        if ($remetente !== null && RateLimiter::tooManyAttempts($remetente, self::PER_SENDER_PER_DAY)) {
            throw new RuntimeException('Limite diário de convites enviados atingido. Tente novamente amanhã.');
        }

        RateLimiter::hit($destino, 3600);

        if ($remetente !== null) {
            RateLimiter::hit($remetente, 86400);
        }
    }
}
