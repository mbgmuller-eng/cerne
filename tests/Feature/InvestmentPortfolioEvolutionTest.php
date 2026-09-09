<?php

namespace Tests\Feature;

use App\Enums\AssetClass;
use App\Livewire\Investments\InvestmentsIndex;
use App\Models\FinancialProfile;
use App\Models\InvestmentRecord;
use App\Models\InvestmentSnapshot;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Evolução do patrimônio" (aba Performance) nasceu do histórico
 * importado do Hugo (18 meses de InvestmentSnapshot vindos do CSV que
 * ele já controlava em planilha) — antes disso a foto mensal só existia
 * pro card de Previdência. Testa a soma mês a mês, o carry-forward de
 * mês sem foto, e que respeita a mesma aba de privacidade da listagem.
 */
class InvestmentPortfolioEvolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_nula_quando_tem_menos_de_dois_meses_de_foto(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $this->actingAs($membro->user);
        app(ProfileContext::class)->set($perfil, $membro);

        $ativo = InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')->create([
            'current_amount' => '1000.00',
        ]);
        InvestmentSnapshot::create(['investment_id' => $ativo->id, 'year' => 2026, 'month' => 8, 'amount' => '1000.00']);

        $evolucao = Livewire::test(InvestmentsIndex::class)->get('portfolioEvolution');

        self::assertNull($evolucao);
    }

    public function test_soma_todos_os_ativos_por_mes_e_carrega_o_ultimo_valor_conhecido_em_mes_sem_foto(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $this->actingAs($membro->user);
        app(ProfileContext::class)->set($perfil, $membro);

        $cdb = InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')->create(['asset_class' => AssetClass::Cdb, 'current_amount' => '12000.00']);
        InvestmentSnapshot::create(['investment_id' => $cdb->id, 'year' => 2026, 'month' => 6, 'amount' => '10000.00']);
        InvestmentSnapshot::create(['investment_id' => $cdb->id, 'year' => 2026, 'month' => 8, 'amount' => '12000.00']);

        $tesouro = InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')->create(['asset_class' => AssetClass::Tesouro, 'current_amount' => '5000.00']);
        InvestmentSnapshot::create(['investment_id' => $tesouro->id, 'year' => 2026, 'month' => 6, 'amount' => '4000.00']);
        InvestmentSnapshot::create(['investment_id' => $tesouro->id, 'year' => 2026, 'month' => 7, 'amount' => '4500.00']);
        InvestmentSnapshot::create(['investment_id' => $tesouro->id, 'year' => 2026, 'month' => 8, 'amount' => '5000.00']);

        $evolucao = Livewire::test(InvestmentsIndex::class)->get('portfolioEvolution');

        // jun: 10000+4000=14000 · jul: CDB sem foto carrega 10000 + 4500=14500 · ago: 12000+5000=17000
        self::assertSame([14000.0, 14500.0, 17000.0], $evolucao['pontos']);
        self::assertSame('Jun/2026', $evolucao['desde']);
        self::assertSame('17000.00', $evolucao['valorAtual']);
        self::assertSame('3000.00', $evolucao['crescimentoValor']);
        self::assertEqualsWithDelta(21.43, $evolucao['crescimentoPct'], 0.01);
    }

    public function test_respeita_a_aba_de_privacidade_e_soma_so_do_membro_selecionado(): void
    {
        $usuarioAna = User::factory()->create();
        $perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $usuarioAna->id]);
        $ana = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuarioAna->id, 'name' => 'Ana']);
        $usuarioBruno = User::factory()->create();
        $bruno = ProfileMember::factory()->secondary()->create(['profile_id' => $perfil->id, 'user_id' => $usuarioBruno->id, 'name' => 'Bruno']);

        $this->actingAs($usuarioAna);
        app(ProfileContext::class)->set($perfil, $ana);

        $deAna = InvestmentRecord::factory()->for($perfil, 'profile')->for($ana, 'member')->create(['current_amount' => '2000.00', 'is_private' => true]);
        InvestmentSnapshot::create(['investment_id' => $deAna->id, 'year' => 2026, 'month' => 7, 'amount' => '1000.00']);
        InvestmentSnapshot::create(['investment_id' => $deAna->id, 'year' => 2026, 'month' => 8, 'amount' => '2000.00']);

        $deBruno = InvestmentRecord::factory()->for($perfil, 'profile')->for($bruno, 'member')->create(['current_amount' => '9000.00']);
        InvestmentSnapshot::create(['investment_id' => $deBruno->id, 'year' => 2026, 'month' => 7, 'amount' => '8000.00']);
        InvestmentSnapshot::create(['investment_id' => $deBruno->id, 'year' => 2026, 'month' => 8, 'amount' => '9000.00']);

        $vistaDeAna = Livewire::test(InvestmentsIndex::class)->set('viewAs', $ana->id)->get('portfolioEvolution');

        self::assertSame([1000.0, 2000.0], $vistaDeAna['pontos']);
    }

    /**
     * @return array{0: FinancialProfile, 1: ProfileMember}
     */
    private function criarPerfil(): array
    {
        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id]);

        return [$perfil, $membro];
    }
}
