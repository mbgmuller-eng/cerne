<?php

namespace Tests\Feature;

use App\Models\CreditCard;
use App\Support\Forms\FormConsequence;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Os avisos "o que vai acontecer ao salvar" usam a mesma conta de fatura e de parcela que o Cerne usa para gravar: aqui conferimos o texto, e
 * (ver CashFlowConsequenceTest) que ele bate com o que de fato é gravado.
 */
class FormConsequenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setLocale('pt_BR');
    }

    private function cartao(int $fechamento, int $vencimento): CreditCard
    {
        return (new CreditCard)->forceFill(['closing_day' => $fechamento, 'due_day' => $vencimento]);
    }

    public function test_compra_antes_do_fechamento_cai_na_fatura_do_mes(): void
    {
        $linhas = FormConsequence::cardPurchase($this->cartao(20, 28), CarbonImmutable::parse('2026-12-10'), '100.00', 1);

        $this->assertSame(['Entra na fatura de dezembro de 2026, com vencimento em 28/12/2026. Nenhuma conta é debitada agora.'], $linhas);
    }

    public function test_compra_depois_do_fechamento_cai_na_fatura_do_mes_seguinte(): void
    {
        $linhas = FormConsequence::cardPurchase($this->cartao(20, 28), CarbonImmutable::parse('2026-12-25'), '100.00', 1);

        $this->assertStringContainsString('fatura de janeiro de 2027', $linhas[0]);
        $this->assertStringContainsString('vencimento em 28/01/2027', $linhas[0]);
    }

    public function test_vencimento_menor_que_o_fechamento_vence_no_mes_seguinte(): void
    {
        // Fecha dia 28 e vence dia 5: a fatura de dezembro vence em 5 de janeiro.
        $linhas = FormConsequence::cardPurchase($this->cartao(28, 5), CarbonImmutable::parse('2026-12-10'), '100.00', 1);

        $this->assertStringContainsString('fatura de dezembro de 2026', $linhas[0]);
        $this->assertStringContainsString('vencimento em 05/01/2027', $linhas[0]);
    }

    public function test_compra_parcelada_diz_o_valor_da_parcela_e_o_intervalo_de_faturas(): void
    {
        $linhas = FormConsequence::cardPurchase($this->cartao(20, 28), CarbonImmutable::parse('2026-12-10'), '1000.00', 4);

        $this->assertSame('4 parcelas de R$ 250,00, da fatura de dezembro de 2026 até a de março de 2027. Nenhuma conta é debitada agora.', $linhas[0]);
        $this->assertCount(1, $linhas, 'a divisão é exata: não há ajuste de centavos');
    }

    public function test_a_sobra_de_centavos_vai_para_a_ultima_parcela_e_o_aviso_diz_isso(): void
    {
        // R$ 1.000 em 3x não dá 333,33 três vezes: a última leva o centavo que sobra (mesma conta de Money::split).
        $linhas = FormConsequence::cardPurchase($this->cartao(20, 28), CarbonImmutable::parse('2026-12-10'), '1000.00', 3);

        $this->assertStringContainsString('3 parcelas de R$ 333,33', $linhas[0]);
        $this->assertSame('A última parcela ajusta os centavos: R$ 333,34.', $linhas[1]);
    }

    public function test_estorno_no_cartao_abate_a_fatura(): void
    {
        $linhas = FormConsequence::cardPurchase($this->cartao(20, 28), CarbonImmutable::parse('2026-12-10'), '50.00', 1, estorno: true);

        $this->assertSame(['Estorno: o valor abate a fatura de dezembro de 2026.'], $linhas);
    }

    public function test_saldo_da_conta_antes_e_depois(): void
    {
        $this->assertSame(
            'O saldo de Itaú · Conta corrente passa de R$ 1.000,00 para R$ 850,10 ao salvar, mesmo com data passada.',
            FormConsequence::accountBalance('Itaú · Conta corrente', '1000.00', '149.90', debito: true),
        );
        $this->assertSame(
            'O saldo de Itaú · Conta corrente passa de R$ 1.000,00 para R$ 1.149,90 ao salvar, mesmo com data passada.',
            FormConsequence::accountBalance('Itaú · Conta corrente', '1000.00', '149.90', debito: false),
        );
    }

    public function test_aviso_de_outro_mes_so_aparece_quando_o_mes_difere_do_exibido(): void
    {
        $this->assertNull(FormConsequence::otherMonth(CarbonImmutable::parse('2026-11-15'), 2026, 11));
        $this->assertSame(
            'Este lançamento aparecerá em dezembro de 2026, e não em novembro de 2026.',
            FormConsequence::otherMonth(CarbonImmutable::parse('2026-12-02'), 2026, 11),
        );
    }
}
