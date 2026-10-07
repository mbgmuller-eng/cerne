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

    /*
    |---------------------------------------------------------------------------
    | Pix Automático
    |---------------------------------------------------------------------------
    | Chave de liberação: enquanto estiver desligada a opção nem aparece no
    | checkout. Ligar só depois de conferir, na Asaas de PRODUÇÃO, que a conta
    | está elegível e que o webhook recebe os eventos PIX_AUTOMATIC_RECURRING_*.
    | Assinaturas que já usam o método continuam funcionando com a chave
    | desligada (ela só esconde a opção para quem ainda vai assinar).
    */

    /*
    |---------------------------------------------------------------------------
    | Bloqueio por falta de assinatura
    |---------------------------------------------------------------------------
    | Ligado, quem não tem assinatura em vigor (teste grátis acabou, pagamento
    | não entrou) é levado à tela de assinatura em toda página. Desligado por
    | padrão: ligar só depois de revisar quais contas existentes ficariam sem
    | acesso (cerne:check mostra o aviso enquanto estiver desligado em produção).
    */

    /*
    |---------------------------------------------------------------------------
    | Nota fiscal de serviço (NFS-e) pela Asaas
    |---------------------------------------------------------------------------
    | Ligado, cada assinatura criada na Asaas já nasce com a emissão automática
    | da nota na confirmação do pagamento (R$ 0,49 por nota). Desligado por
    | padrão: antes de ligar, a conta da Asaas precisa ter a parte fiscal pronta
    | (certificado digital, inscrição municipal, regime e serviço) — o contador
    | define o serviço (lista em GET /v3/fiscalInfo/services) e as alíquotas.
    |
    | Informe o serviço por id (municipal_service_id) OU por código
    | (municipal_service_code, ex.: 1.05). `iss` é a alíquota em % e é obrigatória
    | (use 0 se o contador mandar); os demais impostos ficam em 0 salvo orientação.
    */

    'invoices' => [
        'enabled' => (bool) env('BILLING_ISSUE_INVOICES', false),
        'municipal_service_id' => env('BILLING_NF_SERVICE_ID'),
        'municipal_service_code' => env('BILLING_NF_SERVICE_CODE'),
        'municipal_service_name' => env('BILLING_NF_SERVICE_NAME', 'Licenciamento ou cessão de direito de uso de programas de computação'),
        'observations' => 'Assinatura do Cerne',
        'taxes' => [
            'retain_iss' => (bool) env('BILLING_NF_RETAIN_ISS', false),
            'iss' => env('BILLING_NF_ISS'),
            'cofins' => env('BILLING_NF_COFINS', 0),
            'csll' => env('BILLING_NF_CSLL', 0),
            'inss' => env('BILLING_NF_INSS', 0),
            'ir' => env('BILLING_NF_IR', 0),
            'pis' => env('BILLING_NF_PIS', 0),
        ],
    ],

    'enforce_access' => (bool) env('BILLING_ENFORCE_ACCESS', false),

    'pix_automatic_enabled' => (bool) env('BILLING_PIX_AUTOMATIC', false),

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
