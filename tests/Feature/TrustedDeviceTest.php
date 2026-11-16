<?php

namespace Tests\Feature;

use App\Livewire\Profile\ChangePassword;
use App\Livewire\Profile\TwoFactorSettings;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\TrustedDeviceService;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * "Confiar neste navegador" (dispensa o CÓDIGO do segundo passo, nunca a senha) e o "manter conectado" de 30 dias: o cookie, o prazo (30 dias; 14 para
 * profissional), a troca do token a cada uso, o que derruba tudo (senha, segundo passo refeito) e a lista em Minha conta.
 */
class TrustedDeviceTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    private function dois(): TwoFactorService
    {
        return app(TwoFactorService::class);
    }

    /** @return array{0: User, 1: string} usuário com segundo passo ligado e o segredo */
    private function comDoisPassos(array $atributos = []): array
    {
        $usuario = User::factory()->create(array_merge(['email' => 'pessoa@exemplo.com', 'password' => 'SenhaCerta123'], $atributos));
        $segredo = $this->dois()->generateSecret();
        $this->dois()->enable($usuario, $segredo, $this->dois()->codeAt($segredo));
        $usuario->forceFill(['two_factor_last_step' => intdiv(time(), 30) - 5])->save();

        return [$usuario->fresh(), $segredo];
    }

    private function entrarComCodigo(string $segredo, bool $confiar, bool $lembrar = false, string $email = 'pessoa@exemplo.com')
    {
        $this->withHeader('User-Agent', self::CHROME)->post(route('login.store'), ['email' => $email, 'password' => 'SenhaCerta123'] + ($lembrar ? ['remember' => '1'] : []));

        // Cada entrada usa um passo novo de 30 s (o código aceito não repete).
        User::query()->where('email', $email)->update(['two_factor_last_step' => intdiv(time(), 30) - 5]);

        return $this->withHeader('User-Agent', self::CHROME)->post(route('two-factor.store'), ['code' => $this->dois()->codeAt($segredo)] + ($confiar ? ['trust' => '1'] : []));
    }

    private function cookieConfiavel($resposta, ?User $usuario = null): ?Cookie
    {
        $nome = $usuario !== null ? app(TrustedDeviceService::class)->cookieName($usuario) : 'cerne_td_';

        return collect($resposta->headers->getCookies())->first(fn (Cookie $c) => str_starts_with($c->getName(), $nome));
    }

    /** Fecha a sessão (o navegador "volta amanhã") mas fica com o cookie de confiança. */
    private function novoDia(): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
    }

    private function entrarSoComSenha(Cookie $cookie, string $senha = 'SenhaCerta123')
    {
        return $this->withHeader('User-Agent', self::CHROME)->withUnencryptedCookie($cookie->getName(), $cookie->getValue())
            ->post(route('login.store'), ['email' => 'pessoa@exemplo.com', 'password' => $senha]);
    }

    // ------------------------------------------------------------------ emitir

    public function test_marcar_confiar_emite_o_cookie_e_guarda_so_o_hash_com_o_prazo_de_30_dias(): void
    {
        [$usuario, $segredo] = $this->comDoisPassos();

        $resposta = $this->entrarComCodigo($segredo, confiar: true);

        $this->assertAuthenticatedAs($usuario);
        $cookie = $this->cookieConfiavel($resposta, $usuario);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertEqualsWithDelta(30, ($cookie->getExpiresTime() - time()) / 86400, 0.1);

        $linha = TrustedDevice::query()->sole();
        $this->assertSame($usuario->id, $linha->user_id);
        $this->assertSame('Chrome no Windows', $linha->label);
        $this->assertEqualsWithDelta(30, now()->diffInDays($linha->expires_at), 0.1);
        $this->assertSame(64, strlen($linha->token_hash));
    }

    public function test_sem_marcar_ou_com_codigo_errado_nada_e_emitido(): void
    {
        [$usuario, $segredo] = $this->comDoisPassos();

        $this->assertNull($this->cookieConfiavel($this->entrarComCodigo($segredo, confiar: false), $usuario));
        $this->assertSame(0, TrustedDevice::query()->count());

        $this->novoDia();
        $this->post(route('login.store'), ['email' => 'pessoa@exemplo.com', 'password' => 'SenhaCerta123']);
        $errado = $this->post(route('two-factor.store'), ['code' => '000000', 'trust' => '1']);

        $errado->assertSessionHasErrors('code');
        $this->assertNull($this->cookieConfiavel($errado, $usuario));
        $this->assertSame(0, TrustedDevice::query()->count());
    }

    public function test_o_prazo_e_de_14_dias_para_quem_ve_dado_de_outras_pessoas(): void
    {
        [$consultor, $segredo] = $this->comDoisPassos(['email' => 'consultor@exemplo.com', 'role' => 'consultant']);
        $this->assertTrue($consultor->mustUseTwoFactor());

        $resposta = $this->entrarComCodigo($segredo, confiar: true, email: 'consultor@exemplo.com');

        $this->assertEqualsWithDelta(14, ($this->cookieConfiavel($resposta, $consultor)->getExpiresTime() - time()) / 86400, 0.1);
        $this->assertSame(14, app(TrustedDeviceService::class)->days($consultor));
    }

    public function test_a_tela_do_codigo_oferece_a_caixa_com_o_prazo_de_cada_pessoa(): void
    {
        [, $segredo] = $this->comDoisPassos();
        $this->post(route('login.store'), ['email' => 'pessoa@exemplo.com', 'password' => 'SenhaCerta123']);

        $this->get(route('two-factor.challenge'))->assertSee('Confiar neste navegador por 30 dias')->assertSee('A senha continua sendo pedida')->assertSeeHtml('data-trust-browser');

        $this->novoDia();
        $this->comDoisPassos(['email' => 'broker@exemplo.com', 'role' => 'broker']);
        $this->post(route('login.store'), ['email' => 'broker@exemplo.com', 'password' => 'SenhaCerta123']);
        $this->get(route('two-factor.challenge'))->assertSee('Confiar neste navegador por 14 dias');
    }

    // ------------------------------------------------------------------ usar

    public function test_com_o_cookie_a_senha_basta_e_o_token_troca_sem_estender_o_prazo(): void
    {
        [$usuario, $segredo] = $this->comDoisPassos();
        $cookie1 = $this->cookieConfiavel($this->entrarComCodigo($segredo, confiar: true), $usuario);
        $antes = TrustedDevice::query()->sole();
        $this->novoDia();

        $resposta = $this->entrarSoComSenha($cookie1);

        $resposta->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($usuario);

        $cookie2 = $this->cookieConfiavel($resposta, $usuario);
        $this->assertNotNull($cookie2, 'o cookie novo vai na resposta');
        $this->assertNotSame($cookie1->getValue(), $cookie2->getValue());

        $depois = TrustedDevice::query()->sole();
        $this->assertNotSame($antes->token_hash, $depois->token_hash, 'o token trocou');
        $this->assertTrue($antes->expires_at->equalTo($depois->expires_at), 'usar não estende o prazo');
        $this->assertNotNull($depois->last_used_at);
    }

    public function test_o_cookie_ja_usado_nao_vale_de_novo(): void
    {
        [$usuario, $segredo] = $this->comDoisPassos();
        $cookie1 = $this->cookieConfiavel($this->entrarComCodigo($segredo, confiar: true), $usuario);
        $this->novoDia();
        $this->entrarSoComSenha($cookie1); // o dono usa
        $this->novoDia();

        // Quem copiou o cookie antigo cai no código.
        $this->entrarSoComSenha($cookie1)->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
    }

    public function test_senha_errada_com_o_cookie_nao_entra(): void
    {
        [$usuario, $segredo] = $this->comDoisPassos();
        $cookie = $this->cookieConfiavel($this->entrarComCodigo($segredo, confiar: true), $usuario);
        $this->novoDia();

        $this->entrarSoComSenha($cookie, 'errada')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_cookie_vencido_ou_de_outra_pessoa_nao_dispensa_o_codigo(): void
    {
        [$usuario, $segredo] = $this->comDoisPassos();
        $cookie = $this->cookieConfiavel($this->entrarComCodigo($segredo, confiar: true), $usuario);
        $this->novoDia();

        TrustedDevice::query()->update(['expires_at' => now()->subMinute()]);
        $this->entrarSoComSenha($cookie)->assertRedirect(route('two-factor.challenge'));

        // Outra pessoa com o mesmo cookie: o nome do cookie é de quem confiou, e o token só serve para ela.
        $this->novoDia();
        [$outra] = $this->comDoisPassos(['email' => 'outra@exemplo.com']);
        $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue())
            ->post(route('login.store'), ['email' => 'outra@exemplo.com', 'password' => 'SenhaCerta123'])->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
    }

    // ------------------------------------------------------------------ derrubar tudo

    public function test_trocar_a_senha_tira_todos_os_navegadores(): void
    {
        [$usuario, $segredo] = $this->comDoisPassos();
        $this->entrarComCodigo($segredo, confiar: true);
        $this->assertSame(1, TrustedDevice::query()->count());

        $this->actingAs($usuario);
        Livewire::test(ChangePassword::class)
            ->set('currentPassword', 'SenhaCerta123')->set('newPassword', 'NovaSenha456xyz')->set('newPasswordConfirmation', 'NovaSenha456xyz')
            ->call('save')->assertHasNoErrors();

        $this->assertSame(0, TrustedDevice::query()->count());
    }

    public function test_redefinir_a_senha_tira_todos_os_navegadores(): void
    {
        [$usuario, $segredo] = $this->comDoisPassos();
        $this->entrarComCodigo($segredo, confiar: true);
        $this->novoDia();

        $token = \Illuminate\Support\Facades\Password::broker()->createToken($usuario);
        $this->post(route('password.update'), ['token' => $token, 'email' => 'pessoa@exemplo.com', 'password' => 'OutraSenha789xyz', 'password_confirmation' => 'OutraSenha789xyz']);

        $this->assertSame(0, TrustedDevice::query()->count());
    }

    public function test_refazer_ou_desligar_o_segundo_passo_tira_todos_os_navegadores(): void
    {
        [$usuario, $segredo] = $this->comDoisPassos();
        $this->entrarComCodigo($segredo, confiar: true);

        $novo = $this->dois()->generateSecret();
        $this->dois()->enable($usuario, $novo, $this->dois()->codeAt($novo));
        $this->assertSame(0, TrustedDevice::query()->count(), 'refazer');

        TrustedDevice::create(['user_id' => $usuario->id, 'token_hash' => str_repeat('a', 64), 'label' => 'Chrome', 'expires_at' => now()->addDay()]);
        $this->dois()->disable($usuario);
        $this->assertSame(0, TrustedDevice::query()->count(), 'desligar');
    }

    public function test_no_maximo_dez_navegadores_por_pessoa_e_os_mais_antigos_saem(): void
    {
        [$usuario, $segredo] = $this->comDoisPassos();
        $servico = app(TrustedDeviceService::class);

        foreach (range(1, 12) as $i) {
            $servico->issue($usuario, request());
            \Illuminate\Support\Carbon::setTestNow(now()->addMinute());
        }
        \Illuminate\Support\Carbon::setTestNow();

        $this->assertSame(10, TrustedDevice::query()->where('user_id', $usuario->id)->count());
    }

    // ------------------------------------------------------------------ Minha conta

    public function test_a_lista_mostra_so_os_meus_navegadores_e_revoga_um_ou_todos(): void
    {
        [$usuario, $segredo] = $this->comDoisPassos();
        $servico = app(TrustedDeviceService::class);
        $servico->issue($usuario, request());
        $servico->issue($usuario, request());
        [$outra] = $this->comDoisPassos(['email' => 'outra@exemplo.com']);
        $servico->issue($outra, request());

        $this->actingAs($usuario);
        $primeiro = TrustedDevice::query()->where('user_id', $usuario->id)->first();

        Livewire::test(TwoFactorSettings::class)
            ->assertSeeHtml('data-trusted-browsers')
            ->assertSee('Navegadores confiáveis')
            ->assertSee('A confiança vale 30 dias')
            ->call('revokeDevice', $primeiro->id);

        $this->assertSame(1, TrustedDevice::query()->where('user_id', $usuario->id)->count());
        $this->assertSame(1, TrustedDevice::query()->where('user_id', $outra->id)->count(), 'o da outra pessoa fica');

        // Id de outra pessoa não revoga nada.
        Livewire::test(TwoFactorSettings::class)->call('revokeDevice', TrustedDevice::query()->where('user_id', $outra->id)->value('id'));
        $this->assertSame(1, TrustedDevice::query()->where('user_id', $outra->id)->count());

        Livewire::test(TwoFactorSettings::class)->call('revokeAllDevices')->assertSee('Nenhum navegador confiável.');
        $this->assertSame(0, TrustedDevice::query()->where('user_id', $usuario->id)->count());
        $this->assertSame(1, TrustedDevice::query()->where('user_id', $outra->id)->count());
    }

    public function test_a_lista_marca_o_navegador_atual_e_nao_mostra_os_vencidos(): void
    {
        [$usuario] = $this->comDoisPassos();
        $servico = app(TrustedDeviceService::class);
        $cookie = $servico->issue($usuario, request());
        TrustedDevice::create(['user_id' => $usuario->id, 'token_hash' => str_repeat('b', 64), 'label' => 'Firefox no Linux', 'expires_at' => now()->subDay()]);

        $this->actingAs($usuario);

        Livewire::withCookies([$cookie->getName() => $cookie->getValue()])->test(TwoFactorSettings::class)
            ->assertSee('este navegador')
            ->assertDontSee('Firefox no Linux');
    }

    // ------------------------------------------------------------------ "manter conectado"

    public function test_manter_conectado_dura_30_dias_e_a_tela_diz_quanto(): void
    {
        $this->get(route('login'))->assertSee('Manter conectado por 30 dias');

        User::factory()->create(['email' => 'simples@exemplo.com', 'password' => 'SenhaCerta123']);
        $resposta = $this->post(route('login.store'), ['email' => 'simples@exemplo.com', 'password' => 'SenhaCerta123', 'remember' => '1']);

        $lembranca = collect($resposta->headers->getCookies())->first(fn (Cookie $c) => str_starts_with($c->getName(), 'remember_web_'));
        $this->assertNotNull($lembranca);
        $this->assertEqualsWithDelta(30, ($lembranca->getExpiresTime() - time()) / 86400, 0.1);
    }

    public function test_a_migracao_zera_os_tokens_de_lembranca_de_400_dias(): void
    {
        $com = User::factory()->create(['remember_token' => 'token-antigo-de-400-dias']);
        $sem = User::factory()->create(['remember_token' => null]);

        (include base_path('database/migrations/2026_12_09_100100_reset_long_remember_tokens.php'))->up();

        $this->assertNull($com->fresh()->remember_token);
        $this->assertNull($sem->fresh()->remember_token);
    }

    // ------------------------------------------------------------------ nome do aparelho

    public function test_o_nome_do_aparelho_resume_o_navegador_e_o_sistema(): void
    {
        $servico = app(TrustedDeviceService::class);

        $this->assertSame('Chrome no Windows', $servico->labelFor(self::CHROME));
        $this->assertSame('Safari no iPhone ou iPad', $servico->labelFor('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1'));
        $this->assertSame('Firefox no Linux', $servico->labelFor('Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0'));
        $this->assertSame('Edge no Windows', $servico->labelFor('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0'));
        $this->assertSame('Navegador', $servico->labelFor(null));
    }
}
