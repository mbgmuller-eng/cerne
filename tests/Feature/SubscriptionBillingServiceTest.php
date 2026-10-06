<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Exceptions\AsaasBillingTypeMismatch;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SubscriptionAccessEnding;
use App\Services\AsaasClient;
use App\Services\SubscriptionBillingService;
use App\Services\SubscriptionReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * A assinatura na Asaas só é criada perto do fim do teste grátis: a Asaas gera a
 * primeira cobrança no instante da criação, então no cadastro nada vai para lá.
 */
class SubscriptionBillingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function asaas(): MockInterface
    {
        $asaas = Mockery::mock(AsaasClient::class);
        $asaas->shouldReceive('findOrCreateCustomer')->andReturn('cus_1')->byDefault();
        $asaas->shouldReceive('findSubscriptionIdByReference')->andReturn(null)->byDefault();
        $this->app->instance(AsaasClient::class, $asaas);

        return $asaas;
    }

    private function teste(int $diasParaOFim, array $extra = []): Subscription
    {
        return Subscription::create($extra + [
            'user_id' => User::factory()->create()->id,
            'kind' => SubscriptionKind::Direct,
            'bundle' => SubscriptionBundle::SaudeDocumentos,
            'billing_type' => PaymentMethod::Pix,
            'status' => SubscriptionStatus::Trialing,
            'current_period_ends_at' => Carbon::today()->addDays($diasParaOFim),
            'started_at' => now()->subDays(7 - $diasParaOFim),
        ]);
    }

    public function test_cria_a_assinatura_3_dias_antes_do_fim_com_vencimento_no_ultimo_dia_do_teste(): void
    {
        $assinatura = $this->teste(3);

        $this->asaas()->shouldReceive('createSubscription')->once()
            ->with('cus_1', SubscriptionBundle::SaudeDocumentos, PaymentMethod::Pix, Mockery::type('string'), null, Carbon::today()->addDays(3)->toDateString(), $assinatura->id)
            ->andReturn(['id' => 'sub_novo']);

        self::assertSame(1, app(SubscriptionBillingService::class)->createDueSubscriptions());

        $assinatura->refresh();
        self::assertSame('sub_novo', $assinatura->asaas_subscription_id);
        self::assertNull($assinatura->billing_claimed_at);
        self::assertSame(SubscriptionStatus::Trialing, $assinatura->status);
    }

    public function test_antes_da_janela_nao_cria_nada(): void
    {
        $this->teste(4);

        $asaas = $this->asaas();
        $asaas->shouldNotReceive('createSubscription');
        $asaas->shouldNotReceive('findOrCreateCustomer');

        self::assertSame(0, app(SubscriptionBillingService::class)->createDueSubscriptions());
    }

    public function test_cartao_tambem_e_criado_e_o_profissional_leva_o_teto_de_clientes(): void
    {
        $assinatura = $this->teste(2, [
            'kind' => SubscriptionKind::Professional, 'bundle' => SubscriptionBundle::Completo,
            'billing_type' => PaymentMethod::CreditCard, 'client_cap' => 30,
        ]);

        $this->asaas()->shouldReceive('createSubscription')->once()
            ->with('cus_1', SubscriptionBundle::Completo, PaymentMethod::CreditCard, Mockery::type('string'), 30, Mockery::type('string'), $assinatura->id)
            ->andReturn(['id' => 'sub_pro']);

        self::assertSame(1, app(SubscriptionBillingService::class)->createDueSubscriptions());
    }

    public function test_so_cria_para_teste_de_cartao_ou_pix_ainda_sem_assinatura_na_asaas(): void
    {
        $this->teste(2, ['billing_type' => PaymentMethod::PixAutomatic]);        // tem fluxo próprio
        $this->teste(2, ['asaas_subscription_id' => 'sub_ja_tem']);               // já criada
        $this->teste(2, ['status' => SubscriptionStatus::Cancelled]);             // cancelada
        $this->teste(2, ['status' => SubscriptionStatus::Active]);                // já paga / cortesia
        $this->teste(2, ['billing_type' => null]);                                // cortesia sem forma de pagamento

        $asaas = $this->asaas();
        $asaas->shouldNotReceive('createSubscription');

        self::assertSame(0, app(SubscriptionBillingService::class)->createDueSubscriptions());
    }

    public function test_cron_parado_cria_com_vencimento_hoje_e_nunca_no_passado(): void
    {
        $assinatura = $this->teste(-2); // o teste acabou há 2 dias, ainda dentro da carência

        $this->asaas()->shouldReceive('createSubscription')->once()
            ->with(Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any(), null, Carbon::today()->toDateString(), $assinatura->id)
            ->andReturn(['id' => 'sub_tarde']);

        self::assertSame(1, app(SubscriptionBillingService::class)->createDueSubscriptions());
    }

    public function test_teste_abandonado_ha_tempo_nao_vira_cobranca(): void
    {
        $this->teste(-10);

        $this->asaas()->shouldNotReceive('createSubscription');

        self::assertSame(0, app(SubscriptionBillingService::class)->createDueSubscriptions());
    }

    public function test_rodar_duas_vezes_cria_uma_assinatura_so(): void
    {
        $this->teste(3);

        $this->asaas()->shouldReceive('createSubscription')->once()->andReturn(['id' => 'sub_novo']);

        $service = app(SubscriptionBillingService::class);
        $service->createDueSubscriptions();
        $service->createDueSubscriptions();

        self::assertSame(1, Subscription::query()->whereNotNull('asaas_subscription_id')->count());
    }

    public function test_reaproveita_assinatura_que_a_asaas_criou_e_o_cerne_nao_gravou(): void
    {
        $assinatura = $this->teste(3);

        $asaas = $this->asaas();
        $asaas->shouldReceive('findSubscriptionIdByReference')->with($assinatura->id)->andReturn('sub_orfa');
        $asaas->shouldNotReceive('createSubscription');

        self::assertSame(1, app(SubscriptionBillingService::class)->createDueSubscriptions());

        self::assertSame('sub_orfa', $assinatura->fresh()->asaas_subscription_id);
    }

    public function test_falha_da_asaas_libera_a_reserva_e_a_proxima_execucao_tenta_de_novo(): void
    {
        $assinatura = $this->teste(3);

        $asaas = $this->asaas();
        $asaas->shouldReceive('createSubscription')->once()->andThrow(new RuntimeException('Asaas fora do ar'));
        $asaas->shouldReceive('createSubscription')->once()->andReturn(['id' => 'sub_novo']);

        $service = app(SubscriptionBillingService::class);

        self::assertSame(0, $service->createDueSubscriptions());
        self::assertNull($assinatura->fresh()->billing_claimed_at);
        self::assertNull($assinatura->fresh()->asaas_subscription_id);

        self::assertSame(1, $service->createDueSubscriptions());
    }

    public function test_forma_de_pagamento_errada_nao_e_repetida_no_mesmo_dia(): void
    {
        $assinatura = $this->teste(3);

        $asaas = $this->asaas();
        $asaas->shouldReceive('createSubscription')->once()->andThrow(new AsaasBillingTypeMismatch('PIX', 'BOLETO'));

        $service = app(SubscriptionBillingService::class);

        self::assertSame(0, $service->createDueSubscriptions());
        self::assertNull($assinatura->fresh()->asaas_subscription_id);

        // Segunda execução no mesmo dia: não chama a Asaas de novo (cada tentativa geraria e cancelaria um boleto).
        self::assertSame(0, $service->createDueSubscriptions());
    }

    public function test_reserva_recente_bloqueia_e_reserva_velha_e_retomada(): void
    {
        $assinatura = $this->teste(3, ['billing_claimed_at' => now()]);

        $asaas = $this->asaas();
        $asaas->shouldReceive('createSubscription')->once()->andReturn(['id' => 'sub_novo']);

        $service = app(SubscriptionBillingService::class);
        self::assertSame(0, $service->createDueSubscriptions(), 'outra execução está cuidando');

        $assinatura->update(['billing_claimed_at' => now()->subMinutes(30)]);
        self::assertSame(1, $service->createDueSubscriptions());
    }

    // ---- o teste expira sozinho

    public function test_teste_dentro_do_prazo_tem_acesso_e_depois_da_carencia_nao(): void
    {
        $dentro = $this->teste(2);
        $naCarencia = $this->teste(-4);          // cartão/Pix: 5 dias de carência
        $vencida = $this->teste(-6);

        self::assertTrue($dentro->isCurrent());
        self::assertTrue($naCarencia->isCurrent());
        self::assertFalse($vencida->isCurrent());
    }

    public function test_pix_automatico_nao_autorizado_perde_o_acesso_depois_de_7_dias(): void
    {
        $naCarencia = $this->teste(-6, ['billing_type' => PaymentMethod::PixAutomatic]);
        $vencida = $this->teste(-8, ['billing_type' => PaymentMethod::PixAutomatic]);

        self::assertTrue($naCarencia->isCurrent());
        self::assertFalse($vencida->isCurrent());
    }

    public function test_teste_de_cortesia_sem_data_continua_com_acesso(): void
    {
        $cortesia = Subscription::create([
            'user_id' => User::factory()->create()->id, 'kind' => SubscriptionKind::Direct, 'bundle' => SubscriptionBundle::Completo,
            'status' => SubscriptionStatus::Trialing, 'started_at' => now(),
        ]);

        self::assertTrue($cortesia->isCurrent());
    }

    public function test_aviso_de_acesso_encerrando_tambem_vai_para_o_teste_que_nao_foi_pago(): void
    {
        Notification::fake();
        $asaas = Mockery::mock(AsaasClient::class);
        $asaas->shouldReceive('currentInvoiceUrl')->andReturn(null);

        $assinatura = $this->teste(-4, ['asaas_subscription_id' => 'sub_x']); // último dia da carência de 5

        self::assertSame(1, app(SubscriptionReminderService::class)->notifyAccessEndingTomorrow($asaas));

        Notification::assertSentTo($assinatura->user, SubscriptionAccessEnding::class);
    }
}
