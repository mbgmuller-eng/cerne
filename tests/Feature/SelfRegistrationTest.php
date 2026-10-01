<?php

namespace Tests\Feature;

use App\Enums\MemberRole;
use App\Enums\ProfileType;
use App\Enums\UserRole;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Cadastro sem convite (`/cadastro`) — diferente de AcceptInviteTest: aqui
 * ninguém vouch pelo e-mail, então a conta nasce sem `email_verified_at` e
 * fica barrada em qualquer rota "verified" até confirmar (ver
 * EmailVerificationTest).
 */
class SelfRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function dadosValidos(array $overrides = []): array
    {
        return array_merge([
            'tipo_conta' => 'cliente',
            'nome' => 'Fernanda Lima',
            'email' => 'fernanda.lima@exemplo.com',
            'password' => 'Senha123',
            'password_confirmation' => 'Senha123',
            'termos' => '1',
        ], $overrides);
    }

    public function test_cadastro_de_cliente_cria_usuario_perfil_e_membro_titular(): void
    {
        Notification::fake();

        $this->post(route('register.store'), $this->dadosValidos())
            ->assertRedirect(route('subscription.index'));

        $usuario = User::query()->where('email', 'fernanda.lima@exemplo.com')->sole();

        self::assertSame(UserRole::Client, $usuario->role);
        self::assertNull($usuario->email_verified_at);
        self::assertNotNull($usuario->terms_accepted_at);
        self::assertTrue(auth()->check());
        self::assertSame($usuario->id, auth()->id());

        $perfil = FinancialProfile::query()->where('owner_user_id', $usuario->id)->sole();
        self::assertSame('Fernanda Lima', $perfil->profile_name);
        self::assertSame(ProfileType::Single, $perfil->profile_type);

        self::assertTrue(ProfileMember::query()
            ->where('profile_id', $perfil->id)
            ->where('user_id', $usuario->id)
            ->where('role', MemberRole::Primary)
            ->exists());
    }

    public function test_conta_nao_verificada_nao_abre_tela_protegida(): void
    {
        Notification::fake();

        $this->post(route('register.store'), $this->dadosValidos());

        $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));
    }

    public function test_cadastro_de_profissional_cria_so_o_usuario_sem_perfil(): void
    {
        Notification::fake();

        $this->post(route('register.store'), $this->dadosValidos([
            'tipo_conta' => 'profissional',
            'papel' => 'broker',
            'email' => 'corretor@exemplo.com',
        ]))->assertRedirect(route('subscription.index'));

        $usuario = User::query()->where('email', 'corretor@exemplo.com')->sole();

        self::assertSame(UserRole::Broker, $usuario->role);
        self::assertSame(0, FinancialProfile::query()->where('owner_user_id', $usuario->id)->count());
    }

    public function test_profissional_sem_papel_e_rejeitado(): void
    {
        Notification::fake();

        $this->post(route('register.store'), $this->dadosValidos([
            'tipo_conta' => 'profissional',
            'email' => 'sem.papel@exemplo.com',
        ]))->assertSessionHasErrors('papel');

        self::assertSame(0, User::query()->where('email', 'sem.papel@exemplo.com')->count());
    }

    public function test_email_duplicado_e_rejeitado(): void
    {
        User::factory()->create(['email' => 'ja.existe@exemplo.com']);

        $this->post(route('register.store'), $this->dadosValidos(['email' => 'ja.existe@exemplo.com']))
            ->assertSessionHasErrors('email');
    }

    public function test_senha_fraca_e_rejeitada(): void
    {
        $this->post(route('register.store'), $this->dadosValidos([
            'password' => 'abc',
            'password_confirmation' => 'abc',
        ]))->assertSessionHasErrors('password');

        self::assertSame(0, User::query()->where('email', 'fernanda.lima@exemplo.com')->count());
    }

    public function test_termos_nao_aceitos_e_rejeitado(): void
    {
        $this->post(route('register.store'), $this->dadosValidos(['termos' => false]))
            ->assertSessionHasErrors('termos');

        self::assertSame(0, User::query()->where('email', 'fernanda.lima@exemplo.com')->count());
    }
}
