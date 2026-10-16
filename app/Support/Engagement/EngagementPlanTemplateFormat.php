<?php

namespace App\Support\Engagement;

use InvalidArgumentException;

/**
 * O modelo de passo a passo é escrito como texto simples, fácil de editar:
 *
 *     # Reserva de emergência
 *     - Meta da reserva definida com o cliente
 *     - Reserva de paz completa @reserve_peace
 *
 * "# " abre uma etapa, "- " é um item, e "@chave" no fim do item o liga a um critério automático (ver EngagementPlanCriteria).
 * Linha em branco é ignorada. Qualquer outra coisa é erro, para ninguém perder um item por um traço faltando.
 */
final class EngagementPlanTemplateFormat
{
    public const MAX_STAGES = 12;

    public const MAX_ITEMS_PER_STAGE = 20;

    /** O roteiro padrão que acompanha o Cerne. O consultor pode aplicá-lo como está ou copiá-lo para o seu modelo e ajustar. */
    public static function defaultBody(): string
    {
        return <<<'TXT'
# Diagnóstico
- Contas bancárias cadastradas @accounts
- Receitas lançadas @income_recent
- Despesas lançadas @expenses_recent
- Conversa de diagnóstico com o consultor

# Orçamento organizado
- Contas fixas cadastradas @fixed_bills
- Orçamento mensal definido com o consultor

# Reserva de emergência
- Meta da reserva definida com o consultor
- Reserva de paz completa @reserve_peace

# Proteção (seguros)
- Apólices cadastradas @policies
- Seguro de vida cadastrado @life_insurance
- Coberturas revisadas com o consultor

# Investimentos
- Perfil de investidor preenchido @investor_profile
- Investimentos cadastrados @investments
- Carteira alinhada ao perfil

# Objetivos de longo prazo
- Objetivos cadastrados @goals
- Plano de aportes para os objetivos definido
TXT;
    }

    /**
     * @return list<array{title: string, items: list<array{title: string, auto: ?string}>}>
     *
     * @throws InvalidArgumentException com a mensagem já pronta para mostrar ao consultor
     */
    public static function parse(string $texto): array
    {
        $etapas = [];

        foreach (preg_split('/\R/u', $texto) ?: [] as $n => $linha) {
            $linha = trim($linha);
            $numero = $n + 1;

            if ($linha === '') {
                continue;
            }

            if (str_starts_with($linha, '#')) {
                $titulo = trim(ltrim($linha, '#'));

                if ($titulo === '') {
                    throw new InvalidArgumentException("Linha $numero: a etapa precisa de um nome.");
                }

                if (mb_strlen($titulo) > 120) {
                    throw new InvalidArgumentException("Linha $numero: o nome da etapa passa de 120 caracteres.");
                }

                $etapas[] = ['title' => $titulo, 'items' => []];

                continue;
            }

            if (! str_starts_with($linha, '-')) {
                throw new InvalidArgumentException("Linha $numero: use \"# \" para etapa e \"- \" para item.");
            }

            if ($etapas === []) {
                throw new InvalidArgumentException("Linha $numero: o item precisa vir depois de uma etapa.");
            }

            $titulo = trim(ltrim($linha, '-'));
            $auto = null;

            if (preg_match('/\s@([a-z_]+)\s*$/u', ' '.$titulo, $m) === 1) {
                $auto = $m[1];
                $titulo = trim(preg_replace('/\s@[a-z_]+\s*$/u', '', ' '.$titulo));

                if (! EngagementPlanCriteria::exists($auto)) {
                    throw new InvalidArgumentException("Linha $numero: o critério automático \"@$auto\" não existe.");
                }
            }

            if ($titulo === '') {
                throw new InvalidArgumentException("Linha $numero: o item precisa de um texto.");
            }

            if (mb_strlen($titulo) > 160) {
                throw new InvalidArgumentException("Linha $numero: o item passa de 160 caracteres.");
            }

            $etapas[array_key_last($etapas)]['items'][] = ['title' => $titulo, 'auto' => $auto];

            if (count($etapas[array_key_last($etapas)]['items']) > self::MAX_ITEMS_PER_STAGE) {
                throw new InvalidArgumentException('Cada etapa aceita até '.self::MAX_ITEMS_PER_STAGE.' itens.');
            }
        }

        if ($etapas === []) {
            throw new InvalidArgumentException('Escreva ao menos uma etapa.');
        }

        if (count($etapas) > self::MAX_STAGES) {
            throw new InvalidArgumentException('O roteiro aceita até '.self::MAX_STAGES.' etapas.');
        }

        return $etapas;
    }

    /** @param  list<array{title: string, items: list<array{title: string, auto: ?string}>}>  $etapas */
    public static function serialize(array $etapas): string
    {
        $blocos = array_map(function (array $etapa): string {
            $linhas = ['# '.$etapa['title']];

            foreach ($etapa['items'] as $item) {
                $linhas[] = '- '.$item['title'].($item['auto'] !== null ? ' @'.$item['auto'] : '');
            }

            return implode("\n", $linhas);
        }, $etapas);

        return implode("\n\n", $blocos);
    }
}
