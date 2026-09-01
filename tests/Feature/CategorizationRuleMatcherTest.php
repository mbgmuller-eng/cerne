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

    public function test_fora_da_janela_de_3_dias_nao_casa_a_ocorrencia(): void
    {
        [$perfil] = $this->criarPerfil();
        // Mensal, não semanal: só uma ocorrência no mês — sem risco de
        // "fora da janela de uma" na verdade bater com a semana seguinte.
        $contaFixa = FixedBill::factory()->for($perfil, 'profile')->create(['due_day' => 12]);
        app(FixedBillService::class)->generateForMonth(2026, 9);
        $pagamento = FixedBillPayment::where('fixed_bill_id', $contaFixa->id)->orderBy('due_date')->first();

        ExpenseCategorizationRule::factory()->for($perfil, 'profile')->create([
            'pattern' => 'ADRIANA', 'category_id' => $contaFixa->category_id, 'fixed_bill_id' => $contaFixa->id,
        ]);

        $match = app(CategorizationRuleMatcher::class)->matchExpense('PIX ADRIANA', $pagamento->due_date->addDays(5));

        self::assertNotNull($match);
        self::assertNull($match['fixed_bill_payment']);
    }

    public function test_matchincome_casa_regra_de_receita(): void
    {
        [$perfil] = $this->criarPerfil();
        $categoria = IncomeCategory::factory()->create();
        IncomeCategorizationRule::factory()->for($perfil, 'profile')->create(['pattern' => 'SALARIO', 'category_id' => $categoria->id]);

        $match = app(CategorizationRuleMatcher::class)->matchIncome('DEPOSITO SALARIO EMPRESA X', CarbonImmutable::parse('2026-09-05'));

        self::assertSame($categoria->id, $match['category_id']);
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
