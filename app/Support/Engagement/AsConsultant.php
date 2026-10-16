<?php

namespace App\Support\Engagement;

use App\Models\FinancialProfile;
use App\Support\ProfileContext;

/**
 * Executa algo "da perspectiva do consultor" dentro do perfil de um cliente: os models de dado só enxergam o perfil do contexto,
 * e o consultor vinculado enxerga tudo o que a regra do módulo deixa (o que é privado entre o casal incluso; o que o módulo
 * trava para profissionais, como documentos pessoais, continua travado).
 *
 * Restaura o contexto de quem chamou ao terminar, mesmo com erro, porque o ProfileContext é um singleton da requisição.
 */
final class AsConsultant
{
    /**
     * @template T
     *
     * @param  callable(): T  $acao
     * @return T
     */
    public static function run(FinancialProfile $perfil, callable $acao): mixed
    {
        $contexto = app(ProfileContext::class);
        $antes = [$contexto->profile(), $contexto->member(), $contexto->isConsultant()];

        $contexto->set($perfil, null, true);

        try {
            return $acao();
        } finally {
            $antes[0] !== null ? $contexto->set($antes[0], $antes[1], $antes[2]) : $contexto->clear();
        }
    }
}
