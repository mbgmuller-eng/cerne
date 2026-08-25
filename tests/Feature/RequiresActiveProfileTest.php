<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Consultor sem cliente aberto navegando direto pela URL (sem passar
 * pela Carteira) não pode cair num 404 cru — precisa voltar pra Carteira,
 * de onde escolhe um cliente. Bug real: reproduzido acessando
 * /investimentos logo após login como consultor.
 */
class RequiresActiveProfileTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{string}> */
    public static function rotasComPerfilObrigatorio(): iterable
    {
        yield 'fluxo de caixa' => ['/fluxo-de-caixa'];
        yield 'contas fixas' => ['/contas-fixas'];
        yield 'investimentos' => ['/investimentos'];
        yield 'seguros' => ['/seguros'];
        yield 'objetivos' => ['/objetivos'];
        yield 'importar' => ['/importar'];
        yield 'contas' => ['/contas'];
    }

    #[DataProvider('rotasComPerfilObrigatorio')]
    public function test_consultor_sem_cliente_aberto_e_redirecionado_para_a_carteira(string $rota): void
    {
        $consultor = User::factory()->create(['role' => UserRole::Consultant]);

        $this->actingAs($consultor)
            ->get($rota)
            ->assertRedirect(route('consultant.portfolio'));
    }

    public function test_cliente_sem_perfil_ativo_continua_vendo_404(): void
    {
        $cliente = User::factory()->create(['role' => UserRole::Client]);

        $this->actingAs($cliente)
            ->get('/investimentos')
            ->assertNotFound();
    }
}
