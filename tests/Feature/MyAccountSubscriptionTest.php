<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\MemberRole;
use App\Enums\PaymentMethod;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Livewire\Profile\MyAccount;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\Subscription;
use App\Models\User;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Minha conta" mostra de onde vem o acesso (assinatura própria ou
 * profissional vinculado cobrindo) e, pro profissional, a própria
 * assinatura e a carteira de clientes vinculados — sem cliente nenhum
 * aberto, é "a conta dele", não a de um cliente.
 */
class MyAccountSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private function criarPerfilIndividual(): array
    {
        $titular = User::factory()->create(['name' => 'Marcelo Müller']);
        $profile = FinancialProfile::factory()->create(['owner_user_id' => $titular->id]);
        ProfileMember::factory()->create(['profile_id' => $profile->id, 'user_id' => $titular->id, 'role' => MemberRole::Primary]);

        return [$profile, $titular];
    }

    public function test_cliente_com_assinatura_propria_ve_como_individual(): void
    {
        [$profile, $titular] = $this->criarPerfilIndividual();
        Subscription::create([
            'user_id' => $titular->id,
            'kind' => SubscriptionKind::Direct,
            'bundle' => SubscriptionBundle::Completo,
            'billing_type' => PaymentMethod::Pix,
            'status' => SubscriptionStatus::Active,
            'current_period_ends_at' => now()->addDays(20),
            'started_at' => now(),
        ]);

        $this->actingAs($titular);
        app(ProfileContext::class)->set($profile, $profile->members->first());

        Livewire::test(MyAccount::class)
            ->assertSee('Individual')
            ->assertSee('Completo')
            ->assertSee('Pix')
            ->assertSee(now()->addDays(20)->format('d/m/Y'))
            ->assertSee('Gerenciar assinatura');
    }

    public function test_cliente_vinculado_sem_assinatura_propria_ve_cobertura_do_profissional(): void
    {
        [$profile, $titular] = $this->criarPerfilIndividual();
        $corretor = User::factory()->broker()->create(['name' => 'Bruno Corretor']);
        ConsultantClient::factory()->create([
            'consultant_id' => $corretor->id,
            'client_id' => $titular->id,
            'status' => ConsultantClientStatus::Active,
        ]);
        Subscription::create([
            'user_id' => $corretor->id,
            'kind' => SubscriptionKind::Professional,
            'bundle' => SubscriptionBundle::SaudeDocumentos,
            'status' => SubscriptionStatus::Active,
            'started_at' => now(),
        ]);

        $this->actingAs($titular);
        app(ProfileContext::class)->set($profile, $profile->members->first());

        Livewire::test(MyAccount::class)
            ->assertSee('Vinculada')
            ->assertSee('Bruno Corretor')
            ->assertSee('Corretor')
            ->assertSee('Cobrindo')
            ->assertDontSee('Gerenciar assinatura');
    }

    public function test_profissional_sem_cliente_aberto_ve_a_propria_conta(): void
    {
        $corretor = User::factory()->broker()->create(['name' => 'Bruno Corretor']);
        Subscription::create([
            'user_id' => $corretor->id,
            'kind' => SubscriptionKind::Professional,
            'bundle' => SubscriptionBundle::SaudeDocumentos,
            'billing_type' => PaymentMethod::CreditCard,
            'status' => SubscriptionStatus::Trialing,
            'current_period_ends_at' => now()->addDays(5),
            'started_at' => now(),
        ]);

        $cliente = User::factory()->create(['name' => 'André Albuquerque']);
        ConsultantClient::factory()->create([
            'consultant_id' => $corretor->id,
            'client_id' => $cliente->id,
            'status' => ConsultantClientStatus::Active,
            'accepted_at' => now(),
        ]);

        $this->actingAs($corretor);

        Livewire::test(MyAccount::class)
            ->assertSee('Saúde + Documentos')
            ->assertSee('Cartão de crédito')
            ->assertSee('André Albuquerque')
            ->assertSee('Clientes vinculados');
    }

    public function test_profissional_remove_cliente_vinculado(): void
    {
        $corretor = User::factory()->broker()->create(['name' => 'Bruno Corretor']);
        $cliente = User::factory()->create(['name' => 'André Albuquerque']);
        $vinculo = ConsultantClient::factory()->create([
            'consultant_id' => $corretor->id,
            'client_id' => $cliente->id,
            'status' => ConsultantClientStatus::Active,
            'accepted_at' => now(),
        ]);

        $this->actingAs($corretor);

        Livewire::test(MyAccount::class)
            ->assertSee('André Albuquerque')
            ->call('removerCliente', $vinculo->id)
            ->assertDontSee('André Albuquerque');

        self::assertSame(ConsultantClientStatus::Inactive, $vinculo->fresh()->status);
    }

    public function test_profissional_nao_remove_cliente_de_outro_profissional(): void
    {
        $corretor = User::factory()->broker()->create();
        $outroCorretor = User::factory()->broker()->create();
        $cliente = User::factory()->create();
        $vinculo = ConsultantClient::factory()->create([
            'consultant_id' => $outroCorretor->id,
            'client_id' => $cliente->id,
            'status' => ConsultantClientStatus::Active,
        ]);

        $this->actingAs($corretor);

        Livewire::test(MyAccount::class)
            ->call('removerCliente', $vinculo->id)
            ->assertStatus(404);

        self::assertSame(ConsultantClientStatus::Active, $vinculo->fresh()->status);
    }

    public function test_profissional_com_cliente_aberto_continua_vendo_a_conta_do_cliente(): void
    {
        [$profile, $titular] = $this->criarPerfilIndividual();
        $consultor = User::factory()->consultant()->create(['name' => 'Marina Alencar']);
        ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id,
            'client_id' => $titular->id,
            'status' => ConsultantClientStatus::Active,
        ]);

        $this->actingAs($consultor);
        app(ProfileContext::class)->set($profile, null);

        Livewire::test(MyAccount::class)
            ->assertSee('Marcelo Müller')
            ->assertDontSee('Clientes vinculados');
    }
}
