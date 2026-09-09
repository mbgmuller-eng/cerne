<?php

namespace App\Services\Extraction;

use Anthropic\Client;
use RuntimeException;

/**
 * Extrai uma despesa a partir de uma frase falada e transcrita no
 * navegador (Web Speech API) — "gastei 45 reais de uber pra casa" vira
 * descrição + valor + necessidade + categoria sugeridas.
 *
 * Mesmo espírito do DocumentExtractionService (regra 5 do CLAUDE.md):
 * o resultado só pré-preenche o formulário de despesa já existente —
 * nada é gravado até a pessoa revisar e confirmar, exatamente como uma
 * despesa digitada à mão. Sem documento nem lista de itens (é sempre
 * uma frase, um gasto só), por isso um schema bem mais simples que o
 * de DocumentSchemas.
 */
class VoiceExpenseExtractionService
{
    /** Frase curta — nem perto do teto usado pra extrato/fatura em DocumentExtractionService. */
    private const MAX_TOKENS = 512;

    /**
     * @param  list<string>  $categoriasDisponiveis  nomes exatos das categorias de despesa cadastradas — a IA só pode sugerir um destes nomes (ou null).
     * @return array{descricao: string, valor: ?string, data: ?string, necessidade_sugerida: ?string, categoria_sugerida: ?string}
     */
    public function extract(string $transcricao, array $categoriasDisponiveis): array
    {
        $resposta = $this->client()->messages->create(
            model: config('cerne.ai.model'),
            maxTokens: self::MAX_TOKENS,
            system: $this->prompt($categoriasDisponiveis),
            messages: [[
                'role' => 'user',
                'content' => $transcricao,
            ]],
            outputConfig: [
                'format' => [
                    'type' => 'json_schema',
                    'schema' => $this->schema(),
                ],
                // 'low': é uma frase curta, não uma tabela de PDF mal
                // escaneada — o 'high' que a importação de documento usa
                // custaria mais e responderia mais devagar à toa aqui.
                'effort' => 'low',
            ],
        );

        // Uma recusa devolve HTTP 200 com stop_reason "refusal" e conteúdo
        // vazio — ler content[0] direto quebraria aqui.
        if ($resposta->stopReason === 'refusal') {
            throw new RuntimeException('O modelo recusou processar essa frase.');
        }

        foreach ($resposta->content as $bloco) {
            if ($bloco->type === 'text') {
                $json = json_decode($bloco->text, true, flags: JSON_THROW_ON_ERROR);

                return is_array($json) ? $json : [];
            }
        }

        throw new RuntimeException('A resposta da API não trouxe conteúdo.');
    }

    /** @param  list<string>  $categoriasDisponiveis */
    private function prompt(array $categoriasDisponiveis): string
    {
        $listaCategorias = implode(', ', array_map(fn (string $c) => "\"{$c}\"", $categoriasDisponiveis));

        return implode("\n", [
            'Você extrai uma despesa a partir de uma frase falada em português por um usuário de um app de finanças pessoais brasileiro, transcrita por reconhecimento de voz (pode ter erros de transcrição — interprete com bom senso).',
            '',
            'A pessoa acabou de fazer um gasto e está descrevendo em voz alta, na hora — frases como "gastei 45 reais de uber" ou "paguei 12 e 50 no café" ou "50 conto de gasolina".',
            '',
            '- descricao: um resumo curto do gasto (ex.: "Uber", "Café"). Sempre preencha, mesmo que genérico como "Gasto".',
            '- valor: em reais, decimal com ponto, sem separador de milhar (ex.: "45.00"). null se não conseguir identificar um valor com confiança — nunca invente um número que não foi dito.',
            '- data: ISO 8601 (ex.: "2026-09-09"), só quando a pessoa mencionar explicitamente quando foi ("ontem", "sexta passada"). null se não mencionar — o app assume hoje.',
            '- necessidade_sugerida: sua melhor interpretação do gasto — "essential" (conta, necessidade básica), "discretionary" (lazer, supérfluo) ou "investment" (aporte, investimento). Sempre escolha uma das três, mesmo com pouca certeza.',
            "- categoria_sugerida: EXATAMENTE um destes nomes, ou null se nenhum encaixar: {$listaCategorias}.",
        ]);
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'descricao' => ['type' => 'string'],
                'valor' => ['type' => ['string', 'null']],
                'data' => ['type' => ['string', 'null']],
                'necessidade_sugerida' => ['type' => 'string', 'enum' => ['essential', 'discretionary', 'investment']],
                'categoria_sugerida' => ['type' => ['string', 'null']],
            ],
            'required' => ['descricao', 'valor', 'data', 'necessidade_sugerida', 'categoria_sugerida'],
            'additionalProperties' => false,
        ];
    }

    private function client(): Client
    {
        $chave = config('cerne.ai.api_key');

        if (blank($chave)) {
            throw new RuntimeException(
                'ANTHROPIC_API_KEY não configurada — a despesa por voz está indisponível.'
            );
        }

        return new Client(apiKey: $chave);
    }
}
