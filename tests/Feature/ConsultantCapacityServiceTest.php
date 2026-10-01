<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Models\ConsultantClient;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ConsultantCapacityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsultantCapacityServiceTest extends TestCase
{
    use RefreshDatabase;

    private function vincularClientesAtivos(User $consultor, int $quantidade): void
    {
        ConsultantClient::factory()->count($quantidade)->create([
            'consultant_id' => $consultor->id,
            'status' => ConsultantClientStatus::Active,
        ]);
    }

    public function test_sem_assinatura_professional_nao_tem_teto(): void
    {
        $consultor = User::factory()->consultant()->create();

        self::assertNull(app(ConsultantCapacityService::class)->remainingSlots($consultor));
        self::assertTrue(app(ConsultantCapacityService::class)->hasRoomForNewClient($consultor));
    }

    public function test_assinatura_com_client_cap_nulo_nao_tem_teto(): void
    {
        $consultor = User::factory()->consultant()->create();
        Subscription::create([
            'user_id' => $consultor->id,
            'kind' => SubscriptionKind::Professional,
            'bundle' => SubscriptionBundle::Completo,
            'client_cap' => null,
            'status' => SubscriptionStatus::Active,
            'started_at' => now(),
        ]);
        $this->vincularClientesAtivos($consultor, 35);

        self::assertNull(app(ConsultantCapacityService::class)->remainingSlots($consultor));
        self::assertTrue(app(ConsultantCapacityService::class)->hasRoomForNewClient($consultor));
    }

    public function test_dentro_do_teto_tem_vaga(): void
    {
        $consultor = User::factory()->consultant()->create();
        Subscription::create([
            'user_id' => $consultor->id,
            'kind' => SubscriptionKind::Professional,
            'bundle' => SubscriptionBundle::Completo,
            'client_cap' => 10,
            'status' => SubscriptionStatus::Active,
            'started_at' => now(),
        ]);
        $this->vincularClientesAtivos($consultor, 9);

        self::assertSame(1, app(ConsultantCapacityService::class)->remainingSlots($consultor));
        self::assertTrue(app(ConsultantCapacityService::class)->hasRoomForNewClient($consultor));
    }

    public function test_no_teto_exato_nao_tem_vaga(): void
    {
        $consultor = User::factory()->consultant()->create();
        Subscription::create([
            'user_id' => $consultor->id,
            'kind' => SubscriptionKind::Professional,
            'bundle' => SubscriptionBundle::Completo,
            'client_cap' => 10,
            'status' => SubscriptionStatus::Active,
            'started_at' => now(),
        ]);
        $this->vincularClientesAtivos($consultor, 10);

        self::assertSame(0, app(ConsultantCapacityService::class)->remainingSlots($consultor));
        self::assertFalse(app(ConsultantCapacityService::class)->hasRoomForNewClient($consultor));
    }

    public function test_assinatura_vencida_nao_bloqueia(): void
    {
        $consultor = User::factory()->consultant()->create();
        Subscription::create([
            'user_id' => $consultor->id,
            'kind' => SubscriptionKind::Professional,
            'bundle' => SubscriptionBundle::Completo,
            'client_cap' => 10,
            'status' => SubscriptionStatus::Cancelled,
            'started_at' => now(),
            'cancelled_at' => now(),
        ]);
        $this->vincularClientesAtivos($consultor, 10);

        self::assertNull(app(ConsultantCapacityService::class)->remainingSlots($consultor));
        self::assertTrue(app(ConsultantCapacityService::class)->hasRoomForNewClient($consultor));
    }
}
