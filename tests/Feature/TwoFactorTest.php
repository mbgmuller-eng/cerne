<?php

namespace Tests\Feature;

use App\Livewire\Profile\TwoFactorSettings;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Verificação em dois passos (revisão de segurança 2026-11): o algoritmo, o login em duas etapas, a configuração em Minha conta e
 * a exigência para quem vê dado de outras pessoas.
 */
class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private function servico(): TwoFactorService
    {
        return app(TwoFactorService::class);
    }

    private function comDoisPassos(array $atributos = []): array
    {
        $usuario = User::factory()->create(array_merge(['email' => 'pessoa@exemplo.com', 'password' => 'SenhaCerta123'], $atributos));
        $segredo = $this->servico()->generateSecret();
        $codigos = $this->servico()->enable($usuario, $segredo, $this->servico()->codeAt($segredo));
        $this->assertNotNull($codigos);
        // O passo do código usado para ligar já foi consumido: o login usa o PRÓXIMO passo.
        $usuario->forceFill(['two_factor_last_step' => intdiv(time(), 30) - 5])->save();

        return [$usuario->fresh(), $segredo, $codigos];
    }

    public function test_o_algoritmo_bate_com_os_vetores_da_rfc_6238(): void
    {
        // Segredo ASCII "12345678901234567890" em base32; códigos de 6 dígitos (os últimos 6 dos vetores de 8 da RFC).
        $segredo = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

        $this->assertSame('287082', $this->servico()->codeAt($segredo, 59));
        $this->assertSame('081804', $this->servico()->codeAt($segredo, 1111111109));
        $this->assertSame('005924', $this->servico()->codeAt($segredo, 1234567890));
    }

    public function test_um_codigo_ja_usado_nao_vale_de_novo_e_codigo_de_outro_horario_nao_passa(): void
    {
        $segredo = $this->servico()->generateSecret();
        $agora = time();
        $codigo = $this->servico()->codeAt($segredo, $agora);

        $passo = $this->servico()->matchStep($segredo, $codigo, null, $agora);
        $this->assertSame(intdiv($agora, 30), $passo);
        // Repetição: o mesmo passo (ou anterior) ao do último aceito.
        $this->assertNull($this->servico()->matchStep($segredo, $codigo, $passo, $agora));
        // Código de dois minutos atrás: fora da janela.
        $this->assertNull($this->servico()->matchStep($segredo, $this->servico()->codeAt($segredo, $agora - 120), null, $agora));
        $this->assertNull($this->servico()->matchStep($segredo, '12345', null, $agora));
        $this->assertNull($this->servico()->matchStep($segredo, 'abcdef', null, $agora));
    }

    public function test_senha_certa_sozinha_nao_abre_a_sessao_de_quem_tem_dois_passos(): void
    {
        [$usuario, $segredo] = $this->comDoisPassos();

        $this->post(route('login.store'), ['email' => 'pessoa@exemplo.com', 'password' => 'SenhaCerta123'])->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();

        $this->get(route('dashboard'))->assertRedirect(route('login'));

        $this->post(route('two-factor.store'), ['code' => $this->servico()->codeAt($segredo)])->assertRedirect();
        $this->assertAuthenticatedAs($usuario);
    }

    public function test_codigo_errado_nao_entra_e_senha_errada_nem_chega_a_tela_do_codigo(): void
    {
        $this->comDoisPassos();

        $this->post(route('login.store'), ['email' => 'pessoa@exemplo.com', 'password' => 'errada'])->assertSessionHasErrors('email');

        $this->post(route('login.store'), ['email' => 'pessoa@exemplo.com', 'password' => 'SenhaCerta123']);
        $this->post(route('two-factor.store'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_a_tela_do_codigo_sem_a_senha_antes_volta_para_o_login(): void
    {
        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
        $this->post(route('two-factor.store'), ['code' => '123456'])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_depois_de_cinco_erros_nem_o_codigo_certo_entra(): void
    {
        [, $segredo] = $this->comDoisPassos();
        $this->post(route('login.store'), ['email' => 'pessoa@exemplo.com', 'password' => 'SenhaCerta123']);

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('two-factor.store'), ['code' => '000000'])->assertSessionHasErrors('code');
        }

        $this->post(route('two-factor.store'), ['code' => $this->servico()->codeAt($segredo)])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_codigo_de_recuperacao_entra_uma_vez_so(): void
    {
        [$usuario, , $codigos] = $this->comDoisPassos();

        $this->post(route('login.store'), ['email' => 'pessoa@exemplo.com', 'password' => 'SenhaCerta123']);
        $this->post(route('two-factor.store'), ['code' => $codigos[0]])->assertRedirect();
        $this->assertAuthenticatedAs($usuario);

        $this->post(route('logout'));

        $this->post(route('login.store'), ['email' => 'pessoa@exemplo.com', 'password' => 'SenhaCerta123']);
        $this->post(route('two-factor.store'), ['code' => $codigos[0]])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->assertCount(7, $usuario->fresh()->two_factor_recovery_codes);
    }

    public function test_o_segredo_fica_cifrado_no_banco_e_nunca_sai_na_serializacao(): void
    {
        [$usuario, $segredo, $codigos] = $this->comDoisPassos();

        $bruto = DB::table('users')->where('id', $usuario->id)->first();
        $this->assertStringNotContainsString($segredo, (string) $bruto->two_factor_secret);
        $this->assertStringNotContainsString($codigos[0], (string) $bruto->two_factor_recovery_codes);

        $this->assertArrayNotHasKey('two_factor_secret', $usuario->toArray());
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $usuario->toArray());
    }

    public function test_configurar_em_minha_conta_so_liga_depois_de_um_codigo_de_verdade(): void
    {
        $usuario = User::factory()->create(['password' => 'SenhaCerta123']);
        $this->actingAs($usuario);

        $tela = Livewire::test(TwoFactorSettings::class)->call('startSetup');
        $segredo = session('two_factor_setup_secret');
        $this->assertIsString($segredo);

        $tela->set('code', '000000')->call('confirmSetup')->assertHasErrors('code');
        $this->assertFalse($usuario->fresh()->hasTwoFactor(), 'configurar e errar o código não liga nada');

        $tela->set('code', $this->servico()->codeAt($segredo))->call('confirmSetup')->assertHasNoErrors();

        $this->assertTrue($usuario->fresh()->hasTwoFactor());
        $this->assertCount(8, $tela->get('recoveryCodes'));
    }

    public function test_desligar_pede_a_senha(): void
    {
        [$usuario] = $this->comDoisPassos();
        $this->actingAs($usuario);

        Livewire::test(TwoFactorSettings::class)->set('password', 'errada')->call('disable')->assertHasErrors('password');
        $this->assertTrue($usuario->fresh()->hasTwoFactor());

        Livewire::test(TwoFactorSettings::class)->set('password', 'SenhaCerta123')->call('disable')->assertHasNoErrors();
        $this->assertFalse($usuario->fresh()->hasTwoFactor());
    }

    public function test_quem_vê_dado_de_outras_pessoas_nao_desliga_quando_a_exigencia_esta_ligada(): void
    {
        config(['cerne.security.two_factor_required' => true]);
        [$consultor] = $this->comDoisPassos(['role' => 'consultant']);
        $this->actingAs($consultor);

        Livewire::test(TwoFactorSettings::class)->set('password', 'SenhaCerta123')->call('disable')->assertHasErrors('password');
        $this->assertTrue($consultor->fresh()->hasTwoFactor());
    }

    public function test_com_a_exigencia_ligada_profissional_sem_dois_passos_so_ve_a_tela_de_configuracao(): void
    {
        config(['cerne.security.two_factor_required' => true]);

        $consultor = User::factory()->consultant()->create();
        $this->actingAs($consultor)->get(route('consultant.portfolio'))->assertRedirect(route('two-factor.setup'));
        $this->get(route('two-factor.setup'))->assertOk();

        $corretor = User::factory()->broker()->create();
        $this->actingAs($corretor)->get(route('insurance.index'))->assertRedirect(route('two-factor.setup'));

        $admin = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($admin)->get(route('admin.users'))->assertRedirect(route('two-factor.setup'));
    }

    public function test_com_a_exigencia_ligada_quem_ja_configurou_e_o_cliente_comum_seguem_normalmente(): void
    {
        config(['cerne.security.two_factor_required' => true]);

        [$consultor] = $this->comDoisPassos(['role' => 'consultant']);
        $this->actingAs($consultor)->get(route('consultant.portfolio'))->assertOk();

        $cliente = User::factory()->create();
        // O cliente comum pode ser mandado para outro lugar (sem perfil, por exemplo), mas nunca para a configuração do segundo passo.
        $resposta = $this->actingAs($cliente)->get(route('subscription.index'));
        $this->assertNotSame(route('two-factor.setup'), $resposta->headers->get('Location'));
    }

    public function test_com_a_exigencia_desligada_ninguem_e_barrado(): void
    {
        config(['cerne.security.two_factor_required' => false]);

        $consultor = User::factory()->consultant()->create();
        $this->actingAs($consultor)->get(route('consultant.portfolio'))->assertOk();
    }

    public function test_quem_esta_vendo_como_outra_pessoa_nao_mexe_no_dois_passos_dela(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $alvo = User::factory()->create();
        $this->actingAs($alvo)->withSession(['impersonator_id' => $admin->id]);

        Livewire::test(TwoFactorSettings::class)->call('startSetup')->assertForbidden();
    }
}
