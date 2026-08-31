<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Bootstrap de consultor via `cerne:criar-consultor` — o único jeito de
 * entrar um consultor hoje, já que esse papel não tem autocadastro nem
 * convite (só cliente aceita convite, ver AcceptInviteController).
 */
class CreateConsultantCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_consultor_com_senha_valida(): void
    {
        $this->artisan('cerne:criar-consultor', [
            '--name' => 'Marcelo Müller',
            '--email' => 'marcelo@aprenderparainvestir.com.br',
        ])
            ->expectsQuestion('Senha do consultor (mín. 8 caracteres, com letra e número)', 'Senha123')
            ->expectsQuestion('Confirme a senha', 'Senha123')
            ->assertSuccessful();

        $consultor = User::where('email', 'marcelo@aprenderparainvestir.com.br')->first();

        self::assertNotNull($consultor);
        self::assertSame(UserRole::Consultant, $consultor->role);
        self::assertTrue($consultor->is_active);
        self::assertNotNull($consultor->email_verified_at);
        self::assertTrue(Hash::check('Senha123', $consultor->password));
    }

    public function test_pede_nome_e_email_quando_nao_vem_por_opcao(): void
    {
        $this->artisan('cerne:criar-consultor')
            ->expectsQuestion('Nome do consultor', 'Marina Alencar')
            ->expectsQuestion('E-mail do consultor', 'marina@teste.com')
            ->expectsQuestion('Senha do consultor (mín. 8 caracteres, com letra e número)', 'Senha123')
            ->expectsQuestion('Confirme a senha', 'Senha123')
            ->assertSuccessful();

        self::assertNotNull(User::where('email', 'marina@teste.com')->first());
    }

    public function test_falha_se_email_ja_existe(): void
    {
        User::factory()->create(['email' => 'ja-existe@teste.com']);

        $this->artisan('cerne:criar-consultor', [
            '--name' => 'Fulano',
            '--email' => 'ja-existe@teste.com',
        ])->assertFailed();

        self::assertSame(1, User::where('email', 'ja-existe@teste.com')->count());
    }

    public function test_falha_se_confirmacao_de_senha_nao_confere(): void
    {
        $this->artisan('cerne:criar-consultor', [
            '--name' => 'Fulano',
            '--email' => 'fulano@teste.com',
        ])
            ->expectsQuestion('Senha do consultor (mín. 8 caracteres, com letra e número)', 'Senha123')
            ->expectsQuestion('Confirme a senha', 'OutraSenha123')
            ->assertFailed();

        self::assertNull(User::where('email', 'fulano@teste.com')->first());
    }
}
