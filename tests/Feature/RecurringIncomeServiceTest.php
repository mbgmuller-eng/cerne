<?php

namespace Tests\Feature;

use App\Enums\RecurrenceType;
use App\Enums\RecurringIncomeStatus;
use App\Models\BankAccount;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\RecurringIncome;
use App\Models\RecurringIncomeOccurrence;
use App\Services\RecurringIncomeService;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Espelho de FixedBillServiceTest do lado da receita — mesmo motor, mesma
 * garantia de idempotência (CLAUDE.md regra 4).
 */
class RecurringIncomeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_receita_semanal_gera_uma_ocorrencia_por_dia_da_semana_no_mes(): void
    {
        $perfil = FinancialProfile::factory()->create();
        // Sexta-feira (5) em agosto de 2026: 07, 14, 21, 28.
        RecurringIncome::factory()->for($perfil, 'profile')->weekly(5)->create();

        app(RecurringIncomeService::class)->generateForMonth(2026, 8);

        $datas = RecurringIncomeOccurrence::withoutProfileScope()
            ->orderBy('due_date')
            ->pluck('due_date')
            ->map(fn ($d) => $d->toDateString())
            ->all();

        self::assertSame(['2026-08-07', '2026-08-14', '2026-08-21', '2026-08-28'], $datas);
    }

    public function test_receita_anual_so_gera_ocorrencia_no_mes_configurado(): void
    {
        $perfil = FinancialProfile::factory()->create();
        RecurringIncome::factory()->for($perfil, 'profile')->annual(month: 12, day: 20)->create();

        $emDezembro = app(RecurringIncomeService::class)->generateForMonth(2026, 12);
        $emAgosto = app(RecurringIncomeService::class)->generateForMonth(2026, 8);

        self::assertSame(1, $emDezembro);
        self::assertSame(0, $emAgosto);
    }

    public function test_gerar_o_mesmo_mes_duas_vezes_nao_duplica_nada(): void
    {
        $perfil = FinancialProfile::factory()->create();
        RecurringIncome::factory()->for($perfil, 'profile')->create(['due_day' => 5]);

        $service = app(RecurringIncomeService::class);
        $primeira = $service->generateForMonth(2026, 8);
        $segunda = $service->generateForMonth(2026, 8);

        self::assertSame(1, $primeira);
        self::assertSame(0, $segunda);
        self::assertSame(1, RecurringIncomeOccurrence::withoutProfileScope()->count());
    }

    public function test_create_cadastra_e_ja_gera_a_ocorrencia_do_mes_corrente(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::create(2026, 8, 10));

        $perfil = FinancialProfile::factory()->create();
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id]);
        app(ProfileContext::class)->set($perfil, $membro);

        $receita = app(RecurringIncomeService::class)->create([
            'name' => 'Salário',
            'amount' => '5000.00',
            'recurrence' => RecurrenceType::Monthly,
            'due_day' => 5,
        ]);

        self::assertSame($perfil->id, $receita->profile_id);
        $ocorrencia = RecurringIncomeOccurrence::withoutProfileScope()->where('recurring_income_id', $receita->id)->sole();
        self::assertSame('2026-08-05', $ocorrencia->due_date->toDateString());
        self::assertSame(RecurringIncomeStatus::Pending, $ocorrencia->status);
    }

    public function test_receber_credita_a_conta_bancaria_vinculada_no_valor_exato(): void
    {
        $perfil = FinancialProfile::factory()->create();
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id]);
        // receive() lê $occurrence->recurringIncome, que é BelongsToProfile:
        // sem contexto ativo o escopo falha fechado (mesma nota de
        // FixedBillServiceTest::test_pagar_debita...).
        app(ProfileContext::class)->set($perfil, $membro);
        $bankAccount = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['current_balance' => '1000.00']);
        $receita = RecurringIncome::factory()->for($perfil, 'profile')
            ->create(['due_day' => 5, 'amount' => '5000.00', 'bank_account_id' => $bankAccount->id]);

        $service = app(RecurringIncomeService::class);
        $service->generateForMonth(2026, 8);
        $ocorrencia = RecurringIncomeOccurrence::withoutProfileScope()->where('recurring_income_id', $receita->id)->sole();

        $service->receive($ocorrencia, null, null, null);

        $bankAccount->refresh();
        self::assertSame('6000.00', $bankAccount->current_balance);
    }

    public function test_receita_de_valor_variavel_exige_valor_informado(): void
    {
        $perfil = FinancialProfile::factory()->create();
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id]);
        app(ProfileContext::class)->set($perfil, $membro);
        $receita = RecurringIncome::factory()->for($perfil, 'profile')
            ->create(['due_day' => 5, 'is_variable' => true]);

        $service = app(RecurringIncomeService::class);
        $service->generateForMonth(2026, 8);
        $ocorrencia = RecurringIncomeOccurrence::withoutProfileScope()->where('recurring_income_id', $receita->id)->sole();

        $this->expectException(\InvalidArgumentException::class);
        $service->receive($ocorrencia, null, null, null);
    }

    public function test_geracao_de_varios_perfis_nao_vaza_ocorrencia_entre_eles(): void
    {
        $perfilA = FinancialProfile::factory()->create();
        $receitaA = RecurringIncome::factory()->for($perfilA, 'profile')->create(['due_day' => 5]);

        $perfilB = FinancialProfile::factory()->create();
        RecurringIncome::factory()->for($perfilB, 'profile')->create(['due_day' => 5]);

        app(RecurringIncomeService::class)->generateForMonth(2026, 8);

        $ocorrenciasDeA = RecurringIncomeOccurrence::withoutProfileScope()->where('profile_id', $perfilA->id)->get();
        self::assertCount(1, $ocorrenciasDeA);
        self::assertSame($receitaA->id, $ocorrenciasDeA->first()->recurring_income_id);
    }
}
