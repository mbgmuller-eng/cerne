<?php

namespace App\Support;

/**
 * Neutraliza "injeção de fórmula" em CSV: uma célula de texto que começa com `=`, `+`, `-`, `@`, tabulação ou retorno de carro é lida
 * pelo Excel como fórmula. Nome de dívida, credor e descrição vêm de digitação (e de leitura de PDF por IA), então um valor como
 * `=HYPERLINK(...)` viraria ataque ao abrir a planilha de quem recebe o relatório. A saída é prefixar com apóstrofo, que o Excel
 * mostra como texto. Número com sinal (`-1.234,50`) não é fórmula e passa como está.
 */
class CsvSafe
{
    /** @param  array<int, mixed>  $linha */
    public static function row(array $linha): array
    {
        return array_map(self::cell(...), $linha);
    }

    public static function cell(mixed $valor): mixed
    {
        if (! is_string($valor) || $valor === '') {
            return $valor;
        }

        if (preg_match('/^[-+]?[\d.,]+$/', $valor) === 1) {
            return $valor;
        }

        return preg_match('/^[=+\-@\t\r]/', $valor) === 1 ? "'".$valor : $valor;
    }
}
