<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\PlatformModule;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\Subscription;
use App\Models\User;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A pergunta que todo RequiresModule faz: este perfil tem acesso àquele
 * produto agora? Direto (assinatura do próprio dono) ou via profissional
 * vinculado e ativo — nunca os dois critérios misturados num só lugar.
 */
class EntitlementServiceTest extends TestCase
{
    use RefreshDatabase;

    private EntitlementService $entitlements;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entitlements = app(EntitlementService::class);
    }

    public function test_assinatura_direta_ativa_libera_os_modulos_do_pacote(): void
    {
        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $this->assinar($usuario, SubscriptionBundle::FinancasSegurosDocumentos, SubscriptionKind::Direct);

        self::assertTrue($this->entitlements->profileHasModule($perfil, PlatformModule::Financas));
        self::assertTrue($this->entitlements->profileHasModule($perfil, PlatformModule::Seguros));
        self::assertTrue($this->entitlements->profileHasModule($perfil, PlatformModule::Documentos));
    }

    public function test_pacote_nao_libera_modulo_fora_dele(): void
    {
        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $this->assinar($usuario, SubscriptionBundle::FinancasSegurosDocumentos, SubscriptionKind::Direct);

        self::assertFalse($this->entitlements->profileHasModule($perfil, PlatformModule::Saude));
    }

    public function test_sem_assinatura_nenhuma_nao_libera_nada(): void
    {
        $perfil = FinancialProfile::factory()->create();

        self::assertFalse($this->entitlements->profileHasModule($perfil, PlatformModule::Financas));
    }

    public function test_profissional_vinculado_e_ativo_cobre_o_cliente(): void
    {
        $profissional = User::factory()->consultant()->create();
        $cliente = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $cliente->id]);
        ConsultantClient::factory()->create([
            'consultant_id' => $profissional->id, 'client_id' => $cliente->id, 'status' => ConsultantClientStatus::Active,
        ]);
        $this->assinar($profissional, SubscriptionBundle::Completo, SubscriptionKind::Professional);

        self::assertTrue($this->entitlements->profileHasModule($perfil, PlatformModule::Saude));
    }

    public function test_vinculo_pendente_nao_cobre_o_cliente(): void
    {
        $profissional = User::factory()->consultant()->create();
        $cliente = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $cliente->id]);
        ConsultantClient::factory()->pending()->create([
            'consultant_id' => $profissional->id, 'client_id' => $cliente->id,
        ]);
        $this->assinar($profissional, SubscriptionBundle::Completo, SubscriptionKind::Professional);

        self::assertFalse($this->entitlements->profileHasModule($perfil, PlatformModule::Saude));
    }

    public function test_vinculo_inativo_nao_cobre_o_cliente(): void
    {
        $profissional = User::factory()->consultant()->create();
        $cliente = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $cliente->id]);
        ConsultantClient::factory()->create([
            'consultant_id' => $profissional->id, 'client_id' => $cliente->id, 'status' => ConsultantClientStatus::Inactive,
        ]);
        $this->assinar($profissional, SubscriptionBundle::Completo, SubscriptionKind::Professional);

        self::assertFalse($this->entitlements->profileHasModule($perfil, PlatformModule::Saude));
    }

    public function test_profissional_escolhe_o_pacote_que_os_clientes_recebem(): void
    {
        $profissional = User::factory()->consultant()->create();
        $cliente = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $cliente->id]);
        ConsultantClient::factory()->create([
            'consultant_id' => $profissional->id, 'client_id' => $cliente->id, 'status' => ConsultantClientStatus::Active,
        ]);
        $this->assinar($profissional, SubscriptionBundle::SaudeDocumentos, SubscriptionKind::Professional);

        self::assertTrue($this->entitlements->profileHasModule($perfil, PlatformModule::Saude));
        self::assertFalse($this->entitlements->profileHasModule($perfil, PlatformModule::Financas));
    }

    public function test_past_due_dentro_da_carencia_ainda_libera(): void
    {
        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        Subscription::create([
            'user_id' => $usuario->id, 'kind' => SubscriptionKind::Direct, 'bundle' => SubscriptionBundle::Completo,
            'status' => SubscriptionStatus::PastDue, 'current_period_ends_at' => Carbon::yesterday(), 'started_at' => now()->subMonth(),
        ]);

        self::assertTrue($this->entitlements->profileHasModule($perfil, PlatformModule::Financas));
    }

    public function test_past_due_fora_da_carencia_nao_libera(): void
    {
        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        Subscription::create([
            'user_id' => $usuario->id, 'kind' => SubscriptionKind::Direct, 'bundle' => SubscriptionBundle::Completo,
            'status' => SubscriptionStatus::PastDue,
            'current_period_ends_at' => Carbon::today()->subDays(Subscription::PAST_DUE_GRACE_DAYS + 1),
            'started_at' => now()->subMonth(),
        ]);

        self::assertFalse($this->entitlements->profileHasModule($perfil, PlatformModule::Financas));
    }

    public function test_assinatura_nova_sem_pagamento_confirmado_nao_libera(): void
    {
        // PastDue sem current_period_ends_at nenhum — é o estado inicial
        // logo após criar a assinatura, antes do primeiro webhook de
        // pagamento confirmar (ver SubscriptionIndex::assinar()).
        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        Subscription::create([
            'user_id' => $usuario->id, 'kind' => SubscriptionKind::Direct, 'bundle' => SubscriptionBundle::Completo,
            'status' => SubscriptionStatus::PastDue, 'started_at' => now(),
        ]);

        self::assertFalse($this->entitlements->profileHasModule($perfil, PlatformModule::Financas));
    }

    public function test_assinatura_cancelada_nao_libera(): void
    {
        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        Subscription::create([
            'user_id' => $usuario->id, 'kind' => SubscriptionKind::Direct, 'bundle' => SubscriptionBundle::Completo,
            'status' => SubscriptionStatus::Cancelled, 'started_at' => now()->subMonth(), 'cancelled_at' => now(),
        ]);

        self::assertFalse($this->entitlements->profileHasModule($perfil, PlatformModule::Financas));
    }

    private function assinar(User $usuario, SubscriptionBundle $bundle, SubscriptionKind $kind): Subscription
    {
        return Subscription::create([
            'user_id' => $usuario->id,
            'kind' => $kind,
            'bundle' => $bundle,
            'status' => SubscriptionStatus::Active,
            'started_at' => now(),
        ]);
    }
}
