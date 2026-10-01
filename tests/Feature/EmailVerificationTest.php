<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_link_assinado_valido_confirma_e_libera_a_assinatura(): void
    {
        $usuario = User::factory()->unverified()->create();

        $link = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $usuario->id,
            'hash' => sha1($usuario->email),
        ]);

        $this->actingAs($usuario)->get($link)->assertRedirect(route('subscription.index'));

        self::assertNotNull($usuario->fresh()->email_verified_at);
    }

    public function test_link_adulterado_da_403(): void
    {
        $usuario = User::factory()->unverified()->create();

        $link = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $usuario->id,
            'hash' => sha1('outro-email@exemplo.com'),
        ]);

        $this->actingAs($usuario)->get($link)->assertForbidden();
        self::assertNull($usuario->fresh()->email_verified_at);
    }

    public function test_link_de_outro_usuario_nao_verifica_a_sessao_atual(): void
    {
        $dono = User::factory()->unverified()->create();
        $outro = User::factory()->unverified()->create();

        $link = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $dono->id,
            'hash' => sha1($dono->email),
        ]);

        $this->actingAs($outro)->get($link)->assertForbidden();
        self::assertNull($outro->fresh()->email_verified_at);
    }

    public function test_reenviar_dispara_nova_notificacao(): void
    {
        Notification::fake();

        $usuario = User::factory()->unverified()->create();

        $this->actingAs($usuario)->post(route('verification.send'))
            ->assertRedirect()
            ->assertSessionHas('status');

        Notification::assertSentTo($usuario, \App\Notifications\VerifyEmailAddress::class);
    }
}
