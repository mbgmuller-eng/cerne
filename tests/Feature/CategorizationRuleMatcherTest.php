<?php

namespace Tests\Feature;

use App\Enums\FixedBillPaymentStatus;
use App\Enums\MemberRole;
use App\Enums\Necessity;
use App\Models\ExpenseCategorizationRule;
use App\Models\ExpenseCategory;
use App\Models\FinancialProfile;
use App\Models\FixedBill;
use App\Models\FixedBillPayment;
use App\Models\IncomeCategorizationRule;
use App\Models\IncomeCategory;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\Extraction\CategorizationRuleMatcher;
use App\Services\FixedBillService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O casamento de regra (CategorizationRuleMatcher) é a peça central de
 * "regras de categorização" — é chamado duas vezes com a mesma entrada
 * (revisão e confirmação), então precisa ser determinístico.
 */
class CategorizationRuleMatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_casa_por_substring_sem_diferenciar_maiuscula_minuscula(): void
    {
        [$perfil] = $this->criarPerfil();
        $categoria = ExpenseCategory::factory()->create();
        ExpenseCategorizationRule::factory()->for($perfil, 'profile')->create([
            'pattern' => 'adriana',
            'category_id' => $categoria->id,
        ]);

        $match = app(CategorizationRuleMatcher::class)->matchExpense(
            'PIX ENVIADO ADRIANA RODRIGUES 12345', CarbonImmutable::parse('2026-09-05'),
        );

        self::assertNotNull($match);
        self::assertSame($categoria->id, $match['category_id']);
    }

    public function test_regra_inativa_nao_casa(): void
    {
        [$perfil] = $this->criarPerfil();
        ExpenseCategorizationRule::factory()->inactive()->for($perfil, 'profile')->create(['pattern' => 'ADRIANA']);

        $match = app(CategorizationRuleMatcher::class)->matchExpense('PIX ADRIANA', CarbonImmutable::parse('2026-09-05'));

        self::assertNull($match);
    }

    public function test_padrao_mais_especifico_vence_quando_duas_regras_batem(): void
    {
        [$perfil] = $this->criarPerfil();
        $generica = ExpenseCategory::factory()->create(['name' => 'Genérica']);
        $especifica = ExpenseCategory::factory()->create(['name' => 'Específica']);
        ExpenseCategorizationRule::factory()->for($perfil, 'profile')->create(['pattern' => 'MERCADO', 'category_id' => $generica->id]);
        ExpenseCategorizationRule::factory()->for($perfil, 'profile')->create(['pattern' => 'MERCADO SAO JOAO', 'category_id' => $especifica->id]);

        $match = app(CategorizationRuleMatcher::class)->matchExpense(
            'COMPRA MERCADO SAO JOAO LTDA', CarbonImmutable::parse('2026-09-05'),
        );

        self::assertSame($especifica->id, $match['category_id']);
    }

    public function test_sem_fixed_bill_id_so_categoriza(): void
    {
        [$perfil] = $this->criarPerfil();
        $categoria = ExpenseCategory::factory()->create();
        ExpenseCategorizationRule::factory()->for($perfil, 'profile')->create([
            'pattern' => 'UBER', 'category_id' => $categoria->id, 'necessity' => Necessity::Discretionary,
        ]);

        $match = app(CategorizationRuleMatcher::class)->matchExpense('UBER TRIP 123', CarbonImmutable::parse('2026-09-05'));

        self::assertSame(Necessity::Discretionary, $match['necessity']);
        self::assertNull($match['fixed_bill_payment']);
    }

    public function test_com_fixed_bill_id_e_ocorrencia_pendente_na_janela_retorna_a_ocorrencia(): void
    {
        [$perfil] = $this->criarPerfil();
        $contaFixa = FixedBill::factory()->for($perfil, 'profile')->weekly(6)->create(['name' => 'Diarista']); // sábado
        app(FixedBillService::class)->generateForMonth(2026, 9);
        $pagamento = FixedBillPayment::where('fixed_bill_id', $contaFixa->id)->orderBy('due_date')->first();

        ExpenseCategorizationRule::factory()->for($perfil, 'profile')->create([
            'pattern' => 'ADRIANA', 'category_id' => $contaFixa->category_id, 'fixed_bill_id' => $contaFixa->id,
        ]);

        // PIX cai um dia depois do vencimento (domingo) — dentro da janela de 3 dias.
        $match = app(CategorizationRuleMatcher::class)->matchExpense(
            'PIX ADRIANA', $pagamento->due_date->addDay(),
        );

        self::assertNotNull($match['fixed_bill_payment']);
        self::assertSame($pagamento->id, $match['fixed_bill_payment']->id);
        self::assertTrue($match['fixed_bill_payment']->status->isOutstanding());
    }

    public function test_com_ocorrencia_ja_paga_na_janela_ainda_retorna_mas_nao_esta_pendente(): void
    {
        [$perfil] = $this->criarPerfil();
        $contaFixa = FixedBill::factory()->for($perfil, 'profile')->weekly(6)->create();
        app(FixedBillService::class)->generateForMonth(2026, 9);
        $pagamento = FixedBillPayment::where('fixed_bill_id', $contaFixa->id)->orderBy('due_date')->first();
        $pagamento->update(['status' => FixedBillPaymentStatus::Paid, 'amount_paid' => $contaFixa->amount, 'paid_at' => $pagamento->due_date]);

        ExpenseCategorizationRule::factory()->for($perfil, 'profile')->create([
            'pattern' => 'ADRIANA', 'category_id' => $contaFixa->category_id, 'fixed_bill_id' => $contaFixa->id,
        ]);

        $match = app(CategorizationRuleMatcher::class)->matchExpense('PIX ADRIANA', $pagamento->due_date);

        self::assertNotNull($match['fixed_bill_payment']);
        self::assertFalse($match['fixed_bill_payment']->status->isOutstanding());
    }

    /** @return array{0: \App\Models\FinancialProfile, 1: FixedBill, 2: FixedBillPayment} conta mensal (uma só ocorrência no mês) com regra ligada */
    private function contaMensalComRegra(): array
    {
        [$perfil] = $this->criarPerfil();
        $contaFixa = FixedBill::factory()->for($perfil, 'profile')->create(['due_day' => 12]);
        app(FixedBillService::class)->generateForMonth(2026, 9);
        $pagamento = FixedBillPayment::where('fixed_bill_id', $contaFixa->id)->orderBy('due_date')->first();

        ExpenseCategorizationRule::factory()->for($perfil, 'profile')->create([
            'pattern' => 'ADRIANA', 'category_id' => $contaFixa->category_id, 'fixed_bill_id' => $contaFixa->id,
        ]);

        return [$perfil, $contaFixa, $pagamento];
    }

    public function test_fora_da_janela_larga_nao_casa_a_ocorrencia(): void
    {
        [, , $pagamento] = $this->contaMensalComRegra();
        $casador = app(CategorizationRuleMatcher::class);

        // Pagou 16 dias antes do vencimento (a janela vai até 15) ou 11 dias depois (vai até 10): longe demais.
        self::assertNull($casador->matchExpense('PIX ADRIANA', $pagamento->due_date->subDays(16))['fixed_bill_payment']);
        self::assertNull($casador->matchExpense('PIX ADRIANA', $pagamento->due_date->addDays(11))['fixed_bill_payment']);
    }

    public function test_pagamento_adiantado_ou_com_atraso_casa_a_ocorrencia_em_aberto(): void
    {
        [, , $pagamento] = $this->contaMensalComRegra();
        $casador = app(CategorizationRuleMatcher::class);

        // O caso real: aluguel pago 7 dias antes do vencimento. Antes a janela era de 3 dias e a baixa nem era sugerida.
        foreach ([-15, -7, -1, 0, 5, 10] as $dias) {
            $achada = $casador->matchExpense('PIX ADRIANA', $pagamento->due_date->addDays($dias))['fixed_bill_payment'];
            self::assertNotNull($achada, "pagamento {$dias} dias do vencimento");
            self::assertSame($pagamento->id, $achada->id);
        }
    }

    public function test_prefere_a_ocorrencia_em_aberto_e_a_paga_so_vale_na_janela_curta(): void
    {
        [$perfil, $contaFixa, $setembro] = $this->contaMensalComRegra();
        app(FixedBillService::class)->generateForMonth(2026, 10);
        $outubro = FixedBillPayment::where('fixed_bill_id', $contaFixa->id)->whereMonth('due_date', 10)->first();
        $casador = app(CategorizationRuleMatcher::class);

        // Setembro já pago (vence 12/09) e outubro em aberto (12/10). Lançamento de 03/10: a de setembro está a 21 dias (fora), a de outubro a 9 dias.
        $setembro->update(['status' => FixedBillPaymentStatus::Paid, 'amount_paid' => $contaFixa->amount, 'paid_at' => $setembro->due_date]);
        $achada = $casador->matchExpense('PIX ADRIANA', $outubro->due_date->subDays(9))['fixed_bill_payment'];
        self::assertSame($outubro->id, $achada->id);

        // Lançamento de 20/09 (8 dias depois do vencimento de setembro, já paga; outubro a 22 dias): a paga está na janela larga mas NÃO na curta,
        // então não casa (seria marcar a linha como "já dado baixa" por engano).
        self::assertNull($casador->matchExpense('PIX ADRIANA', $setembro->due_date->addDays(8))['fixed_bill_payment']);

        // Dentro da janela curta, a paga ainda casa (para a revisão desmarcar a linha: já foi dado baixa).
        $paga = $casador->matchExpense('PIX ADRIANA', $setembro->due_date->addDays(2))['fixed_bill_payment'];
        self::assertSame($setembro->id, $paga->id);
        self::assertFalse($paga->status->isOutstanding());
    }

    public function test_matchincome_casa_regra_de_receita(): void
    {
        [$perfil] = $this->criarPerfil();
        $categoria = IncomeCategory::factory()->create();
        IncomeCategorizationRule::factory()->for($perfil, 'profile')->create(['pattern' => 'SALARIO', 'category_id' => $categoria->id]);

        $match = app(CategorizationRuleMatcher::class)->matchIncome('DEPOSITO SALARIO EMPRESA X', CarbonImmutable::parse('2026-09-05'));

        self::assertSame($categoria->id, $match['category_id']);
    }

    /**
     * Motivado por um caso real: PIX mensal que a pessoa manda pra si
     * mesma, sempre no mesmo valor — a descrição sozinha ("PIX MARCELO")
     * casaria com qualquer PIX daquele nome, então o valor vira condição
     * obrigatória, não só desempate.
     */
    public function test_regra_com_valor_so_casa_quando_o_valor_bate_exatamente(): void
    {
        [$perfil] = $this->criarPerfil();
        $categoria = ExpenseCategory::factory()->create();
        ExpenseCategorizationRule::factory()->for($perfil, 'profile')->create([
            'pattern' => 'PIX MARCELO', 'amount' => '199.58', 'category_id' => $categoria->id,
        ]);

        $bate = app(CategorizationRuleMatcher::class)->matchExpense('PIX MARCELO MULLER 24/09', CarbonImmutable::parse('2026-09-24'), '199.58');
        self::assertNotNull($bate);
        self::assertSame($categoria->id, $bate['category_id']);

        $naoBate = app(CategorizationRuleMatcher::class)->matchExpense('PIX MARCELO MULLER 24/09', CarbonImmutable::parse('2026-09-24'), '50.00');
        self::assertNull($naoBate);
    }

    /** Sem valor pra comparar (chamada sem o parâmetro), regra com valor travado nunca casa — não dá pra confirmar a condição. */
    public function test_regra_com_valor_nao_casa_sem_valor_do_item_pra_comparar(): void
    {
        [$perfil] = $this->criarPerfil();
        ExpenseCategorizationRule::factory()->for($perfil, 'profile')->create(['pattern' => 'PIX MARCELO', 'amount' => '199.58']);

        $match = app(CategorizationRuleMatcher::class)->matchExpense('PIX MARCELO MULLER 24/09', CarbonImmutable::parse('2026-09-24'));

        self::assertNull($match);
    }

    /** Quando o valor não bate na regra travada, uma regra sem valor (mais genérica, padrão diferente) ainda pode assumir o casamento. */
    public function test_regra_sem_valor_assume_quando_a_regra_com_valor_nao_bate(): void
    {
        [$perfil] = $this->criarPerfil();
        $generica = ExpenseCategory::factory()->create(['name' => 'Genérica']);
        $comValor = ExpenseCategory::factory()->create(['name' => 'Com valor']);
        ExpenseCategorizationRule::factory()->for($perfil, 'profile')->create(['pattern' => 'PIX', 'category_id' => $generica->id]);
        ExpenseCategorizationRule::factory()->for($perfil, 'profile')->create(['pattern' => 'PIX MARCELO', 'amount' => '199.58', 'category_id' => $comValor->id]);

        $match = app(CategorizationRuleMatcher::class)->matchExpense('PIX MARCELO MULLER 24/09', CarbonImmutable::parse('2026-09-24'), '50.00');

        self::assertSame($generica->id, $match['category_id']);
    }

    /** Regra com valor vence a sem valor mesmo com padrão mais curto — travar o valor é sinal de mais específica, não o tamanho do texto. */
    public function test_regra_com_valor_vence_regra_sem_valor_mesmo_com_padrao_mais_curto(): void
    {
        [$perfil] = $this->criarPerfil();
        $generica = ExpenseCategory::factory()->create(['name' => 'Genérica']);
        $comValor = ExpenseCategory::factory()->create(['name' => 'Com valor']);
        ExpenseCategorizationRule::factory()->for($perfil, 'profile')->create(['pattern' => 'PIX MARCELO MULLER LONGO', 'category_id' => $generica->id]);
        ExpenseCategorizationRule::factory()->for($perfil, 'profile')->create(['pattern' => 'PIX', 'amount' => '199.58', 'category_id' => $comValor->id]);

        $match = app(CategorizationRuleMatcher::class)->matchExpense('PIX MARCELO MULLER LONGO', CarbonImmutable::parse('2026-09-24'), '199.58');

        self::assertSame($comValor->id, $match['category_id']);
    }

    public function test_matchincome_tambem_respeita_valor_exato(): void
    {
        [$perfil] = $this->criarPerfil();
        $categoria = IncomeCategory::factory()->create();
        IncomeCategorizationRule::factory()->for($perfil, 'profile')->create([
            'pattern' => 'REEMBOLSO', 'amount' => '500.00', 'category_id' => $categoria->id,
        ]);

        $bate = app(CategorizationRuleMatcher::class)->matchIncome('REEMBOLSO VIAGEM', CarbonImmutable::parse('2026-09-05'), '500.00');
        self::assertNotNull($bate);

        $naoBate = app(CategorizationRuleMatcher::class)->matchIncome('REEMBOLSO VIAGEM', CarbonImmutable::parse('2026-09-05'), '10.00');
        self::assertNull($naoBate);
    }

    /** @return array{0: FinancialProfile} */
    private function criarPerfil(): array
    {
        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id, 'role' => MemberRole::Primary]);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($perfil, $membro);

        return [$perfil];
    }
}
