<?php

namespace Tests\Feature;

use App\Enums\MemberRole;
use App\Livewire\Profile\MyAccount;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_desligar_email_persiste_na_hora(): void
    {
        [$perfil, $titular] = $this->criarPerfil();

        Livewire::test(MyAccount::class)
            ->assertSet('notifyEmail', true)
            ->set('notifyEmail', false);

        self::assertFalse($titular->fresh()->notify_email_enabled);
    }

    public function test_desligar_push_persiste_na_hora(): void
    {
        [$perfil, $titular] = $this->criarPerfil();
        $titular->update(['notify_push_enabled' => true]);

        Livewire::test(MyAccount::class)->set('notifyPush', false);

        self::assertFalse($titular->fresh()->notify_push_enabled);
    }

    /**
     * Ligar o toggle de push NÃO persiste sozinho — quem liga de verdade é
     * a inscrição via PushSubscriptionController, chamada pelo JS só depois
     * que o navegador confirma. Ver App\Livewire\Profile\MyAccount::updatedNotifyPush.
     */
    public function test_ligar_push_no_livewire_nao_persiste_sem_inscricao(): void
    {
        [$perfil, $titular] = $this->criarPerfil();

        Livewire::test(MyAccount::class)->set('notifyPush', true);

        self::assertFalse($titular->fresh()->notify_push_enabled);
    }

    public function test_inscricao_de_push_liga_a_flag_e_grava_a_inscricao(): void
    {
        [$perfil, $titular] = $this->criarPerfil();
        $this->actingAs($titular);

        $resposta = $this->postJson('/preferencias/push', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
            'keys' => ['p256dh' => 'chave-p256dh', 'auth' => 'chave-auth'],
        ]);

        $resposta->assertOk();
        self::assertTrue($titular->fresh()->notify_push_enabled);
        self::assertDatabaseHas('push_subscriptions', [
            'subscribable_id' => $titular->id,
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
        ]);
    }

    /** @return array{0: FinancialProfile, 1: User} */
    private function criarPerfil(): array
    {
        $titular = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $titular->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $titular->id, 'role' => MemberRole::Primary]);
        $this->actingAs($titular);
        app(ProfileContext::class)->set($perfil, $membro);

        return [$perfil, $titular];
    }
}
