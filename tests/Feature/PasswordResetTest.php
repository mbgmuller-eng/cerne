<?php

namespace Tests\Feature;

use App\Livewire\Profile\ChangePassword;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Notifications\ResetPasswordLink;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Esqueci minha senha" e troca de senha em Minha conta (revisão de segurança 2026-11).
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const RESPOSTA = 'Se este e-mail tiver uma conta no Cerne, enviamos um link para escolher uma nova senha. Ele vale por 60 minutos.';

    public function test_o_pedido_tem_a_mesma_resposta_com_ou_sem_conta_e_so_manda_a_quem_tem_conta_ativa(): void
    {
        Notification::fake();
        $ativo = User::factory()->create(['email' => 'ativo@exemplo.com']);
        $inativo = User::factory()->create(['email' => 'inativo@exemplo.com', 'is_active' => false]);

        foreach (['ativo@exemplo.com', 'inativo@exemplo.com', 'ninguem@exemplo.com'] as $email) {
            $this->post(route('password.email'), ['email' => $email])->assertSessionHas('status', self::RESPOSTA);
        }

        Notification::assertSentTo($ativo, ResetPasswordLink::class);
        Notification::assertNotSentTo($inativo, ResetPasswordLink::class);
        Notification::assertCount(1);
    }

    public function test_o_link_do_e_mail_leva_so_o_token_nunca_o_e_mail_da_conta(): void
    {
        Notification::fake();
        $usuario = User::factory()->create(['email' => 'pessoa@exemplo.com']);

        $this->post(route('password.email'), ['email' => 'pessoa@exemplo.com']);

        Notification::assertSentTo($usuario, ResetPasswordLink::class, function (ResetPasswordLink $n) use ($usuario) {
            $url = $n->toMail($usuario)->viewData['url'];
            $this->assertStringNotContainsString('pessoa', $url);
            $this->assertStringNotContainsString('@', $url);

            return true;
        });
    }

    public function test_redefinir_troca_a_senha_derruba_as_sessoes_e_o_link_so_vale_uma_vez(): void
    {
        $usuario = User::factory()->create(['email' => 'pessoa@exemplo.com', 'password' => 'SenhaAntiga123']);
        DB::table('sessions')->insert(['id' => 'sessao-de-outro-aparelho', 'user_id' => $usuario->id, 'payload' => '', 'last_activity' => time()]);
        $token = Password::createToken($usuario);

        $this->post(route('password.update'), [
            'token' => $token, 'email' => 'pessoa@exemplo.com', 'password' => 'NovaSenha456', 'password_confirmation' => 'NovaSenha456',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('NovaSenha456', $usuario->fresh()->password));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $usuario->id)->count(), 'as sessões abertas caem');

        // O mesmo link, de novo: já foi usado.
        $this->post(route('password.update'), [
            'token' => $token, 'email' => 'pessoa@exemplo.com', 'password' => 'OutraSenha789', 'password_confirmation' => 'OutraSenha789',
        ])->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('NovaSenha456', $usuario->fresh()->password));
    }

    public function test_redefinir_nao_aceita_token_errado_nem_senha_fraca(): void
    {
        $usuario = User::factory()->create(['email' => 'pessoa@exemplo.com', 'password' => 'SenhaAntiga123']);
        $token = Password::createToken($usuario);

        $this->post(route('password.update'), [
            'token' => 'token-errado', 'email' => 'pessoa@exemplo.com', 'password' => 'NovaSenha456', 'password_confirmation' => 'NovaSenha456',
        ])->assertSessionHasErrors('email');

        $this->post(route('password.update'), [
            'token' => $token, 'email' => 'pessoa@exemplo.com', 'password' => 'curta', 'password_confirmation' => 'curta',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('SenhaAntiga123', $usuario->fresh()->password));
    }

    public function test_quem_recebe_o_link_no_proprio_email_ativa_a_conta_que_o_profissional_criou(): void
    {
        $usuario = User::factory()->unverified()->create(['email' => 'cliente@exemplo.com', 'password_pending' => true]);
        $token = Password::createToken($usuario);

        $this->post(route('password.update'), [
            'token' => $token, 'email' => 'cliente@exemplo.com', 'password' => 'NovaSenha456', 'password_confirmation' => 'NovaSenha456',
        ])->assertRedirect(route('login'));

        $usuario->refresh();
        $this->assertFalse($usuario->password_pending);
        $this->assertNotNull($usuario->email_verified_at);
    }

    public function test_sessao_aberta_com_a_senha_antiga_deixa_de_valer(): void
    {
        $usuario = User::factory()->create(['password' => 'SenhaAntiga123']);
        $this->actingAs($usuario)->get(route('verification.notice'))->assertOk();

        // Outro aparelho troca a senha: esta sessão guardava o hash antigo.
        $usuario->forceFill(['password' => 'NovaSenha456'])->save();

        $this->get(route('verification.notice'))->assertRedirect(route('login'));
    }

    public function test_troca_de_senha_pede_a_atual_e_derruba_os_outros_aparelhos(): void
    {
        $usuario = User::factory()->create(['password' => 'SenhaAntiga123']);
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id]);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($perfil, $membro);
        DB::table('sessions')->insert(['id' => 'outro-aparelho', 'user_id' => $usuario->id, 'payload' => '', 'last_activity' => time()]);

        Livewire::test(ChangePassword::class)
            ->set('currentPassword', 'errada')->set('newPassword', 'NovaSenha456')->set('newPasswordConfirmation', 'NovaSenha456')
            ->call('save')->assertHasErrors('currentPassword');
        $this->assertTrue(Hash::check('SenhaAntiga123', $usuario->fresh()->password));
        $this->assertSame(1, DB::table('sessions')->where('id', 'outro-aparelho')->count());

        Livewire::test(ChangePassword::class)
            ->set('currentPassword', 'SenhaAntiga123')->set('newPassword', 'NovaSenha456')->set('newPasswordConfirmation', 'NovaSenha456')
            ->call('save')->assertHasNoErrors();

        $this->assertTrue(Hash::check('NovaSenha456', $usuario->fresh()->password));
        $this->assertSame(0, DB::table('sessions')->where('id', 'outro-aparelho')->count());
    }

    public function test_quem_esta_vendo_como_outra_pessoa_nao_troca_a_senha_dela(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $alvo = User::factory()->create(['password' => 'SenhaAntiga123']);
        $this->actingAs($alvo)->withSession(['impersonator_id' => $admin->id]);

        Livewire::test(ChangePassword::class)
            ->set('currentPassword', 'SenhaAntiga123')->set('newPassword', 'NovaSenha456')->set('newPasswordConfirmation', 'NovaSenha456')
            ->call('save')->assertForbidden();

        $this->assertTrue(Hash::check('SenhaAntiga123', $alvo->fresh()->password));
    }
}
