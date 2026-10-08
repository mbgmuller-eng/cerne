<?php

namespace App\Services\Extraction;

use App\Enums\DocumentType;

/**
 * Esquemas de saída estruturada, um por tipo de documento.
 *
 * Passar o esquema à API garante JSON válido conforme o formato — sem
 * isso seria preciso extrair JSON de texto livre com expressão regular e
 * torcer, o que numa importação de dado financeiro é inaceitável.
 *
 * Todo objeto declara `additionalProperties: false` e lista `required`,
 * exigência do modo estruturado.
 */
class DocumentSchemas
{
    /** @return array<string, mixed> */
    public static function for(DocumentType $tipo): array
    {
        return match ($tipo) {
            DocumentType::BankStatement => self::bankStatement(),
            DocumentType::CreditCardInvoice => self::creditCardInvoice(),
            DocumentType::InvestmentStatement => self::investmentStatement(),
            DocumentType::BrokerageNote => self::brokerageNote(),
            DocumentType::PerformanceReport => self::performanceReport(),
            DocumentType::InsurancePolicy => self::insurancePolicy(),
            default => self::generic(),
        };
    }

    /**
     * Instrução enviada junto com o PDF.
     *
     * A parte mais importante é a proibição de inventar: um valor
     * "deduzido" num extrato bancário entra no patrimônio do cliente como
     * se fosse real.
     */
    public static function promptFor(DocumentType $tipo): string
    {
        $base = implode("\n", [
            'Você extrai dados de documentos financeiros brasileiros para um aplicativo de finanças pessoais.',
            '',
            'Regras que valem para todo documento:',
            '',
            '- Valores em reais, com ponto decimal e sem separador de milhar: "1234.56". Nunca "R$ 1.234,56".',
            '- Datas em ISO: "2026-08-15".',
            '- Extraia APENAS o que está no documento. Se um campo não estiver legível ou não existir, deixe nulo — nunca invente, estime ou complete por dedução.',
            '- Se o documento estiver ilegível ou não for do tipo informado, devolva a lista vazia e explique em "observacoes".',
            '- Em "observacoes", registre o que não conseguiu ler com certeza. Um humano vai revisar antes de qualquer coisa ser gravada.',
        ]);

        $especifico = match ($tipo) {
            DocumentType::BankStatement => implode("\n", [
                'Este é um EXTRATO BANCÁRIO. Extraia cada movimentação.',
                'Classifique como "receita" o que entrou e "despesa" o que saiu.',
                'Ignore saldos, totalizadores e linhas de saldo anterior.',
            ]),
            DocumentType::CreditCardInvoice => implode("\n", [
                'Esta é uma FATURA DE CARTÃO. Extraia cada lançamento.',
                'Quando a linha indicar parcelamento ("03/10", "PARC 3/10"), preencha parcela_atual e parcela_total.',
                'Ignore o pagamento da fatura anterior, juros de rotativo já somados e o totalizador da fatura.',
            ]),
            DocumentType::InvestmentStatement => implode("\n", [
                'Este é um EXTRATO DE INVESTIMENTOS. Extraia cada ativo em posição, com o valor atual.',
                'Ignore movimentações do período.',
            ]),
            DocumentType::BrokerageNote => implode("\n", [
                'Esta é uma NOTA DE CORRETAGEM. Extraia cada operação com quantidade e preço unitário.',
                'As taxas (corretagem, emolumentos, liquidação) devem vir separadas do valor bruto.',
            ]),
            DocumentType::PerformanceReport => implode("\n", [
                'Este é um RELATÓRIO DE RENTABILIDADE.',
                'Extraia o retorno de cada período apresentado, com o benchmark comparado quando houver.',
            ]),
            DocumentType::InsurancePolicy => implode("\n", [
                'Esta é uma APÓLICE (ou certificado, proposta ou endosso) de SEGURO. Devolva um item por apólice distinta que aparecer no documento; na maioria dos PDFs é um só.',
                '',
                '- tipo: vida (inclui prestamista e acidentes pessoais), carro (automóvel, moto), residencia (residencial, condomínio, empresarial), saude (plano de saúde ou odontológico), viagem, eletronicos (celular, notebook, equipamento portátil), civil (responsabilidade civil), outro.',
                '- seguradora: o nome da seguradora que emite (não o corretor, o estipulante ou o banco que vendeu).',
                '- segurado: nome da pessoa segurada ou titular; em apólice de bem, o proprietário/segurado nomeado.',
                '- objeto_segurado: o que está coberto quando é um bem (modelo, ano e placa do veículo; endereço do imóvel; aparelho). Texto vazio para seguro de pessoa.',
                '- valor_segurado: o valor principal coberto: capital de morte ou capital segurado em vida; valor do bem ou limite máximo de indenização nos demais. Nulo se o documento não trouxer um valor único.',
                '- coberturas: cada proteção contratada com seu valor (limite máximo de indenização ou capital) e a franquia como está escrita no documento (ex.: "R$ 3.500,00" ou "10% dos prejuízos"). Inclua assistências (24h, guincho) com valor nulo quando não houver; franquia em texto vazio quando não houver.',
                '- premio: o valor de CADA pagamento. periodicidade: monthly se paga todo mês (inclusive parcelado em 10x ou 12x), quarterly se trimestral, annual se à vista ou parcela anual única.',
                '- premio_total_anual: o prêmio total da vigência quando o documento informar; senão nulo.',
                '- inicio_vigencia e fim_vigencia: início e fim da vigência do seguro. Seguro vitalício, sem prazo final: fim_vigencia nulo.',
                '- vigente_desde: a partir de que data os valores DESTE documento (prêmio, capitais) valem, como "informações vigentes a partir de" em certificados de seguro de vida que reajustam todo ano; em apólice de bem é o início da vigência da renovação. Nulo se o documento não diz.',
                '- beneficiarios: nome, percentual e parentesco (texto vazio se não informado), quando houver; senão lista vazia.',
                '- observacoes_item: carências, exclusões importantes ou condições especiais em poucas frases; texto vazio se não houver.',
                '- campos_incertos: nomes dos campos acima que você leu com dúvida (ilegível, ambíguo ou conflitante no documento). Lista vazia se tem certeza de tudo.',
            ]),
            default => 'Extraia o que for financeiramente relevante.',
        };

        return $base."\n\n".$especifico;
    }

