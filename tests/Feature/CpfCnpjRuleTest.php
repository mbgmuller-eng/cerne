<?php

namespace Tests\Feature;

use App\Rules\CpfCnpj;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CpfCnpjRuleTest extends TestCase
{
    /** @return array<string, array{0: string, 1: bool}> */
    public static function casos(): array
    {
        return [
            'cpf válido' => ['529.982.247-25', true],
            'cnpj válido' => ['11.222.333/0001-81', true],
            'cpf com dígito verificador errado' => ['529.982.247-00', false],
            'sequência repetida' => ['000.000.000-00', false],
            'tamanho errado' => ['123', false],
            'vazio' => ['', false],
        ];
    }

    #[DataProvider('casos')]
    public function test_valida_cpf_cnpj(string $valor, bool $esperado): void
    {
        // 'required' junto, igual é usado de verdade em SubscriptionIndex e
        // SelfRegistrationController — sozinha, a rule não dispara em valor
        // vazio (comportamento padrão do Validator pra regras não-implícitas).
        $validator = Validator::make(['documento' => $valor], ['documento' => ['required', new CpfCnpj]]);

        self::assertSame($esperado, $validator->passes());
    }
}
