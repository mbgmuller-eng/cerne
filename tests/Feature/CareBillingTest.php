<?php

namespace Tests\Feature;

use App\Enums\CaregiverAccess;
use App\Enums\CaregiverStatus;
use App\Enums\MemberRole;
use App\Enums\PaymentMethod;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Models\FinancialProfile;
use App\Models\ProfileCaregiver;
use App\Models\ProfileMember;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AsaasClient;
use App\Services\CareBillingService;
use App\Services\CareProfileService;
use App\Services\HealthAppointmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Cobrança por pessoa acompanhada (R$ 4,90 por mês cada, somada à assinatura direta do dono; quem cuida junto não paga).
 */
class CareBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cerne.care.enabled' => true, 'billing.care_seat_price' => '4.90']);
    }

    public function test_cada_pessoa_soma_ao_valor_mensal_e_o_cuidador_nao_paga(): void
    {
        [$dono, $assinatura] = $this->donoComAssinatura();
        $this->assertSame('29.90', $assinatura->fresh()->monthlyPrice());

        $this->mock(AsaasClient::class);
        $a = app(CareProfileService::class)->create($dono, 'Maria', null, CareProfileService::BASIS_AUTHORIZED);
        $this->assertSame('34.80', $assinatura->fresh()->monthlyPrice());

        app(CareProfileService::class)->create($dono, 'João', null, CareProfileService::BASIS_AUTHORIZED);
        $this->assertSame('39.70', $assinatura->fresh()->monthlyPrice());

        // Cuidador não paga nem soma: o assento é de quem cadastrou.
        $cuidador = User::factory()->create();
        ProfileCaregiver::query()->create(['profile_id' => $a->id, 'user_id' => $cuidador->id, 'invited_email' => $cuidador->email, 'invited_name' => 'X', 'access_level' => CaregiverAccess::Edit, 'status' => CaregiverStatus::Active]);
        $this->assertSame('39.70', $assinatura->fresh()->monthlyPrice());
        $this->assertSame(0, CareBillingService::seatsOf($cuidador));
    }

    public function test_assinatura_de_profissional_nao_soma_assento(): void
    {
        $consultor = User::factory()->consultant()->create();
        $assinatura = Subscription::create([
            'user_id' => $consultor->id, 'kind' => SubscriptionKind::Professional, 'bundle' => SubscriptionBundle::Completo, 'client_cap' => 25,
            'status' => SubscriptionStatus::Active, 'current_period_ends_at' => now()->addMonth(), 'started_at' => now(),
        ]);

        $this->assertSame(0, $assinatura->careSeats());
        $this->assertSame(\App\Support\ProfessionalPricing::priceFor(25), $assinatura->monthlyPrice());
    }

    public function test_cadastrar_troca_o_valor_na_asaas_para_cartao_e_apagar_devolve(): void
    {
        [$dono, $assinatura] = $this->donoComAssinatura(['asaas_subscription_id' => 'sub_1', 'billing_type' => PaymentMethod::CreditCard]);

        $this->mock(AsaasClient::class, function (MockInterface $m): void {
            $m->shouldReceive('updateSubscriptionValue')->once()->with('sub_1', '34.80')->ordered();
            $m->shouldReceive('updateSubscriptionValue')->once()->with('sub_1', '29.90')->ordered();
        });

        $pessoa = app(CareProfileService::class)->create($dono, 'Maria', null, CareProfileService::BASIS_AUTHORIZED);
        $this->assertSame(1, (int) $assinatura->fresh()->care_seats_billed);

        app(CareProfileService::class)->delete($pessoa);
        $this->assertSame(0, (int) $assinatura->fresh()->care_seats_billed);
    }

    public function test_pix_automatico_nao_troca_nada_na_asaas_o_valor_sai_na_hora_de_cobrar(): void
    {
        [$dono, $assinatura] = $this->donoComAssinatura(['asaas_subscription_id' => 'sub_2', 'billing_type' => PaymentMethod::PixAutomatic]);

        $this->mock(AsaasClient::class, function (MockInterface $m): void {
            $m->shouldNotReceive('updateSubscriptionValue');
        });

        app(CareProfileService::class)->create($dono, 'Maria', null, CareProfileService::BASIS_AUTHORIZED);

        $this->assertSame(1, (int) $assinatura->fresh()->care_seats_billed);
        // É este o valor que o Pix Automático cobra no ciclo (PixAutomaticBillingService usa monthlyPrice()).
        $this->assertSame('34.80', $assinatura->fresh()->monthlyPrice());
    }

    public function test_falha_da_asaas_nao_impede_o_cadastro_e_a_rotina_corrige_sem_repetir(): void
    {
        [$dono, $assinatura] = $this->donoComAssinatura(['asaas_subscription_id' => 'sub_3', 'billing_type' => PaymentMethod::CreditCard]);

        $this->mock(AsaasClient::class, function (MockInterface $m): void {
            $m->shouldReceive('updateSubscriptionValue')->once()->andThrow(new RuntimeException('Asaas fora do ar'))->ordered();
            $m->shouldReceive('updateSubscriptionValue')->once()->with('sub_3', '34.80')->ordered();
        });

        $pessoa = app(CareProfileService::class)->create($dono, 'Maria', null, CareProfileService::BASIS_AUTHORIZED);

        $this->assertNotNull(FinancialProfile::withCareProfiles()->find($pessoa->id), 'a Asaas fora do ar não trava o cuidado');
        $this->assertSame(0, (int) $assinatura->fresh()->care_seats_billed);

        $this->assertSame(1, app(CareBillingService::class)->reconcile());
        $this->assertSame(1, (int) $assinatura->fresh()->care_seats_billed);

        // Idempotente: com a contagem já alinhada, não chama a Asaas de novo.
        $this->assertSame(0, app(CareBillingService::class)->reconcile());
    }

    public function test_assinatura_nova_na_asaas_nasce_com_o_valor_que_inclui_as_pessoas(): void
    {
        config(['services.asaas.base_url' => 'https://asaas.test/v3', 'services.asaas.api_key' => 'k']);
        Http::fake(['*' => Http::response(['id' => 'sub_9', 'billingType' => 'CREDIT_CARD'])]);

        [$dono, $assinatura] = $this->donoComAssinatura();
        // Sem id na Asaas ainda (teste grátis), o cadastro da pessoa não chama a Asaas: só soma ao valor.
        app(CareProfileService::class)->create($dono, 'Maria', null, CareProfileService::BASIS_AUTHORIZED);

        app(AsaasClient::class)->createSubscription('cus_1', SubscriptionBundle::Completo, PaymentMethod::CreditCard, 'Cerne', null, '2026-12-01', $assinatura->id);

        Http::assertSent(fn ($request) => $request->url() === 'https://asaas.test/v3/subscriptions' && (float) $request['value'] === 34.8);
    }

    public function test_sem_assinatura_do_dono_a_pessoa_fica_suspensa_e_ninguem_e_avisado(): void
    {
        Notification::fake();
        [$dono, $assinatura] = $this->donoComAssinatura();
        $this->mock(AsaasClient::class);
        $pessoa = app(CareProfileService::class)->create($dono, 'Maria', null, CareProfileService::BASIS_AUTHORIZED);
        $cuidador = User::factory()->create();
        ProfileCaregiver::query()->create(['profile_id' => $pessoa->id, 'user_id' => $cuidador->id, 'invited_email' => $cuidador->email, 'invited_name' => 'X', 'access_level' => CaregiverAccess::Read, 'status' => CaregiverStatus::Active]);

        $this->actingAs($cuidador)->post(route('care.open', $pessoa->id));
        $this->get(route('health.card.index'))->assertOk();

        $assinatura->update(['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => now()]);

        $this->get(route('health.card.index'))->assertRedirect(route('care.index'));
        $this->get(route('documents.vault.index'))->assertRedirect(route('care.index'));
        $this->get(route('care.index'))->assertOk();

        $membro = ProfileMember::query()->where('profile_id', $pessoa->id)->sole();
        app(\App\Support\ProfileContext::class)->set($pessoa, null);
        $this->actingAs($dono);
        app(HealthAppointmentService::class)->create($membro, ['kind' => \App\Enums\HealthAppointmentKind::Consultation, 'title' => 'Exame', 'scheduled_at' => CarbonImmutable::now()->addDay()], null);
        app(\App\Support\ProfileContext::class)->clear();

        $this->assertSame(0, app(HealthAppointmentService::class)->notifyUpcoming(CarbonImmutable::now(), 1));
    }

    public function test_so_a_assinatura_direta_do_dono_vale_nao_a_de_um_profissional_vinculado(): void
    {
        [$dono, $assinatura] = $this->donoComAssinatura();
        $this->mock(AsaasClient::class);
        $pessoa = app(CareProfileService::class)->create($dono, 'Maria', null, CareProfileService::BASIS_AUTHORIZED);

        $assinatura->update(['status' => SubscriptionStatus::Cancelled]);

        // Mesmo com um consultor de assinatura em dia vinculado ao dono, o assento é cobrado do dono: sem a direta, está suspenso.
        $consultor = User::factory()->consultant()->create();
        Subscription::create(['user_id' => $consultor->id, 'kind' => SubscriptionKind::Professional, 'bundle' => SubscriptionBundle::Completo, 'client_cap' => 25, 'status' => SubscriptionStatus::Active, 'current_period_ends_at' => now()->addMonth(), 'started_at' => now()]);
        \App\Models\ConsultantClient::query()->create(['consultant_id' => $consultor->id, 'client_id' => $dono->id, 'status' => \App\Enums\ConsultantClientStatus::Active, 'invited_at' => now(), 'accepted_at' => now()]);

        $this->assertFalse(app(\App\Services\EntitlementService::class)->careProfileEntitled($pessoa));
    }

    // ----------------------------------------------------------------------

    /** @return array{0: User, 1: Subscription} */
    private function donoComAssinatura(array $extra = []): array
    {
        $dono = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $dono->id]);
        ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $dono->id, 'role' => MemberRole::Primary]);

        $assinatura = Subscription::create($extra + [
            'user_id' => $dono->id, 'kind' => SubscriptionKind::Direct, 'bundle' => SubscriptionBundle::Completo, 'status' => SubscriptionStatus::Active,
            'current_period_ends_at' => now()->addMonth(), 'started_at' => now(),
        ]);

        return [$dono, $assinatura];
    }
}