    // -----------------------------------------------------------------

    /**
     * Envelope comum: identificação do documento + a lista de itens.
     *
     * @param  array<string, mixed>  $itemProperties
     * @param  list<string>  $itemRequired
     * @return array<string, mixed>
     */
    private static function envelope(array $itemProperties, array $itemRequired): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'instituicao' => [
                    'type' => ['string', 'null'],
                    'description' => 'Nome do banco, corretora ou seguradora.',
                ],
                'competencia_mes' => ['type' => ['integer', 'null'], 'description' => '1 a 12'],
                'competencia_ano' => ['type' => ['integer', 'null']],
                'itens' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => $itemProperties,
                        'required' => $itemRequired,
                        'additionalProperties' => false,
                    ],
                ],
                'observacoes' => [
                    'type' => ['string', 'null'],
                    'description' => 'O que não foi possível ler com certeza.',
                ],
            ],
            'required' => ['instituicao', 'competencia_mes', 'competencia_ano', 'itens', 'observacoes'],
            'additionalProperties' => false,
        ];
    }

    private static function bankStatement(): array
    {
        return self::envelope([
            'data' => ['type' => 'string', 'description' => 'ISO 8601'],
            'descricao' => ['type' => 'string'],
            'valor' => ['type' => 'string', 'description' => 'Sempre positivo'],
            'tipo' => ['type' => 'string', 'enum' => ['receita', 'despesa']],
            'categoria_sugerida' => ['type' => ['string', 'null']],
        ], ['data', 'descricao', 'valor', 'tipo', 'categoria_sugerida']);
    }

    private static function creditCardInvoice(): array
    {
        return self::envelope([
            'data' => ['type' => 'string'],
            'descricao' => ['type' => 'string'],
            'valor' => ['type' => 'string'],
            'categoria_sugerida' => ['type' => ['string', 'null']],
            'parcela_atual' => ['type' => ['integer', 'null']],
            'parcela_total' => ['type' => ['integer', 'null']],
        ], ['data', 'descricao', 'valor', 'categoria_sugerida', 'parcela_atual', 'parcela_total']);
    }

    private static function investmentStatement(): array
    {
        return self::envelope([
            'nome' => ['type' => 'string'],
            'ticker' => ['type' => ['string', 'null']],
            'valor_atual' => ['type' => 'string'],
            'quantidade' => ['type' => ['string', 'null']],
            'classe' => [
                'type' => ['string', 'null'],
                'description' => 'cdb, tesouro, lci, lca, fundo, acao, fii, etf, cripto, previdencia, poupanca',
            ],
            'rentabilidade' => ['type' => ['string', 'null'], 'description' => 'Ex: "CDI 102%"'],
            'vencimento' => ['type' => ['string', 'null']],
        ], ['nome', 'ticker', 'valor_atual', 'quantidade', 'classe', 'rentabilidade', 'vencimento']);
    }

    private static function brokerageNote(): array
    {
        return self::envelope([
            'data_operacao' => ['type' => 'string'],
            'ticker' => ['type' => 'string'],
            'tipo' => ['type' => 'string', 'enum' => ['compra', 'venda']],
            'quantidade' => ['type' => 'string'],
            'preco_unitario' => ['type' => 'string'],
            'valor_bruto' => ['type' => 'string'],
            'taxas' => ['type' => ['string', 'null'], 'description' => 'Corretagem + emolumentos'],
        ], ['data_operacao', 'ticker', 'tipo', 'quantidade', 'preco_unitario', 'valor_bruto', 'taxas']);
    }

    private static function performanceReport(): array
    {
        return self::envelope([
            'periodo' => ['type' => 'string', 'description' => 'Ex: "03/2026"'],
            'rentabilidade_percentual' => ['type' => 'string'],
            'benchmark' => ['type' => ['string', 'null'], 'description' => 'cdi, ipca, ibovespa, ifix, sp500'],
            'benchmark_percentual' => ['type' => ['string', 'null']],
            'ativo' => ['type' => ['string', 'null'], 'description' => 'Nulo = carteira consolidada'],
        ], ['periodo', 'rentabilidade_percentual', 'benchmark', 'benchmark_percentual', 'ativo']);
    }

    private static function insurancePolicy(): array
    {
        $dinheiro = ['type' => ['string', 'null'], 'description' => 'Reais com ponto decimal, sem milhar: "1234.56"'];

        return self::envelope([
            'tipo' => ['type' => 'string', 'enum' => ['vida', 'carro', 'residencia', 'saude', 'viagem', 'eletronicos', 'civil', 'outro']],
            'seguradora' => ['type' => ['string', 'null']],
            'numero_apolice' => ['type' => ['string', 'null']],
            'segurado' => ['type' => ['string', 'null']],
            'objeto_segurado' => ['type' => 'string', 'description' => 'Texto vazio quando não há bem segurado'],
            'valor_segurado' => $dinheiro,
            'coberturas' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'nome' => ['type' => 'string'],
                        'valor' => $dinheiro,
                        'franquia' => ['type' => 'string', 'description' => 'Texto vazio quando não há franquia'],
                    ],
                    'required' => ['nome', 'valor', 'franquia'],
                    'additionalProperties' => false,
                ],
            ],
            'premio' => $dinheiro,
            'periodicidade' => ['type' => 'string', 'enum' => ['monthly', 'quarterly', 'annual']],
            'premio_total_anual' => $dinheiro,
            'inicio_vigencia' => ['type' => ['string', 'null'], 'description' => 'ISO 8601'],
            'fim_vigencia' => ['type' => ['string', 'null'], 'description' => 'ISO 8601'],
            'vigente_desde' => ['type' => ['string', 'null'], 'description' => 'ISO 8601'],
            'beneficiarios' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'nome' => ['type' => 'string'],
                        'percentual' => ['type' => 'number'],
                        'parentesco' => ['type' => 'string', 'description' => 'Texto vazio quando não informado'],
                    ],
                    'required' => ['nome', 'percentual', 'parentesco'],
                    'additionalProperties' => false,
                ],
            ],
            'observacoes_item' => ['type' => 'string', 'description' => 'Texto vazio quando não há'],
            'campos_incertos' => ['type' => 'array', 'items' => ['type' => 'string']],
        ], [
            'tipo', 'seguradora', 'numero_apolice', 'segurado', 'objeto_segurado', 'valor_segurado', 'coberturas', 'premio',
            'periodicidade', 'premio_total_anual', 'inicio_vigencia', 'fim_vigencia', 'vigente_desde', 'beneficiarios', 'observacoes_item', 'campos_incertos',
        ]);
    }

    private static function generic(): array
    {
        return self::envelope([
            'descricao' => ['type' => 'string'],
            'valor' => ['type' => ['string', 'null']],
            'data' => ['type' => ['string', 'null']],
        ], ['descricao', 'valor', 'data']);
    }
}
