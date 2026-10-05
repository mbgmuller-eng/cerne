<?php

use App\Enums\SubscriptionBundle;

return [

    /*
    |---------------------------------------------------------------------------
    | Preço de cada pacote (usuário final)
    |---------------------------------------------------------------------------
    | Valor mensal, em reais (igual money do resto do app: decimal, nunca
    | float). Marcelo edita aqui quando quiser mudar o preço — sem tabela
    | admin por ora (ver o plano: fica pra quando o padrão de uso aparecer).
    |
    | Vale só pra assinatura DIRETA. O profissional não escolhe pacote: o
    | preço dele depende só de quantos clientes vincula (ver 'professional').
    */

    'prices' => [
        SubscriptionBundle::FinancasSegurosDocumentos->value => 19.90,
        SubscriptionBundle::SaudeDocumentos->value => 15.90,
        SubscriptionBundle::Completo->value => 29.90,
    ],

    /*
    |---------------------------------------------------------------------------
    | Preço do profissional (consultor/corretor), por quantidade de clientes
    |---------------------------------------------------------------------------
    | Sem diferença entre pacotes: o que o profissional enxerga já é limitado
    | por regra própria, e os clientes dele recebem tudo (pacote Completo).
    |
    | Até `first_tier_clients` paga `first_tier_price`; até `second_tier_clients`
    | paga `second_tier_price`; depois disso cada bloco de `extra_block_clients`
    | clientes a mais soma `extra_block_price`. Ex.: 50 clientes = 149,90 +
    | 3 x 59,90 = 329,60. Strings, porque o cálculo é em bcmath
    | (ver App\Support\ProfessionalPricing).
    |
    | Acima de `max_self_service_clients` não é self-service — vira acordo
    | manual, concedido direto no banco com client_cap = null (sem teto), mesmo
    | caminho que já criou a assinatura de cortesia do Marcelo.
    */

    'professional' => [
        'first_tier_clients' => 10,
        'first_tier_price' => '79.90',
        'second_tier_clients' => 20,
        'second_tier_price' => '149.90',
        'extra_block_clients' => 10,
        'extra_block_price' => '59.90',
        'max_self_service_clients' => 100,
    ],

];
