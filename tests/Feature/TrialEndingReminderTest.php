<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\TrialEndingSoon;
use App\Services\SubscriptionReminderService;
use App\Support\NotificationPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Faltam 3 dias para o último dia do teste: e-mail e aviso dentro do Cerne. */
class TrialEndingReminderTest extends TestCase
{
    use RefreshDatabase;

    private function teste(int $diasParaTravar, array $extra = []): Subscription
    {
        return Subscription::create($extra + [
            'user_id' => User::factory()->create()->id,
            'kind' => SubscriptionKind::Direct,
            'bundle' => SubscriptionBundle::SaudeDocumentos,
            'status' => SubscriptionStatus::Trialing,
            'current_period_ends_at' => Carbon::today()->addDays($diasParaTravar),
            'started_at' => now(),
        ]);
    }

    public function test_avisa_quem_tem_3_dias_de_teste_pelos_dois_canais(): void
    {
        Notification::fake();
        $assinatura = $this->teste(3);

        self::assertSame(1, app(SubscriptionReminderService::class)->notifyTrialEnding());

        Notification::assertSentTo($assinatura->user, TrialEndingSoon::class, function (TrialEndingSoon $n) use ($assinatura) {
            return $n->via($assinatura->user) === ['mail', 'database']
                && $n->lastDay === Carbon::today()->addDays(2)->toDateString()
                && $n->priceFormatted === 'R$ 15,90';
        });
    }

    public function test_so_avisa_no_dia_certo_e_so_quem_ainda_esta_no_teste(): void
    {
        Notification::fake();
        $doisDias = $this->teste(2);
        $quatroDias = $this->teste(4);
        $pago = $this->teste(3, ['status' => SubscriptionStatus::Active]);
        $cancelado = $this->teste(3, ['status' => SubscriptionStatus::Cancelled]);

        self::assertSame(0, app(SubscriptionReminderService::class)->notifyTrialEnding());

        foreach ([$doisDias, $quatroDias, $pago, $cancelado] as $assinatura) {
            Notification::assertNotSentTo($assinatura->user, TrialEndingSoon::class);
        }
    }

    public function test_rodar_duas_vezes_no_mesmo_dia_avisa_uma_vez_so(): void
    {
        Notification::fake();
        $this->teste(3);

        $servico = app(SubscriptionReminderService::class);

        self::assertSame(1, $servico->notifyTrialEnding());
        self::assertSame(0, $servico->notifyTrialEnding());
        Notification::assertCount(1);
    }

    public function test_avisa_mesmo_quem_ja_escolheu_a_forma_de_pagamento_mas_nao_pagou(): void
    {
        Notification::fake();
        $assinatura = $this->teste(3, ['billing_type' => PaymentMethod::Pix, 'asaas_subscription_id' => 'sub_1']);

        self::assertSame(1, app(SubscriptionReminderService::class)->notifyTrialEnding());
        Notification::assertSentTo($assinatura->user, TrialEndingSoon::class);
    }

    public function test_o_aviso_aparece_no_sino_com_o_texto_e_o_link_da_assinatura(): void
    {
        $assinatura = $this->teste(3);

        $assinatura->user->notifyNow(new TrialEndingSoon('Saúde + Documentos', Carbon::today()->addDays(2)->toDateString(), 'R$ 15,90'));

        $gravada = $assinatura->user->notifications()->sole();
        $apresentada = NotificationPresenter::present($gravada);

        self::assertSame('Teste grátis terminando', $apresentada['heading']);
        self::assertStringContainsString(Carbon::today()->addDays(2)->format('d/m'), $apresentada['message']);
        self::assertSame(route('subscription.index'), $apresentada['url']);
    }

    public function test_o_email_diz_o_ultimo_dia_o_valor_e_leva_a_tela_de_pagamento(): void
    {
        $assinatura = $this->teste(3);
        $ultimo = Carbon::today()->addDays(2);

        $mail = (new TrialEndingSoon('Saúde + Documentos', $ultimo->toDateString(), 'R$ 15,90'))->toMail($assinatura->user);
        $html = (string) $mail->render();

        self::assertSame('Seu teste grátis do Cerne termina em 3 dias', $mail->subject);
        self::assertStringContainsString($ultimo->format('d/m/Y'), $html);
        self::assertStringContainsString('R$ 15,90', $html);
        self::assertStringContainsString(route('subscription.index'), $html);
        self::assertStringNotContainsString('—', $html);
    }
}
