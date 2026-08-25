<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Comportamento do ProfileScope (CLAUDE.md, regra 1): o MySQL não tem RLS,
 * então este escopo global é a única coisa que impede uma query de um
 * perfil devolver dado financeiro de outro.
 */
class TenancyIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_perfil_so_enxerga_as_proprias_contas(): void
    {
        [$perfilA, $contaA] = $this->criarPerfilComConta();
        [, $contaB] = $this->criarPerfilComConta();

        app(ProfileContext::class)->set($perfilA);

        $visiveis = BankAccount::all();

        self::assertTrue($visiveis->contains($contaA));
        self::assertFalse($visiveis->contains($contaB));
    }

    public function test_nao_consegue_buscar_registro_de_outro_perfil_pelo_id(): void
    {
        [$perfilA] = $this->criarPerfilComConta();
        [, $contaB] = $this->criarPerfilComConta();

        app(ProfileContext::class)->set($perfilA);

        self::assertNull(BankAccount::find($contaB->id));
    }

    /**
     * O escopo falha fechado: sem perfil ativo (comando de console, job
     * sem contexto, requisição não autenticada), a query devolve zero
     * linhas — nunca "todas". O contrário vazaria dado de todo mundo.
     */
    public function test_sem_perfil_ativo_nenhuma_linha_e_visivel(): void
    {
        $this->criarPerfilComConta();
        $this->criarPerfilComConta();

        app(ProfileContext::class)->clear();

        self::assertCount(0, BankAccount::all());
    }

    /**
     * withoutProfileScope() é a válvula de escape documentada para jobs de
     * manutenção — precisa continuar atravessando o isolamento de propósito.
     */
    public function test_without_profile_scope_atravessa_o_isolamento_deliberadamente(): void
    {
        [$perfilA] = $this->criarPerfilComConta();
        app(ProfileContext::class)->set($perfilA);
        $this->criarPerfilComConta();

        self::assertCount(2, BankAccount::withoutProfileScope()->get());
    }

    /** @return array{0: FinancialProfile, 1: BankAccount} */
    private function criarPerfilComConta(): array
    {
        $perfil = FinancialProfile::factory()->create();
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id]);
        $conta = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')->create();

        return [$perfil, $conta];
    }
}
