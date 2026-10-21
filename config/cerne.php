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
    | Notificações
    |---------------------------------------------------------------------------
    | Aviso de vencimento (conta fixa, fatura de cartão) sai com esta
    | antecedência. Não confundir com dashboard.upcoming_bills_days acima —
    | aquele alimenta o card da Visão Geral, este dispara notificação de
    | verdade (sino/e-mail/push).
    */

    'notifications' => [
        'days_before_due' => env('CERNE_NOTIFY_DAYS_BEFORE_DUE', 3),

        // Datas importantes (aniversário de cliente, renovação/vencimento
        // de apólice, vencimento de investimento) avisam o profissional com
        // mais folga que uma conta vencendo — dá tempo de ligar pro
        // cliente antes da data, não só no dia.
        'days_before_important_date' => env('CERNE_NOTIFY_DAYS_BEFORE_IMPORTANT_DATE', 7),

        // Consulta/exame: 1 dia é o suficiente pra lembrar sem virar ruído
        // — diferente de conta e apólice, marcar com muita antecedência
        // não muda nada que a pessoa precise FAZER antes da data.
        'days_before_appointment' => env('CERNE_NOTIFY_DAYS_BEFORE_APPOINTMENT', 1),

        // Itens de saúde de troca periódica (filtro do aparelho auditivo, palmilha...): avisa
        // este tanto de dias antes e também no próprio dia.
        'days_before_care_item' => env('CERNE_NOTIFY_DAYS_BEFORE_CARE_ITEM', 1),
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

        // Sonnet, não Opus: é leitura de documento estruturado (extrato,
        // fatura, apólice), não raciocínio complexo — Opus custaria muito
        // mais por token sem ganho perceptível aqui.
        'model' => env('CERNE_AI_MODEL', 'claude-sonnet-5'),

        // Extrato de dois meses de conta bancária movimentada passa de 200
        // lançamentos reais — o teto precisa acomodar o JSON inteiro, senão
        // a resposta vem truncada (stop_reason "max_tokens") antes de listar
        // tudo. 16000 dava conta de um mês; 32000 ainda truncou o mesmo
        // extrato de dois meses numa segunda tentativa (o tamanho da saída
        // varia de execução pra execução). Dobrado de novo, com folga maior.
        'max_tokens' => 64000,

        // 'high' lê melhor as tabelas mal formatadas de PDF escaneado,
        // que é o caso comum de extrato bancário brasileiro.
        'effort' => env('CERNE_AI_EFFORT', 'high'),

        'max_upload_mb' => 32,
        'max_pdf_pages' => 600,
        'require_review' => true,
        'job_tries' => 3,

        // Cada leitura de apólice é uma chamada paga à API: teto por perfil e por dia, para um
        // envio repetido por engano não virar conta. Tentativas que falharam também contam.
        'insurance_imports_per_day' => (int) env('CERNE_INSURANCE_IMPORTS_PER_DAY', 10),

        // Idem para o extrato de consórcio lido em Dívidas.
        'consortium_imports_per_day' => (int) env('CERNE_CONSORTIUM_IMPORTS_PER_DAY', 10),

        // Idem para a simulação em PDF de um lead (ver LeadQuoteExtractionService), por profissional.
        'lead_quote_reads_per_day' => (int) env('CERNE_LEAD_QUOTE_READS_PER_DAY', 20),

        // Idem para relatório de outro aplicativo (ver DocumentsIndex::enviar).
        'external_imports_per_day' => (int) env('CERNE_EXTERNAL_IMPORTS_PER_DAY', 10),
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
    | Fotos de exercício (Cerne Saúde › Academia)
    |---------------------------------------------------------------------------
    | Mesmo disco privado dos documentos: é dado de saúde, servido só pra
    | quem tem acesso ao exercício (ver GymExerciseImageController).
    */

    'gym_images' => [
        'disk' => env('CERNE_DOCUMENTS_DISK', 'local'),
        'path' => 'academia-imagens',
        'max_kb' => 4096,
    ],

    /*
    |---------------------------------------------------------------------------
    | Área de Documentos (CNH, passaporte, apólice, exame...)
    |---------------------------------------------------------------------------
    | Mesmo disco privado dos demais documentos — pasta própria só pra não
    | misturar com o que a importação por IA usa (cerne.documents.path).
    */

    'document_vault' => [
        'disk' => env('CERNE_DOCUMENTS_DISK', 'local'),
        'path' => 'documentos-pessoais',
        'max_kb' => 10240,
    ],

    /*
    |---------------------------------------------------------------------------
    | Anexos de leads (simulações em PDF, documentos do contato)
    |---------------------------------------------------------------------------
    | Disco privado, só abre para o dono do lead. Simulação de vida e saúde
    | carrega dado sensível de alguém que ainda não é cliente: os anexos de um
    | lead PERDIDO saem do servidor depois de `retention_months` meses (ver
    | LeadService::purgeExpiredAttachments()).
    */

    // Resumo diário de leads (ver LeadAlertService): com quantos dias de antecedência avisar uma cotação que vence e
    // depois de quantos dias sem contato um lead é considerado parado.
    'lead_alerts' => [
        'quote_days' => (int) env('CERNE_LEAD_ALERT_QUOTE_DAYS', 3),
        'stale_days' => (int) env('CERNE_LEAD_ALERT_STALE_DAYS', 7),
    ],

    // Leads criados a partir da carteira (ver LeadAutomationService): com quantos dias de antecedência uma renovação vira
    // lead, quantos dias antes da data o primeiro contato fica marcado e quais tipos de seguro entram na venda cruzada.
    'lead_automation' => [
        'renewal_days' => (int) env('CERNE_LEAD_RENEWAL_DAYS', 45),
        'contact_days_before' => (int) env('CERNE_LEAD_RENEWAL_CONTACT_DAYS', 30),
        // Cada profissional escolhe os tipos que geram lead de renovação. Sem escolha, todos menos vida: o seguro de
        // vida costuma renovar sozinho.
        'renewal_default_types' => ['carro', 'residencia', 'saude', 'viagem', 'eletronicos', 'civil', 'outro'],
        'cross_sell_types' => ['vida', 'residencia', 'saude'],
    ],

    'lead_files' => [
        'disk' => env('CERNE_DOCUMENTS_DISK', 'local'),
        'path' => 'leads-anexos',
        'max_kb' => 10240,
        'max_per_lead' => 20,
        'retention_months' => 12,
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

    /*
    |---------------------------------------------------------------------------
    | Privacidade e LGPD
    |---------------------------------------------------------------------------
    | As versões mudam quando o TEXTO dos Termos, da Política ou do consentimento de
    | saúde muda de forma que exija novo aceite: todo mundo aceita de novo no
    | próximo acesso. O controlador e o encarregado (DPO) aparecem na Política; o
    | cerne:check avisa enquanto o e-mail do encarregado não estiver definido.
    */

    'legal' => [
        'terms_version' => '2026-10-22',
        'health_consent_version' => '2026-10-22',
        'controller_name' => env('CERNE_CONTROLLER_NAME'),
        'controller_cnpj' => env('CERNE_CONTROLLER_CNPJ'),
        'dpo_name' => env('CERNE_DPO_NAME'),
        'dpo_email' => env('CERNE_DPO_EMAIL'),
        // O PDF original de extrato/fatura/apólice sai do servidor depois de lido e confirmado (ou descartado) e deste prazo (ver DataRetentionService).
        'source_pdf_days' => 90,
    ],

    /*
    |---------------------------------------------------------------------------
    | Acompanhamento de erros
    |---------------------------------------------------------------------------
    | Todo erro da plataforma é anotado (ErrorRecorder) e o relatório do dia
    | anterior sai por e-mail às 08:10. Sem ERROR_REPORT_EMAILS (separados por
    | vírgula), vai para as contas de administrador da plataforma.
    */

    'error_tracking' => [
        'enabled' => (bool) env('ERROR_TRACKING', true),
        'report_to' => array_filter(array_map('trim', explode(',', (string) env('ERROR_REPORT_EMAILS', '')))),
    ],

];
