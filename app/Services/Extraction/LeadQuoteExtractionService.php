<?php

namespace App\Services\Extraction;

use App\Enums\InsuranceType;
use App\Enums\PaymentFrequency;
use App\Enums\ProcessingStatus;
use App\Jobs\ExtractLeadQuoteJob;
use App\Models\LeadAttachment;
use App\Models\User;
use Anthropic\Client;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Lê a simulação em PDF de um lead de seguros e devolve as cotações que ela traz, pela API da Anthropic.
 *
 * O resultado NÃO vira cotação aqui. Fica no anexo (extraction_summary) aguardando revisão: a tela abre o formulário de
 * cotação pré-preenchido e só grava quando o profissional confere e salva. Dado extraído por IA nunca entra sozinho.
 */
class LeadQuoteExtractionService
{
    private const MAX_BYTES = 32 * 1024 * 1024;

    /** Quantas leituras o profissional já pediu hoje (as que falharam também contam: cada uma foi uma chamada paga). */
    public function readsToday(User $consultant): int
    {
        return LeadAttachment::query()
            ->whereHas('lead', fn ($q) => $q->where('consultant_id', $consultant->id))
            ->where('extraction_requested_at', '>=', now()->startOfDay())
            ->count();
    }

    public function limitReached(User $consultant): bool
    {
        return $this->readsToday($consultant) >= config('cerne.ai.lead_quote_reads_per_day');
    }

    /** Marca o anexo como na fila e despacha a leitura. O teto diário é conferido por quem chama. */
    public function request(LeadAttachment $anexo): void
    {
        $anexo->update([
            'extraction_status' => ProcessingStatus::Pending,
            'extraction_requested_at' => now(),
            'extraction_summary' => null,
            'extraction_error' => null,
            'extraction_applied_at' => null,
        ]);

        ExtractLeadQuoteJob::dispatch($anexo->id);
    }

    public function extract(LeadAttachment $anexo): LeadAttachment
    {
        if (! $anexo->isPdf()) {
            return $this->fail($anexo, 'A leitura automática vale só para PDF.');
        }

        $conteudo = Storage::disk(config('cerne.lead_files.disk'))->get($anexo->storage_path);

        if ($conteudo === null) {
            return $this->fail($anexo, 'O arquivo não está mais no servidor.');
        }

        if (strlen($conteudo) > self::MAX_BYTES) {
            return $this->fail($anexo, 'O arquivo excede o limite de 32 MB da leitura.');
        }

        $anexo->update(['extraction_status' => ProcessingStatus::Processing]);

        try {
            $dados = $this->ask($conteudo);
        } catch (\Throwable $e) {
            report($e);

            return $this->fail($anexo, 'Falha ao ler: '.mb_substr($e->getMessage(), 0, 400));
        }

        $anexo->update([
            'extraction_status' => ProcessingStatus::Completed,
            'extraction_summary' => [
                'cotacoes' => array_values($dados['cotacoes'] ?? []),
                'observacoes' => $dados['observacoes'] ?? null,
                'aplicadas' => [],
                'extraido_em' => now()->toIso8601String(),
                'modelo' => config('cerne.ai.model'),
            ],
            'extraction_error' => null,
        ]);

        return $anexo->refresh();
    }

    public function fail(LeadAttachment $anexo, string $mensagem): LeadAttachment
    {
        $anexo->update(['extraction_status' => ProcessingStatus::Failed, 'extraction_error' => mb_substr($mensagem, 0, 500)]);

        return $anexo->refresh();
    }

