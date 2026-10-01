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

    /*
    |---------------------------------------------------------------------------
    | Faixas de clientes (só assinatura Professional)
    |---------------------------------------------------------------------------
    | Chave = teto de clientes vinculados e ativos; valor = acréscimo
    | mensal somado ao preço do pacote (ver 'prices' acima). Acima da maior
    | faixa não é self-service — vira acordo manual, concedido direto no
    | banco com client_cap = null (sem teto), mesmo caminho que já criou a
    | assinatura de cortesia do Marcelo. Ver ConsultantCapacityService.
    */

    'client_tier_surcharge' => [
        10 => 0,
        25 => 50.00,
        50 => 120.00,
    ],

];
