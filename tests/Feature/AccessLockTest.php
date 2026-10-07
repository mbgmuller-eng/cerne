<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\PaymentMethod;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Bloqueio do app inteiro para quem não tem assinatura em vigor: acabou o teste
 * grátis (sem dias extras) ou o pagamento não entrou, e a pessoa vai direto
 * para a tela de assinatura.
 */
class AccessLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.enforce_access' => true]);
    }

    private function assinatura(User $usuario, array $extra = []): Subscription
    {
        return Subscription::create($extra + [
            'user_id' => $usuario->id,
            'kind' => $usuario->isLinkedProfessional() ? SubscriptionKind::Professional : SubscriptionKind::Direct,
            'bundle' => SubscriptionBundle::Completo,
            'status' => SubscriptionStatus::Trialing,
            'current_period_ends_at' => Carbon::today()->addDays(3),
            'started_at' => now(),
        ]);
    }

    private function comPerfil(User $usuario): void
    {
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id]);
    }

    public function test_sem_a_chave_ligada_ninguem_e_barrado(): void
    {
        config(['billing.enforce_access' => false]);
        $usuario = User::factory()->create();
        $this->comPerfil($usuario);

        $this->actingAs($usuario)->get(route('dashboard'))->assertOk();
    }

    public function test_quem_nunca_assinou_vai_para_a_tela_de_assinatura(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)->get(route('dashboard'))
            ->assertRedirect(route('subscription.index'))
            ->assertSessionHas('status');
    }

    public function test_teste_em_andamento_tem_acesso(): void
    {
        $usuario = User::factory()->create();
        $this->comPerfil($usuario);
        $this->assinatura($usuario);

        $this->actingAs($usuario)->get(route('dashboard'))->assertOk();
    }

    public function test_no_dia_em_que_o_teste_acaba_o_acesso_trava_sem_carencia(): void
    {
        $usuario = User::factory()->create();
        $this->assinatura($usuario, ['current_period_ends_at' => Carbon::today()]);

        $this->actingAs($usuario)->get(route('dashboard'))->assertRedirect(route('subscription.index'));
        $this->actingAs($usuario)->get(route('fixedbills.index'))->assertRedirect(route('subscription.index'));
        $this->actingAs($usuario)->get(route('insurance.index'))->assertRedirect(route('subscription.index'));
    }

    public function test_um_dia_antes_do_fim_ainda_tem_acesso(): void
    {
        $usuario = User::factory()->create();
        $this->comPerfil($usuario);
        $this->assinatura($usuario, ['current_period_ends_at' => Carbon::tomorrow()]);

        $this->actingAs($usuario)->get(route('dashboard'))->assertOk();
    }

    public function test_assinatura_paga_tem_acesso(): void
    {
        $usuario = User::factory()->create();
        $this->comPerfil($usuario);
        $this->assinatura($usuario, ['status' => SubscriptionStatus::Active, 'current_period_ends_at' => Carbon::today()->addMonth()]);

        $this->actingAs($usuario)->get(route('dashboard'))->assertOk();
    }

    public function test_renovacao_atrasada_tem_carencia_de_5_dias_e_depois_trava(): void
    {
        $naCarencia = User::factory()->create();
        $this->comPerfil($naCarencia);
        $this->assinatura($naCarencia, ['status' => SubscriptionStatus::PastDue, 'billing_type' => PaymentMethod::Pix, 'current_period_ends_at' => Carbon::today()->subDays(4)]);

        $vencida = User::factory()->create();
        $this->assinatura($vencida, ['status' => SubscriptionStatus::PastDue, 'billing_type' => PaymentMethod::Pix, 'current_period_ends_at' => Carbon::today()->subDays(6)]);

        $this->actingAs($naCarencia)->get(route('dashboard'))->assertOk();
        $this->actingAs($vencida)->get(route('dashboard'))->assertRedirect(route('subscription.index'));
    }

    public function test_assinatura_cancelada_nao_tem_acesso(): void
    {
        $usuario = User::factory()->create();
        $this->assinatura($usuario, ['status' => SubscriptionStatus::Cancelled]);

        $this->actingAs($usuario)->get(route('dashboard'))->assertRedirect(route('subscription.index'));
    }

    public function test_o_necessario_para_pagar_e_sair_continua_aberto_mesmo_bloqueado(): void
    {
        $usuario = User::factory()->create();
        $this->comPerfil($usuario);
        $this->assinatura($usuario, ['current_period_ends_at' => Carbon::today()->subDays(2)]);

        $this->actingAs($usuario)->get(route('subscription.index'))->assertOk()->assertSee('Seu acesso gratuito terminou');
        $this->actingAs($usuario)->get(route('my-account'))->assertOk();
        self::assertNotSame(route('subscription.index'), $this->actingAs($usuario)->post(route('theme.update'), ['theme' => 'dark'])->headers->get('Location'));
        $this->actingAs($usuario)->post(route('logout'))->assertRedirect();
    }

    public function test_administrador_nunca_e_barrado(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($admin)->get(route('admin.users'))->assertOk();
    }

    public function test_profissional_precisa_da_propria_assinatura(): void
    {
        $semAssinatura = User::factory()->consultant()->create();
        $comAssinatura = User::factory()->consultant()->create();
        $this->assinatura($comAssinatura);

        $this->actingAs($semAssinatura)->get(route('consultant.portfolio'))->assertRedirect(route('subscription.index'));
        $this->actingAs($comAssinatura)->get(route('consultant.portfolio'))->assertOk();
    }

    public function test_cliente_vinculado_tem_acesso_pela_assinatura_do_profissional(): void
    {
        $profissional = User::factory()->consultant()->create();
        $this->assinatura($profissional, ['status' => SubscriptionStatus::Active, 'current_period_ends_at' => Carbon::today()->addMonth()]);

        $cliente = User::factory()->create();
        $this->comPerfil($cliente);
        ConsultantClient::factory()->create([
            'consultant_id' => $profissional->id, 'client_id' => $cliente->id, 'status' => ConsultantClientStatus::Active,
        ]);

        $this->actingAs($cliente)->get(route('dashboard'))->assertOk();
    }

    public function test_cliente_perde_o_acesso_quando_o_vinculo_nao_esta_ativo_ou_a_assinatura_do_profissional_acaba(): void
    {
        $profissional = User::factory()->consultant()->create();
        $this->assinatura($profissional, ['status' => SubscriptionStatus::PastDue, 'current_period_ends_at' => Carbon::today()->subDays(10)]);

        $cliente = User::factory()->create();
        ConsultantClient::factory()->create([
            'consultant_id' => $profissional->id, 'client_id' => $cliente->id, 'status' => ConsultantClientStatus::Active,
        ]);

        $this->actingAs($cliente)->get(route('dashboard'))->assertRedirect(route('subscription.index'));
    }

    public function test_cliente_bloqueado_ainda_consegue_aceitar_o_vinculo_com_um_profissional(): void
    {
        $profissional = User::factory()->consultant()->create();
        $cliente = User::factory()->create();
        $vinculo = ConsultantClient::factory()->create([
            'consultant_id' => $profissional->id, 'client_id' => $cliente->id, 'status' => ConsultantClientStatus::Pending,
        ]);

        $resposta = $this->actingAs($cliente)->post(route('link.decline', $vinculo));

        self::assertNotSame(route('subscription.index'), $resposta->headers->get('Location'), 'o vínculo não pode cair no bloqueio');
    }

    public function test_a_saida_da_personificacao_nunca_fica_presa_no_bloqueio(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $bloqueado = User::factory()->create();

        $resposta = $this->actingAs($bloqueado)->withSession(['impersonator_id' => $admin->id])->post(route('admin.impersonate.stop'));

        self::assertNotSame(route('subscription.index'), $resposta->headers->get('Location'));
    }
}
