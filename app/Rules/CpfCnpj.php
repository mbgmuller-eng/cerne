<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * CPF/CNPJ de verdade, com dígito verificador — a regex antiga
 * (`/^\d{11}$|^\d{14}$/`) aceitava "00000000000" porque só conferia o
 * tamanho. Precisa ser rigoroso aqui porque é o dado que vai pra Asaas
 * criar o cliente de cobrança (AsaasClient::findOrCreateCustomer).
 */
class CpfCnpj implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $digitos = preg_replace('/\D/', '', (string) $value);

        if ($digitos === null || ! in_array(strlen($digitos), [11, 14], true)) {
            $fail('O :attribute precisa ter 11 dígitos (CPF) ou 14 (CNPJ).');

            return;
        }

        // Sequência de um dígito só repetido (00000000000, 11111111111...)
        // passa em qualquer fórmula de dígito verificador por acidente —
        // precisa ser descartada à parte.
        if (preg_match('/^(\d)\1*$/', $digitos) === 1) {
            $fail('O :attribute não é válido.');

            return;
        }

        $valido = strlen($digitos) === 11
            ? $this->cpfValido($digitos)
            : $this->cnpjValido($digitos);

        if (! $valido) {
            $fail('O :attribute não é válido.');
        }
    }

    private function cpfValido(string $cpf): bool
    {
        for ($posicao = 9; $posicao <= 10; $posicao++) {
            $soma = 0;
            for ($indice = 0; $indice < $posicao; $indice++) {
                $soma += (int) $cpf[$indice] * (($posicao + 1) - $indice);
            }
            $resto = $soma % 11;
            $digitoEsperado = $resto < 2 ? 0 : 11 - $resto;

            if ((int) $cpf[$posicao] !== $digitoEsperado) {
                return false;
            }
        }

        return true;
    }

    private function cnpjValido(string $cnpj): bool
    {
        $pesos = [[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]];

        foreach ([12, 13] as $indicePosicao => $posicao) {
            $soma = 0;
            for ($indice = 0; $indice < $posicao; $indice++) {
                $soma += (int) $cnpj[$indice] * $pesos[$indicePosicao][$indice];
            }
            $resto = $soma % 11;
            $digitoEsperado = $resto < 2 ? 0 : 11 - $resto;

            if ((int) $cnpj[$posicao] !== $digitoEsperado) {
                return false;
            }
        }

        return true;
    }
}
