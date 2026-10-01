<?php

use App\Enums\SubscriptionBundle;

return [

    /*
    |---------------------------------------------------------------------------
    | Preço de cada pacote
    |---------------------------------------------------------------------------
    | Valor mensal, em reais (igual money do resto do app: decimal, nunca
    | float). Marcelo edita aqui quando quiser mudar o preço — sem tabela
    | admin por ora (ver o plano: fica pra quando o padrão de uso aparecer).
    */

    'prices' => [
        SubscriptionBundle::FinancasSegurosDocumentos->value => 29.90,
        SubscriptionBundle::SaudeDocumentos->value => 19.90,
        SubscriptionBundle::Completo->value => 39.90,
    ],

];
