<?php

namespace App\Support;

use App\Enums\InsuranceType;

/**
 * O que se pergunta ao interessado para cotar cada tipo de seguro. Um conjunto mínimo por tipo, não um formulário
 * gigante: o suficiente para digitar a simulação no portal da seguradora sem ligar de novo para o cliente. Os tipos
 * que não aparecem aqui só usam as anotações do lead.
 */
final class LeadQuoteFields
{
    /**
     * @return array<string, list<array{key: string, label: string, type: string, options?: array<string, string>}>>
     */
    public static function all(): array
    {
        return [
            InsuranceType::Vida->value => [
                ['key' => 'fuma', 'label' => 'Fuma?', 'type' => 'select', 'options' => ['nao' => 'Não', 'sim' => 'Sim']],
                ['key' => 'profissao', 'label' => 'Profissão', 'type' => 'text'],
                ['key' => 'renda', 'label' => 'Renda mensal (R$)', 'type' => 'number'],
            ],
            InsuranceType::Carro->value => [
                ['key' => 'veiculo', 'label' => 'Veículo (modelo, ano, placa)', 'type' => 'text'],
                ['key' => 'cep', 'label' => 'CEP onde fica', 'type' => 'text'],
                ['key' => 'vencimento_atual', 'label' => 'Vencimento do seguro atual', 'type' => 'date'],
            ],
            InsuranceType::Residencia->value => [
                ['key' => 'tipo_imovel', 'label' => 'Tipo de imóvel', 'type' => 'text'],
                ['key' => 'cep', 'label' => 'CEP', 'type' => 'text'],
                ['key' => 'valor_imovel', 'label' => 'Valor do imóvel (R$)', 'type' => 'number'],
            ],
            InsuranceType::Saude->value => [
                ['key' => 'vidas', 'label' => 'Quantas pessoas', 'type' => 'number'],
                ['key' => 'idades', 'label' => 'Idades', 'type' => 'text'],
                ['key' => 'plano_atual', 'label' => 'Plano atual (operadora)', 'type' => 'text'],
            ],
        ];
    }

    /** @return list<array{key: string, label: string, type: string, options?: array<string, string>}> */
    public static function for(string $tipo): array
    {
        return self::all()[$tipo] ?? [];
    }

    /**
     * Só o que é dos tipos escolhidos e é um campo conhecido, com texto cortado: o JSON do lead nunca guarda chave
     * solta que veio da tela.
     *
     * @param  list<string>  $interesses
     * @param  array<string, array<string, mixed>>  $dados
     * @return array<string, array<string, string>>
     */
    public static function clean(array $interesses, array $dados): array
    {
        $limpo = [];

        foreach ($interesses as $tipo) {
            foreach (self::for($tipo) as $campo) {
                $valor = trim((string) ($dados[$tipo][$campo['key']] ?? ''));

                if ($valor === '') {
                    continue;
                }

                if ($campo['type'] === 'select' && ! array_key_exists($valor, $campo['options'] ?? [])) {
                    continue;
                }

                $limpo[$tipo][$campo['key']] = mb_substr($valor, 0, 255);
            }
        }

        return $limpo;
    }
}
