<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Convite de cliente
    |---------------------------------------------------------------------------
    | O consultor convida o cliente por e-mail; o token vale por este número
    | de dias (seção 2 da especificação).
    */

    'invite' => [
        'expires_in_days' => 7,
    ],

    /*
    |---------------------------------------------------------------------------
    | Parcelamento
    |---------------------------------------------------------------------------
    | Compras parceladas geram UMA transação por parcela por ciclo de fatura,
    | nunca um lançamento único de valor total (seção 4).
    */

    'installments' => [
        'max' => 48,
    ],

    /*
    |---------------------------------------------------------------------------
    | Reservas
    |---------------------------------------------------------------------------
    | Meta sugerida da reserva de paz = custo mensal médio x meses.
    */

    'reserves' => [
        'default_months_target' => 6,
    ],

    /*
    |---------------------------------------------------------------------------
    | Dashboard
    |---------------------------------------------------------------------------
    | As agregações da Visão Geral são caras; ficam em cache por perfil.
    */

    'dashboard' => [
        'cache_ttl_minutes' => 15,
        'evolution_months' => 12,
        'upcoming_bills_days' => 7,
    ],

    /*
    |---------------------------------------------------------------------------
    | Importação de documentos com IA
    |---------------------------------------------------------------------------
    | Limites da API: 32 MB por requisição e 600 páginas por PDF. O limite de
    | upload é menor de propósito, para caber no post_max_size do PHP na
    | hospedagem compartilhada.
    |
    | `require_review` mantém a extração em rascunho até o usuário conferir.
    | Gravar lançamento financeiro extraído por IA sem revisão é risco que
    | não compensa — deixe ligado salvo decisão explícita em contrário.
    */

    'ai' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('CERNE_AI_MODEL', 'claude-opus-5'),

        // Extrato de um mês pode ter centenas de linhas; o teto precisa
        // acomodar o JSON inteiro, senão a resposta vem truncada.
        'max_tokens' => 16000,

        // 'high' lê melhor as tabelas mal formatadas de PDF escaneado,
        // que é o caso comum de extrato bancário brasileiro.
        'effort' => env('CERNE_AI_EFFORT', 'high'),

        'max_upload_mb' => 32,
        'max_pdf_pages' => 600,
        'require_review' => true,
        'job_tries' => 3,
    ],

    /*
    |---------------------------------------------------------------------------
    | Armazenamento de documentos
    |---------------------------------------------------------------------------
    | Disco privado: extrato bancário não pode ficar em pasta pública.
    */

    'documents' => [
        'disk' => env('CERNE_DOCUMENTS_DISK', 'local'),
        'path' => 'documentos',
    ],

    /*
    |---------------------------------------------------------------------------
    | Precisão numérica
    |---------------------------------------------------------------------------
    | Dinheiro em 2 casas; quantidade e preço médio de ativos em 6, porque
    | cripto e fundos operam com frações pequenas.
    */

    'scale' => [
        'money' => 2,
        'quantity' => 6,
    ],

];
