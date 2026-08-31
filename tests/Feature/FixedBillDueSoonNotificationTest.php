<?php

namespace Tests\Feature;

use App\Enums\FixedBillPaymentStatus;
use App\Enums\MemberRole;
use App\Models\FinancialProfile;
use App\Models\FixedBill;
use App\Models\FixedBillPayment;
use App\Models\ProfileMember;
use App\Models\User;
use App\Notifications\FixedBillDueSoon;
use App\Services\FixedBillService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * FixedBill/FixedBillPayment não têm ProfileContext ativo aqui (é rotina de
 * cron) — o teste mais importante é a regressão de privacidade: uma conta
 * privada não pode notificar quem ela está escondendo, mesmo sem o escopo
 * global de privacidade ativo pra fazer essa filtragem sozinho.
 */
class FixedBillDueSoonNotificationTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $hoje;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = CarbonImmutable::parse('2026-09-01');
    }

    public function test_notifica_exatamente_no_dia_configurado_de_antecedencia(): void
    {
        Notification::fake();
        [$perfil, $titular] = $this->criarPerfil();

        $bill = FixedBill::factory()->create(['profile_id' => $perfil->id, 'member_id' => null, 'is_private' => false]);
        $this->criarPagamento($bill, $this->hoje->addDays(2));
        $alvo = $this->criarPagamento($bill, $this->hoje->addDays(3));
        $this->criarPagamento($bill, $this->hoje->addDays(4));

        app(FixedBillService::class)->notifyUpcomingDueDates($this->hoje);

        Notification::assertSentToTimes($titular, FixedBillDueSoon::class, 1);
        Notification::assertSentTo($titular, FixedBillDueSoon::class, fn ($n) => $n->paymentId === $alvo->id);
    }

    public function test_conta_privada_notifica_so_o_dono_nao_o_conjuge(): void
    {
        Notification::fake();
        [$perfil, $titular, $conjuge, $membroConjuge] = $this->criarCasal();

        $bill = FixedBill::factory()->create([
            'profile_id' => $perfil->id,
            'member_id' => $membroConjuge->id,
            'is_private' => true,
        ]);
        $this->criarPagamento($bill, $this->hoje->addDays(3));

        app(FixedBillService::class)->notifyUpcomingDueDates($this->hoje);

        Notification::assertSentTo($conjuge, FixedBillDueSoon::class);
        Notification::assertNotSentTo($titular, FixedBillDueSoon::class);
    }

    public function test_conta_compartilhada_notifica_os_dois_membros(): void
    {
        Notification::fake();
        [$perfil, $titular, $conjuge] = $this->criarCasal();

        $bill = FixedBill::factory()->create(['profile_id' => $perfil->id, 'member_id' => null, 'is_private' => false]);
        $this->criarPagamento($bill, $this->hoje->addDays(3));

        app(FixedBillService::class)->notifyUpcomingDueDates($this->hoje);

        Notification::assertSentTo($titular, FixedBillDueSoon::class);
        Notification::assertSentTo($conjuge, FixedBillDueSoon::class);
    }

    public function test_reexecutar_no_mesmo_dia_nao_duplica_o_aviso(): void
    {
        // Sem Notification::fake() de propósito: a proteção contra duplicata
        // consulta a tabela `notifications` de verdade (ver
        // FixedBillService::alreadyNotifiedToday) — fake() nunca grava lá.
        [$perfil, $titular] = $this->criarPerfil();

        $bill = FixedBill::factory()->create(['profile_id' => $perfil->id, 'member_id' => null, 'is_private' => false]);
        $this->criarPagamento($bill, $this->hoje->addDays(3));

        app(FixedBillService::class)->notifyUpcomingDueDates($this->hoje);
        app(FixedBillService::class)->notifyUpcomingDueDates($this->hoje);

        self::assertSame(1, $titular->notifications()->where('type', FixedBillDueSoon::class)->count());
    }

    private function criarPagamento(FixedBill $bill, CarbonImmutable $vencimento): FixedBillPayment
    {
        return FixedBillPayment::withoutProfileScope()->create([
            'profile_id' => $bill->profile_id,
            'fixed_bill_id' => $bill->id,
            'year' => $vencimento->year,
            'month' => $vencimento->month,
            'due_date' => $vencimento,
            'status' => FixedBillPaymentStatus::Pending,
        ]);
    }

    /** @return array{0: FinancialProfile, 1: User} */
    private function criarPerfil(): array
    {
        $titular = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $titular->id]);
        ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $titular->id, 'role' => MemberRole::Primary]);

        return [$perfil, $titular];
    }

    /** @return array{0: FinancialProfile, 1: User, 2: User, 3: ProfileMember} */
    private function criarCasal(): array
    {
        [$perfil, $titular] = $this->criarPerfil();

        $conjuge = User::factory()->create();
        $membroConjuge = ProfileMember::factory()->secondary()->create(['profile_id' => $perfil->id, 'user_id' => $conjuge->id]);

        return [$perfil, $titular, $conjuge, $membroConjuge];
    }
}
