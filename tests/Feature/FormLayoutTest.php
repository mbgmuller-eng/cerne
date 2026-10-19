<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Campos lado a lado precisam ficar alinhados. Um rótulo comprido numa coluna de meia largura quebra em duas linhas e empurra o
 * campo para baixo, deixando o do vizinho mais alto. A regra: o rótulo cabe em uma linha (até 38 caracteres) e a explicação vai
 * numa dica abaixo do campo (`<p class="mt-1 text-xs text-slate-400">`), que não mexe no alinhamento.
 *
 * Só olha rótulo de campo (`<label>` em bloco) cujo pai direto é uma célula de grade de duas colunas e que não ocupa a linha
 * inteira (`col-span-2`).
 */
class FormLayoutTest extends TestCase
{
    private const LIMITE = 38;

    public function test_rotulo_de_campo_em_coluna_de_meia_largura_cabe_em_uma_linha(): void
    {
        $erros = [];
        $raiz = dirname(__DIR__, 2).'/resources/views';

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz, RecursiveDirectoryIterator::SKIP_DOTS)) as $arquivo) {
            $caminho = str_replace('\\', '/', $arquivo->getPathname());

            if (! str_ends_with($caminho, '.blade.php')) {
                continue;
            }

            $codigo = file_get_contents($caminho);

            if (! str_contains($codigo, 'grid-cols-2')) {
                continue;
            }

            foreach ($this->rotulosEmMeiaColuna($codigo) as [$linha, $texto]) {
                $erros[] = str_replace(str_replace('\\', '/', $raiz).'/', 'resources/views/', $caminho).":$linha ({$this->tamanho($texto)} caracteres) $texto";
            }
        }

        self::assertSame([], $erros, "Rótulo longo em coluna de meia largura desalinha os campos. Encurte o rótulo (até ".self::LIMITE." caracteres) e ponha a explicação numa dica abaixo do campo:\n".implode("\n", $erros));
    }

    public function test_o_detector_pega_o_rotulo_que_quebrava_e_deixa_passar_o_curto_e_o_de_linha_inteira(): void
    {
        $quebra = '<div class="grid gap-4 @sm:grid-cols-2"><div><label class="block text-xs">CPF ou CNPJ do credor <span>(para o imposto de renda; opcional)</span></label><input></div></div>';
        $curto = '<div class="grid gap-4 @sm:grid-cols-2"><div><label class="block text-xs">CPF/CNPJ do credor <span>(opcional)</span></label><input></div></div>';
        $linhaInteira = '<div class="grid gap-4 @sm:grid-cols-2"><div class="@sm:col-span-2"><label class="block text-xs">CPF ou CNPJ do credor <span>(para o imposto de renda; opcional)</span></label><input></div></div>';
        $comBloco = '<div class="grid @sm:grid-cols-2"><div class="contents"><div><label class="block text-xs">CPF ou CNPJ do credor (para o imposto de renda; opcional)</label></div></div></div>';

        self::assertCount(1, $this->rotulosEmMeiaColuna($quebra));
        self::assertCount(0, $this->rotulosEmMeiaColuna($curto));
        self::assertCount(0, $this->rotulosEmMeiaColuna($linhaInteira));
        self::assertCount(1, $this->rotulosEmMeiaColuna($comBloco), 'o bloco contents entre a grade e a célula não esconde o problema');
    }

    /** @return list<array{0: int, 1: string}> linha e texto de cada rótulo comprido numa célula de meia largura */
    private function rotulosEmMeiaColuna(string $codigo): array
    {
        preg_match_all('/<div\b([^>]*)>|<\/div>|<label\b([^>]*)>(.*?)<\/label>/s', $codigo, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $pilha = [];
        $achados = [];

        foreach ($m as $token) {
            $texto = $token[0][0];

            if (str_starts_with($texto, '</div')) {
                array_pop($pilha);

                continue;
            }

            if (str_starts_with($texto, '<div')) {
                $pilha[] = $token[1][0] ?? '';

                continue;
            }

            // <label ...>: rótulo de caixa de seleção ou de seletor em linha (flex) não é rótulo de campo
            $atributos = $token[2][0] ?? '';

            if (! str_contains($atributos, 'block') || str_contains($atributos, 'flex') || str_contains($atributos, '@class')) {
                continue;
            }

            $estatico = $this->estatico($token[3][0] ?? '');

            if ($this->tamanho($estatico) <= self::LIMITE) {
                continue;
            }

            $pai = $pilha[count($pilha) - 1] ?? '';
            $avo = $pilha[count($pilha) - 2] ?? '';

            // Bloco `contents` entre a grade e a célula (formulário por tipo): a grade é o ancestral seguinte.
            if (str_contains($avo, 'contents') && count($pilha) >= 3) {
                $avo = $pilha[count($pilha) - 3];
            }

            if (str_contains($avo, 'grid-cols-2') && ! str_contains($pai, 'col-span')) {
                $achados[] = [substr_count(substr($codigo, 0, $token[0][1]), "\n") + 1, $estatico];
            }
        }

        return $achados;
    }

    /**
     * O texto que o usuário lê, sem marcação; expressões do Blade contam como 6 caracteres (um valor curto). Com ramos
     * (@switch, @if), vale o mais comprido: só um deles aparece por vez.
     */
    private function estatico(string $html): string
    {
        $semBlade = preg_replace('/\{\{.*?\}\}/s', 'XXXXXX', $html);
        $ramos = preg_split('/@(?:switch|case|if|elseif)\s*\(.*\)|@(?:break|default|else|endif|endswitch)\b/', $semBlade);
        $textos = array_map(fn (string $ramo) => trim(preg_replace('/\s+/', ' ', strip_tags($ramo))), $ramos);

        usort($textos, fn (string $x, string $y) => mb_strlen($y) <=> mb_strlen($x));

        return $textos[0] ?? '';
    }

    private function tamanho(string $texto): int
    {
        return mb_strlen($texto);
    }
}
