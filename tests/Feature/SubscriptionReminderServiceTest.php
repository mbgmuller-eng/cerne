<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\PixPaymentDueSoon;
use App\Notifications\SubscriptionAccessEnding;
use App\Services\AsaasClient;
use App\Services\SubscriptionReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Pix não tem débito automático — o aviso de 3 dias antes é o que evita a
 * pessoa ser pega de surpresa com o acesso cortado.
 */
class SubscriptionReminderServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fakeAsaas(): AsaasClient
    {
        $asaas = Mockery::mock(AsaasClient::class);
        $asaas->shouldReceive('currentInvoiceUrl')->andReturn('https://sandbox.asaas.com/i/teste');

        return $asaas;
    }

    private function criarAssinatura(PaymentMethod $metodo, int $diasAteVencer, SubscriptionStatus $status = SubscriptionStatus::Trialing): Subscription
    {
        return Subscription::create([
            'user_id' => User::factory()->create()->id,
            'kind' => SubscriptionKind::Direct,
            'bundle' => SubscriptionBundle::Completo,
            'billing_type' => $metodo,
            'status' => $status,
            'current_period_ends_at' => now()->addDays($diasAteVencer),
            'asaas_subscription_id' => 'sub_'.Str::random(10),
            'started_at' => now(),
        ]);
    }

    public function test_avisa_assinatura_pix_vencendo_em_exatamente_3_dias(): void
    {
        Notification::fake();
        $assinatura = $this->criarAssinatura(PaymentMethod::Pix, 3);

        $notificados = app(SubscriptionReminderService::class)->notifyUpcomingPixDueDates($this->fakeAsaas());

        self::assertSame(1, $notificados);
        Notification::assertSentTo($assinatura->user, PixPaymentDueSoon::class);
    }

    public function test_nao_avisa_fora_da_janela_de_3_dias(): void
    {
        Notification::fake();
        $this->criarAssinatura(PaymentMethod::Pix, 2);
        $this->criarAssinatura(PaymentMethod::Pix, 4);

        $notificados = app(SubscriptionReminderService::class)->notifyUpcomingPixDueDates($this->fakeAsaas());

        self::assertSame(0, $notificados);
        Notification::assertNothingSent();
    }

    public function test_nao_avisa_assinatura_de_cartao(): void
    {
        Notification::fake();
        $this->criarAssinatura(PaymentMethod::CreditCard, 3);

        $notificados = app(SubscriptionReminderService::class)->notifyUpcomingPixDueDates($this->fakeAsaas());

        self::assertSame(0, $notificados);
        Notification::assertNothingSent();
    }

    public function test_nao_avisa_assinatura_cancelada(): void
    {
        Notification::fake();
        $this->criarAssinatura(PaymentMethod::Pix, 3, SubscriptionStatus::Cancelled);

        $notificados = app(SubscriptionReminderService::class)->notifyUpcomingPixDueDates($this->fakeAsaas());

        self::assertSame(0, $notificados);
        Notification::assertNothingSent();
    }

    public function test_avisa_no_ultimo_dia_da_carencia_que_o_acesso_acaba_amanha(): void
    {
        Notification::fake();
        // Venceu há 4 dias: carência de 5 dias, corte amanhã.
        $assinatura = $this->criarAssinatura(PaymentMethod::Pix, -4, SubscriptionStatus::PastDue);

        $avisados = app(SubscriptionReminderService::class)->notifyAccessEndingTomorrow($this->fakeAsaas());

        self::assertSame(1, $avisados);
        Notification::assertSentTo($assinatura->user, SubscriptionAccessEnding::class, function (SubscriptionAccessEnding $n) {
            return $n->cutoffFormatted === now()->addDay()->format('d/m/Y');
        });
    }

    public function test_aviso_de_acesso_encerrando_nao_repete_se_o_cron_rodar_duas_vezes(): void
    {
        Notification::fake();
        $this->criarAssinatura(PaymentMethod::CreditCard, -4, SubscriptionStatus::PastDue);

        $primeira = app(SubscriptionReminderService::class)->notifyAccessEndingTomorrow($this->fakeAsaas());
        $segunda = app(SubscriptionReminderService::class)->notifyAccessEndingTomorrow($this->fakeAsaas());

        self::assertSame(1, $primeira);
        self::assertSame(0, $segunda);
        Notification::assertSentTimes(SubscriptionAccessEnding::class, 1);
    }

    public function test_nao_avisa_acesso_encerrando_fora_do_ultimo_dia_nem_quem_esta_em_dia(): void
    {
        Notification::fake();
        $this->criarAssinatura(PaymentMethod::Pix, -3, SubscriptionStatus::PastDue);
        $this->criarAssinatura(PaymentMethod::Pix, -5, SubscriptionStatus::PastDue);
        $this->criarAssinatura(PaymentMethod::Pix, -4, SubscriptionStatus::Active);
        $this->criarAssinatura(PaymentMethod::Pix, -4, SubscriptionStatus::Cancelled);

        $avisados = app(SubscriptionReminderService::class)->notifyAccessEndingTomorrow($this->fakeAsaas());

        self::assertSame(0, $avisados);
        Notification::assertNothingSent();
    }
}
