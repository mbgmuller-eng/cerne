<?php

namespace App\Services\Assistant;

/**
 * A conversa com o modelo, atrás de uma interface: o serviço de produção chama a API da Anthropic, e os testes trocam por um cliente de mentira
 * (nenhum teste gasta chamada paga nem precisa de chave).
 */
interface AssistantClient
{
    /**
     * @param  list<array<string, mixed>>  $messages  no formato da API (role + content, com blocos text, tool_use e tool_result)
     * @param  list<array<string, mixed>>  $tools  definições (name, description, input_schema)
     * @return array{stop_reason: string, content: list<array<string, mixed>>}  blocos normalizados: text (text) e tool_use (id, name, input)
     */
    public function complete(string $system, array $messages, array $tools): array;
}
