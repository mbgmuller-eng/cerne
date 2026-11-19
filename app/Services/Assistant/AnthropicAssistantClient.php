<?php

namespace App\Services\Assistant;

use Anthropic\Client;
use RuntimeException;

/**
 * Chamada real à API. Normaliza a resposta para arrays simples, que é o que o serviço (e o cliente de mentira dos testes) entende.
 */
class AnthropicAssistantClient implements AssistantClient
{
    public function complete(string $system, array $messages, array $tools): array
    {
        $chave = config('cerne.ai.api_key');

        if (blank($chave)) {
            throw new RuntimeException('ANTHROPIC_API_KEY não configurada: o assistente está indisponível.');
        }

        $resposta = (new Client(apiKey: $chave))->messages->create(
            model: config('cerne.ai.model'),
            maxTokens: (int) config('cerne.assistant.max_tokens'),
            system: $system,
            messages: $messages,
            tools: $tools,
        );

        // Recusa volta como HTTP 200 com stop_reason "refusal" e conteúdo vazio.
        if ($resposta->stopReason === 'refusal') {
            throw new RuntimeException('O modelo recusou responder.');
        }

        $blocos = [];
        foreach ($resposta->content as $bloco) {
            if ($bloco->type === 'text') {
                $blocos[] = ['type' => 'text', 'text' => $bloco->text];
            } elseif ($bloco->type === 'tool_use') {
                $blocos[] = ['type' => 'tool_use', 'id' => $bloco->id, 'name' => $bloco->name, 'input' => $bloco->input];
            }
        }

        return ['stop_reason' => (string) $resposta->stopReason, 'content' => $blocos];
    }
}
