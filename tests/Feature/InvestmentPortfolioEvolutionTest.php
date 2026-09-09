<?php

namespace Tests\Feature;

use App\Enums\AssetClass;
use App\Enums\PortfolioDisplayGroup;
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
 * pro card de Previdência. O resumo (getPortfolioEvolutionProperty) e o
 * gráfico de colunas (getEvolutionChartProperty, 3 lentes: Total/Grupo/
 * Ativo) compartilham a mesma base de meses+carry-forward — ver
 * InvestmentsIndex::evolutionSeries().
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

        $componente = Livewire::test(InvestmentsIndex::class);

        self::assertNull($componente->get('portfolioEvolution'));
        self::assertNull($componente->get('evolutionChart'));
    }

    public function test_soma_todos_os_ativos_por_mes_e_carrega_o_ultimo_valor_conhecido_em_mes_sem_foto(): void
    {
        // .env local roda em APP_LOCALE=en (produção roda pt_BR) — força
        // aqui pra testar o rótulo de mês que o usuário de verdade vê.
        app()->setLocale('pt_BR');

        [$perfil, $membro] = $this->criarPerfil();
        $this->actingAs($membro->user);
        app(ProfileContext::class)->set($perfil, $membro);

        $cdb = InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')->create(['asset_class' => AssetClass::Cdb, 'name' => 'CDB Banco X', 'current_amount' => '12000.00']);
        InvestmentSnapshot::create(['investment_id' => $cdb->id, 'year' => 2026, 'month' => 6, 'amount' => '10000.00']);
        InvestmentSnapshot::create(['investment_id' => $cdb->id, 'year' => 2026, 'month' => 8, 'amount' => '12000.00']);

        $tesouro = InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')->create(['asset_class' => AssetClass::Tesouro, 'name' => 'Tesouro Selic', 'current_amount' => '5000.00']);
        InvestmentSnapshot::create(['investment_id' => $tesouro->id, 'year' => 2026, 'month' => 6, 'amount' => '4000.00']);
        InvestmentSnapshot::create(['investment_id' => $tesouro->id, 'year' => 2026, 'month' => 7, 'amount' => '4500.00']);
        InvestmentSnapshot::create(['investment_id' => $tesouro->id, 'year' => 2026, 'month' => 8, 'amount' => '5000.00']);

        $componente = Livewire::test(InvestmentsIndex::class);
        $resumo = $componente->get('portfolioEvolution');
        $grafico = $componente->get('evolutionChart');

        self::assertSame('jun/2026', $resumo['desde']);
        self::assertSame('17000.00', $resumo['valorAtual']);
        self::assertSame('3000.00', $resumo['crescimentoValor']);
        self::assertEqualsWithDelta(21.43, $resumo['crescimentoPct'], 0.01);

        // jun: 10000+4000=14000 · jul: CDB sem foto carrega 10000 + 4500=14500 · ago: 12000+5000=17000
        self::assertSame(['jun/26', 'jul/26', 'ago/26'], $grafico['meses']);
        self::assertSame([14000.0, 14500.0, 17000.0], $grafico['total']);
        self::assertSame(17000.0, $grafico['maximo']);
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

        $grafico = Livewire::test(InvestmentsIndex::class)->set('viewAs', $ana->id)->get('evolutionChart');

        self::assertSame([1000.0, 2000.0], $grafico['total']);
    }

    public function test_lente_grupo_empilha_por_portfoliodisplaygroup_com_cor_fixa_e_na_ordem_do_enum(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $this->actingAs($membro->user);
        app(ProfileContext::class)->set($perfil, $membro);

        $etf = InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')->create(['asset_class' => AssetClass::Etf, 'current_amount' => '3000.00']);
        InvestmentSnapshot::create(['investment_id' => $etf->id, 'year' => 2026, 'month' => 7, 'amount' => '2000.00']);
        InvestmentSnapshot::create(['investment_id' => $etf->id, 'year' => 2026, 'month' => 8, 'amount' => '3000.00']);

        $cdb = InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')->create(['asset_class' => AssetClass::Cdb, 'current_amount' => '1000.00']);
        InvestmentSnapshot::create(['investment_id' => $cdb->id, 'year' => 2026, 'month' => 7, 'amount' => '1000.00']);
        InvestmentSnapshot::create(['investment_id' => $cdb->id, 'year' => 2026, 'month' => 8, 'amount' => '1000.00']);

        $grafico = Livewire::test(InvestmentsIndex::class)->set('evolutionLens', 'group')->get('evolutionChart');

        // FixedIncome (Cdb) vem antes de Etfs na ordem de PortfolioDisplayGroup::cases(), mesmo o CDB tendo sido criado depois.
        self::assertSame(
            [PortfolioDisplayGroup::FixedIncome, PortfolioDisplayGroup::Etfs],
            array_column($grafico['porGrupo'], 'grupo'),
        );
        self::assertSame(PortfolioDisplayGroup::FixedIncome->color(), $grafico['porGrupo'][0]['cor']);
        self::assertSame([1000.0, 1000.0], $grafico['porGrupo'][0]['valores']);
        self::assertSame([2000.0, 3000.0], $grafico['porGrupo'][1]['valores']);
        self::assertTrue($grafico['porGrupo'][0]['visivel']);
        self::assertTrue($grafico['porGrupo'][1]['visivel']);
        // escala do eixo Y é a SOMA empilhada do mês (não o maior grupo isolado): ago = 1000+3000.
        self::assertSame(4000.0, $grafico['maximo']);
    }

    public function test_clicar_na_legenda_esconde_o_grupo_e_reescala_o_eixo_pros_que_sobraram(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $this->actingAs($membro->user);
        app(ProfileContext::class)->set($perfil, $membro);

        $etf = InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')->create(['asset_class' => AssetClass::Etf, 'current_amount' => '3000.00']);
        InvestmentSnapshot::create(['investment_id' => $etf->id, 'year' => 2026, 'month' => 7, 'amount' => '2000.00']);
        InvestmentSnapshot::create(['investment_id' => $etf->id, 'year' => 2026, 'month' => 8, 'amount' => '3000.00']);

        $cdb = InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')->create(['asset_class' => AssetClass::Cdb, 'current_amount' => '1000.00']);
        InvestmentSnapshot::create(['investment_id' => $cdb->id, 'year' => 2026, 'month' => 7, 'amount' => '1000.00']);
        InvestmentSnapshot::create(['investment_id' => $cdb->id, 'year' => 2026, 'month' => 8, 'amount' => '1000.00']);

        $componente = Livewire::test(InvestmentsIndex::class)
            ->set('evolutionLens', 'group')
            ->call('toggleEvolutionGroup', PortfolioDisplayGroup::Etfs->value);

        $grafico = $componente->get('evolutionChart');

        // continua listado (pra dar pra religar), só marcado como escondido — e não entra mais na escala.
        self::assertCount(2, $grafico['porGrupo']);
        self::assertFalse($grafico['porGrupo'][1]['visivel']);
        self::assertTrue($grafico['porGrupo'][0]['visivel']);
        self::assertSame(1000.0, $grafico['maximo']); // sem o Etfs (2000/3000), só sobra o Cdb (1000/1000).

        $componente->call('toggleEvolutionGroup', PortfolioDisplayGroup::Etfs->value);
        self::assertTrue($componente->get('evolutionChart')['porGrupo'][1]['visivel']);
        self::assertSame(4000.0, $componente->get('evolutionChart')['maximo']);
    }

    public function test_lente_ativo_mostra_so_a_serie_do_ativo_escolhido_e_trocar_de_lente_limpa_a_escolha(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $this->actingAs($membro->user);
        app(ProfileContext::class)->set($perfil, $membro);

        $cdb = InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')->create(['name' => 'CDB Banco X', 'current_amount' => '1500.00']);
        InvestmentSnapshot::create(['investment_id' => $cdb->id, 'year' => 2026, 'month' => 7, 'amount' => '1000.00']);
        InvestmentSnapshot::create(['investment_id' => $cdb->id, 'year' => 2026, 'month' => 8, 'amount' => '1500.00']);

        $outro = InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')->create(['name' => 'Outro ativo', 'current_amount' => '9000.00']);
        InvestmentSnapshot::create(['investment_id' => $outro->id, 'year' => 2026, 'month' => 7, 'amount' => '9000.00']);
        InvestmentSnapshot::create(['investment_id' => $outro->id, 'year' => 2026, 'month' => 8, 'amount' => '9000.00']);

        $componente = Livewire::test(InvestmentsIndex::class)
            ->set('evolutionLens', 'asset')
            ->set('evolutionAssetId', $cdb->id);

        $grafico = $componente->get('evolutionChart');
        self::assertSame('CDB Banco X', $grafico['ativo']['nome']);
        self::assertSame([1000.0, 1500.0], $grafico['ativo']['valores']);
        self::assertSame(1500.0, $grafico['maximo']);
        self::assertCount(2, $grafico['ativosDisponiveis']);

        $componente->set('evolutionLens', 'total');
        self::assertSame('', $componente->get('evolutionAssetId'));
    }

    /**
     * Regressão: as computed properties são resolvidas na hora certa em
     * Livewire::test()->get(), mas render() passa os dados pra view por
     * uma lista explícita — esquecer de acrescentar uma nova ali não
     * quebra o ->get(), só quebra o HTML de verdade ("Undefined
     * variable"). Só um teste que troca de aba/lente de fato pega isso
     * — foi exatamente o que aconteceu em produção com $portfolioEvolution.
     */
    public function test_as_abas_e_as_tres_lentes_renderizam_sem_erro(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $this->actingAs($membro->user);
        app(ProfileContext::class)->set($perfil, $membro);

        $ativo = InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')->create(['current_amount' => '1000.00']);
        InvestmentSnapshot::create(['investment_id' => $ativo->id, 'year' => 2026, 'month' => 6, 'amount' => '800.00']);
        InvestmentSnapshot::create(['investment_id' => $ativo->id, 'year' => 2026, 'month' => 8, 'amount' => '1000.00']);

        foreach (['portfolio', 'performance', 'transactions'] as $aba) {
            Livewire::test(InvestmentsIndex::class)->call('setTab', $aba)->assertOk();
        }

        foreach (['total', 'group', 'asset'] as $lente) {
            Livewire::test(InvestmentsIndex::class)
                ->call('setTab', 'performance')
                ->set('evolutionLens', $lente)
                ->set('evolutionAssetId', $lente === 'asset' ? $ativo->id : '')
                ->assertOk();
        }

        // lente Grupo com TODOS os grupos escondidos — o "sem grupo selecionado" também precisa renderizar.
        Livewire::test(InvestmentsIndex::class)
            ->call('setTab', 'performance')
            ->set('evolutionLens', 'group')
            ->call('toggleEvolutionGroup', $ativo->displayGroup()->value)
            ->assertOk();
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
