<?php

namespace Tests\Feature;

use App\Livewire\Profile\PrivacyCenter;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\Subscription;
use App\Models\SubscriptionWebhookEvent;
use App\Models\User;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Services\ClientInviteService;
use App\Services\SubscriptionWebhookService;
use App\Support\MailThrottle;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Tetos de tentativa (revisão de segurança 2026-11): telas públicas por IP, e-mail de convite por destino e por remetente,
 * exclusão de conta e envio de documento para leitura por IA. Cada um existe para impedir um abuso que o app antes aceitava.
 */
class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('x');
    }

    public function test_o_login_tem_um_teto_por_ip_alem_do_de_email(): void
    {
        // Cada e-mail é diferente: o limite por e-mail+IP nunca dispara, só o do IP.
        for ($i = 1; $i <= 20; $i++) {
            $this->post(route('login.store'), ['email' => "alvo{$i}@exemplo.com", 'password' => 'errada'])->assertSessionHasErrors('email');
        }

        $this->post(route('login.store'), ['email' => 'alvo21@exemplo.com', 'password' => 'errada'])->assertStatus(429);
    }

    public function test_cadastro_e_compra_tem_teto_por_ip(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->post(route('register.store'), [])->assertSessionHasErrors();
        }

        $this->post(route('register.store'), [])->assertStatus(429);
        // O contador é o mesmo para a compra: não dá para alternar entre as duas telas e dobrar o teto.
        $this->post(route('checkout.store'), [])->assertStatus(429);
    }

    public function test_abrir_convite_pelo_token_tem_teto_por_ip(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->get(route('invite.accept', 'token-que-nao-existe-'.$i));
        }

        $this->get(route('invite.accept', 'token-que-nao-existe-31'))->assertStatus(429);
    }

    public function test_o_mesmo_endereco_nao_recebe_convite_demais_na_mesma_hora(): void
    {
        Mail::fake();
        $consultor = User::factory()->consultant()->create();

        for ($i = 1; $i <= MailThrottle::PER_DESTINATION_PER_HOUR; $i++) {
            app(ClientInviteService::class)->send($consultor, 'Pessoa', 'vitima@exemplo.com');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('convites demais para este e-mail');

        try {
            app(ClientInviteService::class)->send($consultor, 'Pessoa', 'vitima@exemplo.com');
        } finally {
            Mail::assertQueuedCount(MailThrottle::PER_DESTINATION_PER_HOUR);
        }
    }

    public function test_um_remetente_tem_teto_diario_de_convites(): void
    {
        Mail::fake();
        $consultor = User::factory()->consultant()->create();

        for ($i = 1; $i <= MailThrottle::PER_SENDER_PER_DAY; $i++) {
            app(ClientInviteService::class)->send($consultor, 'Pessoa', "pessoa{$i}@exemplo.com");
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Limite diário');

        app(ClientInviteService::class)->send($consultor, 'Pessoa', 'mais-uma@exemplo.com');
    }

    public function test_o_teto_de_senha_da_exclusao_de_conta_vale_mesmo_com_a_senha_certa_depois(): void
    {
        $usuario = User::factory()->create(['password' => 'SenhaCerta123']);
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id]);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($perfil, $membro);

        $tela = Livewire::test(PrivacyCenter::class)->set('deleteEmail', $usuario->email);

        for ($i = 1; $i <= 5; $i++) {
            $tela->set('deletePassword', 'errada'.$i)->call('startDeletion')->assertHasErrors('deletePassword');
        }

        // A sexta tentativa, ainda que com a senha certa, espera: o teto não revela se a senha estava certa.
        $tela->set('deletePassword', 'SenhaCerta123')->call('startDeletion');
        $this->assertStringContainsString('Muitas tentativas', $tela->errors()->first('deletePassword'));
    }

    public function test_evento_do_webhook_so_fica_registrado_se_o_tratamento_deu_certo(): void
    {
        $assinatura = Subscription::create([
            'user_id' => User::factory()->create()->id,
            'kind' => SubscriptionKind::Direct,
            'bundle' => SubscriptionBundle::Completo,
            'status' => SubscriptionStatus::PastDue,
            'asaas_subscription_id' => 'sub_falha',
            'started_at' => now(),
        ]);
        $payload = ['id' => 'evt_reentrega', 'event' => 'PAYMENT_CONFIRMED', 'payment' => ['subscription' => 'sub_falha', 'dueDate' => now()->toDateString()]];

        $falhar = true;
        Subscription::updating(function () use (&$falhar): void {
            if ($falhar) {
                throw new RuntimeException('falha simulada');
            }
        });

        try {
            app(SubscriptionWebhookService::class)->handle($payload);
            $this->fail('o tratamento deveria ter falhado');
        } catch (RuntimeException $e) {
            $this->assertSame('falha simulada', $e->getMessage());
        }

        // Sem registro: a Asaas reentrega e o evento é tratado de verdade (antes ficava marcado como processado e se perdia).
        $this->assertSame(0, SubscriptionWebhookEvent::query()->where('asaas_event_id', 'evt_reentrega')->count());

        $falhar = false;
        app(SubscriptionWebhookService::class)->handle($payload);

        $this->assertSame(1, SubscriptionWebhookEvent::query()->where('asaas_event_id', 'evt_reentrega')->count());
        $this->assertSame(SubscriptionStatus::Active, $assinatura->fresh()->status);
    }
}
