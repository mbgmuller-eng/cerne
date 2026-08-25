<?php

namespace Tests\Feature;

use App\Enums\FixedBillPaymentStatus;
use App\Enums\RecurrenceType;
use App\Models\BankAccount;
use App\Models\FinancialProfile;
use App\Models\FixedBill;
use App\Models\FixedBillPayment;
use App\Models\ProfileMember;
use App\Services\FixedBillService;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Recorrência de conta fixa: semanal, mensal e anual, todas passando pelo
 * mesmo motor de geração — idempotência é a parte que mais importa aqui
 * (CLAUDE.md regra 4: índice único, não if (existe)), porque a rotina
 * roda tanto no cron quanto sempre que a tela é aberta.
 */
class FixedBillServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_conta_mensal_gera_um_vencimento_no_mes(): void
    {
        $perfil = FinancialProfile::factory()->create();
        $conta = FixedBill::factory()->for($perfil, 'profile')->create(['due_day' => 5]);

        $criados = app(FixedBillService::class)->generateForMonth(2026, 8);

        self::assertSame(1, $criados);
        $pagamento = FixedBillPayment::withoutProfileScope()->where('fixed_bill_id', $conta->id)->sole();
        self::assertSame('2026-08-05', $pagamento->due_date->toDateString());
    }

    public function test_conta_semanal_gera_uma_ocorrencia_por_dia_da_semana_no_mes(): void
    {
        $perfil = FinancialProfile::factory()->create();
        // Sexta-feira (5) em agosto de 2026: 07, 14, 21, 28.
        FixedBill::factory()->for($perfil, 'profile')->weekly(5)->create();

        app(FixedBillService::class)->generateForMonth(2026, 8);

        $datas = FixedBillPayment::withoutProfileScope()
            ->orderBy('due_date')
            ->pluck('due_date')
            ->map(fn ($d) => $d->toDateString())
            ->all();

        self::assertSame(['2026-08-07', '2026-08-14', '2026-08-21', '2026-08-28'], $datas);
    }

    public function test_conta_anual_so_gera_vencimento_no_mes_configurado(): void
    {
        $perfil = FinancialProfile::factory()->create();
        FixedBill::factory()->for($perfil, 'profile')->annual(month: 3, day: 15)->create();

        $emMarco = app(FixedBillService::class)->generateForMonth(2026, 3);
        $emAgosto = app(FixedBillService::class)->generateForMonth(2026, 8);

        self::assertSame(1, $emMarco);
        self::assertSame(0, $emAgosto);
        self::assertSame(1, FixedBillPayment::withoutProfileScope()->count());
    }

    public function test_gerar_o_mesmo_mes_duas_vezes_nao_duplica_nada(): void
    {
        $perfil = FinancialProfile::factory()->create();
        FixedBill::factory()->for($perfil, 'profile')->weekly(1)->create(); // segundas

        $service = app(FixedBillService::class);
        $primeira = $service->generateForMonth(2026, 8);
        $segunda = $service->generateForMonth(2026, 8);

        self::assertGreaterThan(0, $primeira);
        self::assertSame(0, $segunda);
        self::assertSame($primeira, FixedBillPayment::withoutProfileScope()->count());
    }

    public function test_conta_inativa_nao_gera_vencimento(): void
    {
        $perfil = FinancialProfile::factory()->create();
        FixedBill::factory()->for($perfil, 'profile')->inactive()->create(['due_day' => 5]);

        $criados = app(FixedBillService::class)->generateForMonth(2026, 8);

        self::assertSame(0, $criados);
    }

    public function test_create_cadastra_e_ja_gera_o_vencimento_do_mes_corrente(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::create(2026, 8, 10));

        $perfil = FinancialProfile::factory()->create();
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id]);
        app(ProfileContext::class)->set($perfil, $membro);

        $conta = app(FixedBillService::class)->create([
            'name' => 'Aluguel',
            'amount' => '2000.00',
            'recurrence' => RecurrenceType::Monthly,
            'due_day' => 5,
        ]);

        self::assertSame($perfil->id, $conta->profile_id);
        $pagamento = FixedBillPayment::withoutProfileScope()->where('fixed_bill_id', $conta->id)->sole();
        self::assertSame('2026-08-05', $pagamento->due_date->toDateString());
        self::assertSame(FixedBillPaymentStatus::Pending, $pagamento->status);
    }

    public function test_pagar_debita_a_conta_bancaria_vinculada_no_valor_exato(): void
    {
        $perfil = FinancialProfile::factory()->create();
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id]);
        // pay() lê $payment->fixedBill, e FixedBill é BelongsToProfile: sem
        // contexto ativo o escopo falha fechado e a relação volta null —
        // numa tela de verdade o middleware já deixa isso setado.
        app(ProfileContext::class)->set($perfil, $membro);
        $bankAccount = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['current_balance' => '1000.00']);
        $conta = FixedBill::factory()->for($perfil, 'profile')
            ->create(['due_day' => 5, 'amount' => '149.90', 'bank_account_id' => $bankAccount->id]);

        $service = app(FixedBillService::class);
        $service->generateForMonth(2026, 8);
        $pagamento = FixedBillPayment::withoutProfileScope()->where('fixed_bill_id', $conta->id)->sole();

        $service->pay($pagamento, null, null, null);

        $bankAccount->refresh();
        self::assertSame('850.10', $bankAccount->current_balance);
    }

    public function test_geracao_de_varios_perfis_nao_vaza_vencimento_entre_eles(): void
    {
        $perfilA = FinancialProfile::factory()->create();
        $contaA = FixedBill::factory()->for($perfilA, 'profile')->create(['due_day' => 5]);

        $perfilB = FinancialProfile::factory()->create();
        FixedBill::factory()->for($perfilB, 'profile')->create(['due_day' => 5]);

        app(FixedBillService::class)->generateForMonth(2026, 8);

        $pagamentosDeA = FixedBillPayment::withoutProfileScope()->where('profile_id', $perfilA->id)->get();
        self::assertCount(1, $pagamentosDeA);
        self::assertSame($contaA->id, $pagamentosDeA->first()->fixed_bill_id);
    }
}