    /**
     * A chamada em si (separada para os testes não dependerem da API).
     *
     * @return array<string, mixed>
     */
    protected function ask(string $conteudo): array
    {
        $resposta = $this->client()->messages->create(
            model: config('cerne.ai.model'),
            maxTokens: config('cerne.ai.max_tokens'),
            system: self::prompt(),
            messages: [[
                'role' => 'user',
                'content' => [
                    ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($conteudo)]],
                    ['type' => 'text', 'text' => 'Extraia as cotações desta simulação seguindo o formato definido.'],
                ],
            ]],
            outputConfig: [
                'format' => ['type' => 'json_schema', 'schema' => self::schema()],
                'effort' => config('cerne.ai.effort'),
            ],
        );

        if ($resposta->stopReason === 'refusal') {
            throw new RuntimeException('O modelo recusou processar este documento.');
        }

        if ($resposta->stopReason === 'max_tokens') {
            throw new RuntimeException('O documento é extenso demais para uma leitura única.');
        }

        foreach ($resposta->content as $bloco) {
            if ($bloco->type === 'text') {
                $json = json_decode($bloco->text, true, flags: JSON_THROW_ON_ERROR);

                return is_array($json) ? $json : [];
            }
        }

        throw new RuntimeException('A resposta da API não trouxe conteúdo.');
    }

    private function client(): Client
    {
        $chave = config('cerne.ai.api_key');

        if (blank($chave)) {
            throw new RuntimeException('ANTHROPIC_API_KEY não configurada: a leitura por IA está indisponível.');
        }

        return new Client(apiKey: $chave);
    }

    public static function prompt(): string
    {
        return implode("\n", [
            'Você lê simulações e cotações de seguro brasileiras para um aplicativo de corretores e consultores.',
            '',
            'Regras:',
            '- Valores em reais com ponto decimal e sem separador de milhar: "1234.56". Nunca "R$ 1.234,56".',
            '- Datas em ISO: "2026-08-15".',
            '- Extraia APENAS o que está no documento. Se um campo não estiver legível ou não existir, deixe nulo ou texto vazio. Nunca invente, estime nem complete por dedução.',
            '- Um humano vai conferir tudo antes de qualquer coisa ser gravada. Em "observacoes", registre o que não conseguiu ler com certeza.',
            '',
            'Este documento é uma SIMULAÇÃO ou COTAÇÃO de seguro (ainda não é a apólice emitida). Devolva um item em "cotacoes" por alternativa distinta: uma por seguradora ou por plano. Na maioria dos PDFs é uma só; comparativos trazem várias.',
            '- tipo: vida (inclui prestamista e acidentes pessoais), carro (automóvel, moto), residencia (residencial, condomínio, empresarial), saude (plano de saúde ou odontológico), viagem, eletronicos, civil (responsabilidade civil), outro.',
            '- seguradora: quem emitiria o seguro (não o corretor nem o simulador).',
            '- produto: nome do produto ou plano. numero_simulacao: o número ou protocolo da simulação. Texto vazio se não traz.',
            '- segurado: nome da pessoa ou do proprietário do bem, quando consta.',
            '- valor_segurado: o capital principal (morte, em vida) ou o valor do bem / limite máximo de indenização nos demais. Nulo se não houver um valor único.',
            '- coberturas: cada proteção com seu valor (limite ou capital) e a franquia como escrita (ex.: "R$ 3.500,00" ou "10% dos prejuízos"). Assistências com valor nulo. Franquia em texto vazio quando não houver.',
            '- premio: o valor de CADA pagamento. periodicidade: monthly se paga todo mês (inclusive parcelado em 10x ou 12x), quarterly se trimestral, annual se à vista ou parcela anual única.',
            '- premio_total_anual: o total do ano quando o documento informar; senão nulo.',
            '- validade: até quando a cotação vale ("válida até"), quando consta; texto vazio se não traz.',
            '- observacoes_item: carências, exclusões ou condições importantes em poucas frases; texto vazio se não houver.',
            '- campos_incertos: nomes dos campos acima que você leu com dúvida. Lista vazia se tem certeza de tudo.',
        ]);
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $dinheiro = ['type' => ['string', 'null'], 'description' => 'Reais com ponto decimal, sem milhar: "1234.56"'];
        $texto = ['type' => 'string', 'description' => 'Texto vazio quando o documento não traz'];

        // Texto e não "string ou nulo" onde der: a API recusa esquema com mais de 16 campos "valor ou nulo".
        return [
            'type' => 'object',
            'properties' => [
                'cotacoes' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'tipo' => ['type' => 'string', 'enum' => array_column(InsuranceType::cases(), 'value')],
                            'seguradora' => $texto,
                            'produto' => $texto,
                            'numero_simulacao' => $texto,
                            'segurado' => $texto,
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
                            'periodicidade' => ['type' => 'string', 'enum' => array_column(PaymentFrequency::cases(), 'value')],
                            'premio_total_anual' => $dinheiro,
                            'validade' => ['type' => 'string', 'description' => 'AAAA-MM-DD; texto vazio quando o documento não traz'],
                            'observacoes_item' => $texto,
                            'campos_incertos' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                        'required' => [
                            'tipo', 'seguradora', 'produto', 'numero_simulacao', 'segurado', 'valor_segurado', 'coberturas', 'premio',
                            'periodicidade', 'premio_total_anual', 'validade', 'observacoes_item', 'campos_incertos',
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'observacoes' => ['type' => 'string', 'description' => 'O que não foi possível ler com certeza; texto vazio se tudo foi lido'],
            ],
            'required' => ['cotacoes', 'observacoes'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Uma cotação lida, convertida nos campos do formulário de cotação. A tela só preenche; quem grava é a revisão.
     *
     * @param  array<string, mixed>  $item
     * @return array{type: string, insurer: string, product: string, reference: string, premium: string, frequency: string, coverageAmount: string, coverages: list<array{name: string, value: string, deductible: string}>, validUntil: string, notes: string, uncertain: list<string>}
     */
    public static function toFormValues(array $item): array
    {
        $tipo = InsuranceType::tryFrom((string) ($item['tipo'] ?? ''))?->value ?? InsuranceType::Outro->value;
        $frequencia = PaymentFrequency::tryFrom((string) ($item['periodicidade'] ?? ''))?->value ?? PaymentFrequency::Monthly->value;
        $premio = self::money($item['premio'] ?? null);

        // Só o total anual no documento: vira um pagamento anual, em vez de deixar o prêmio em branco.
        if ($premio === '' && self::money($item['premio_total_anual'] ?? null) !== '') {
            $premio = self::money($item['premio_total_anual']);
            $frequencia = PaymentFrequency::Annual->value;
        }

        $validade = (string) ($item['validade'] ?? '');

        return [
            'type' => $tipo,
            'insurer' => trim((string) ($item['seguradora'] ?? '')),
            'product' => trim((string) ($item['produto'] ?? '')),
            'reference' => trim((string) ($item['numero_simulacao'] ?? '')),
            'premium' => $premio,
            'frequency' => $frequencia,
            'coverageAmount' => self::money($item['valor_segurado'] ?? null),
            'coverages' => array_values(array_map(fn (array $c) => [
                'name' => trim((string) ($c['nome'] ?? '')),
                'value' => self::money($c['valor'] ?? null),
                'deductible' => trim((string) ($c['franquia'] ?? '')),
            ], array_filter($item['coberturas'] ?? [], 'is_array'))),
            'validUntil' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $validade) === 1 && strtotime($validade) !== false ? $validade : '',
            'notes' => trim((string) ($item['observacoes_item'] ?? '')),
            'uncertain' => array_values(array_filter((array) ($item['campos_incertos'] ?? []), 'is_string')),
        ];
    }

    private static function money(mixed $valor): string
    {
        if ($valor === null || $valor === '') {
            return '';
        }

        $texto = trim((string) $valor);

        return is_numeric($texto) && (float) $texto >= 0 ? $texto : '';
    }
}
