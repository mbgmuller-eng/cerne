<?php

namespace Tests\Feature;

use App\Enums\AssetClass;
use App\Enums\InvestmentSector;
use App\Enums\MemberRole;
use App\Enums\TransactionType;
use App\Livewire\Investments\InvestmentPerformance;
use App\Models\FinancialProfile;
use App\Models\InvestmentPortfolioMonth;
use App\Models\InvestmentRecord;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\InvestmentPerformanceService;
use App\Services\InvestmentTransactionService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Gráficos de desempenho dos investimentos: carteira mês a mês contra o CDI, composição e proventos.
 */
class InvestmentPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private FinancialProfile $perfil;

    private ProfileMember $membro;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-16 09:00:00'));

        $this->usuario = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->usuario->id]);
        $this->membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuario->id, 'name' => 'Marcelo', 'role' => MemberRole::Primary]);
        $this->actingAs($this->usuario);
        app(ProfileContext::class)->set($this->perfil, $this->membro);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function mes(int $ano, int $mes, array $campos, ?ProfileMember $dono = null): InvestmentPortfolioMonth
    {
        return InvestmentPortfolioMonth::create(array_merge(['profile_id' => $this->perfil->id, 'member_id' => ($dono ?? $this->membro)->id, 'institution' => 'BTG Pactual', 'year' => $ano, 'month' => $mes], $campos));
    }

    private function ativo(string $nome, AssetClass $classe, string $valor, ?ProfileMember $dono = null): InvestmentRecord
    {
        return InvestmentRecord::factory()->for($this->perfil, 'profile')->for($dono ?? $this->membro, 'member')->create([
            'name' => $nome, 'ticker' => strtoupper(substr($nome, 0, 5)), 'asset_class' => $classe, 'sector' => InvestmentSector::VariableIncome,
            'current_amount' => $valor, 'quantity' => null,
        ]);
    }

    private function provento(InvestmentRecord $ativo, TransactionType $tipo, string $bruto, string $imposto, string $data, ?ProfileMember $dono = null): void
    {
        app(InvestmentTransactionService::class)->registerIncome($ativo, $tipo, $bruto, $imposto, CarbonImmutable::parse($data), null, $this->usuario->id);
    }

    public function test_rentabilidade_acumulada_e_composta_e_comparada_ao_cdi(): void
    {
        $this->mes(2026, 1, ['return_percent' => '1.0000', 'cdi_percent' => '1.0000']);
        $this->mes(2026, 2, ['return_percent' => '2.0000', 'cdi_percent' => '0.9000']);
        $this->mes(2026, 3, ['return_percent' => '-1.0000', 'cdi_percent' => '1.1000']);

        $c = app(InvestmentPerformanceService::class)->forPeriod(null, 12)['portfolio'];

        // 1,01 x 1,02 x 0,99 = 1,019898 ; CDI: 1,01 x 1,009 x 1,011 = 1,030340...
        self::assertSame(['jan/26', 'fev/26', 'mar/26'], $c['months']);
        self::assertSame('1.99', $c['summary']['return']);
        self::assertSame('3.03', $c['summary']['cdi']);
        self::assertSame('66', $c['summary']['percent_of_cdi']);
        self::assertSame(1, $c['summary']['months_above_cdi'] - 0);
        self::assertSame(3, $c['summary']['months_compared']);
        self::assertSame('2.0000', $c['summary']['best']['value']);
        self::assertSame('mar/26', $c['summary']['worst']['label']);
        self::assertEqualsWithDelta(1.0, $c['cumulative_return'][0], 0.0001);
        self::assertEqualsWithDelta(1.9898, $c['cumulative_return'][2], 0.0001);
    }

    public function test_janela_em_meses_conta_do_ultimo_mes_com_dado(): void
    {
        foreach (range(1, 6) as $m) {
            $this->mes(2026, $m, ['return_percent' => '1.0000', 'cdi_percent' => '1.0000', 'closing_balance' => (string) (1000 * $m).'.00']);
        }

        $c = app(InvestmentPerformanceService::class)->forPeriod(null, 3)['portfolio'];

        self::assertSame(['abr/26', 'mai/26', 'jun/26'], $c['months']);
        self::assertSame(['abr/26', 'mai/26', 'jun/26'], $c['patrimony']['labels']);
        self::assertSame('4000.00', $c['patrimony']['first']);
        self::assertSame('6000.00', $c['patrimony']['last']);
        self::assertSame('3.03', $c['summary']['return']);
    }

    public function test_mes_sem_saldo_fica_fora_do_patrimonio_mas_a_rentabilidade_continua(): void
    {
        $this->mes(2025, 1, ['return_percent' => '0.5000', 'cdi_percent' => '1.0000']);
        $this->mes(2026, 1, ['return_percent' => '1.0000', 'cdi_percent' => '1.0000', 'closing_balance' => '5000.00', 'gain' => '50.00']);

        $c = app(InvestmentPerformanceService::class)->forPeriod(null, 0)['portfolio'];

        // O eixo é o calendário: de jan/25 a jan/26 são 13 meses, com buraco onde não há dado.
        self::assertCount(13, $c['months']);
        self::assertSame(['jan/26'], $c['patrimony']['labels']);
        self::assertSame(50.0, $c['gain'][12]);
        self::assertNull($c['gain'][0]);
        self::assertSame('50.00', $c['summary']['gain_total']);
        // O acumulado ignora o que ficou antes do buraco: só jan/26.
        self::assertSame('1.00', $c['summary']['return']);
        self::assertSame(1, $c['summary']['months_compared']);
        self::assertSame('jan/26', $c['summary']['sequence_start']);
    }

    public function test_lacuna_no_relatorio_da_corretora_interrompe_o_acumulado(): void
    {
        $this->mes(2026, 1, ['return_percent' => '37.0000', 'cdi_percent' => '1.0000']);
        $this->mes(2026, 2, ['return_percent' => '1.0000', 'cdi_percent' => '1.0000']);
        // março sem informação
        $this->mes(2026, 4, ['return_percent' => '2.0000', 'cdi_percent' => '1.0000']);
        $this->mes(2026, 5, ['return_percent' => '1.0000', 'cdi_percent' => '1.0000']);

        $c = app(InvestmentPerformanceService::class)->forPeriod(null, 12)['portfolio'];

        self::assertSame(['jan/26', 'fev/26', 'mar/26', 'abr/26', 'mai/26'], $c['months']);
        self::assertNull($c['cumulative_return'][0]);
        self::assertNull($c['cumulative_return'][2]);
        self::assertSame(37.0, $c['monthly_return'][0], 'a barra do mês anterior ao buraco continua aparecendo');
        // 1,02 x 1,01 = 3,02%: o 37% de janeiro não entra no acumulado.
        self::assertSame('3.02', $c['summary']['return']);
        self::assertSame(2, $c['summary']['months_compared']);
        self::assertSame('abr/26', $c['summary']['sequence_start']);
        self::assertSame('2.0000', $c['summary']['best']['value']);
    }

    public function test_mes_sem_cdi_nao_entra_na_comparacao(): void
    {
        $this->mes(2026, 1, ['return_percent' => '1.0000', 'cdi_percent' => null]);
        $this->mes(2026, 2, ['return_percent' => '2.0000', 'cdi_percent' => '1.0000']);

        $c = app(InvestmentPerformanceService::class)->forPeriod(null, 12)['portfolio'];

        self::assertSame(1, $c['summary']['months_compared']);
        self::assertSame('2.00', $c['summary']['return']);
        self::assertNull($c['cumulative_return'][0]);
        self::assertSame(1.0, $c['monthly_return'][0], 'a barra mensal mostra o mês mesmo sem CDI');
    }

    public function test_composicao_agrupa_por_grupo_e_ignora_inativos_e_zerados(): void
    {
        $this->ativo('Acao A', AssetClass::Acao, '600.00');
        $this->ativo('Fii B', AssetClass::Fii, '200.00');
        $this->ativo('Tesouro C', AssetClass::Tesouro, '200.00');
        $this->ativo('Zerado', AssetClass::Acao, '0.00');
        $this->ativo('Encerrado', AssetClass::Acao, '900.00')->update(['is_active' => false]);

        $a = app(InvestmentPerformanceService::class)->forPeriod(null, 12)['allocation'];

        self::assertSame('1000.00', $a['total']);
        self::assertSame(['Ações e FIIs', 'Renda fixa'], array_column($a['slices'], 'label'));
        self::assertSame(80.0, $a['slices'][0]['pct']);
        self::assertSame('800.00', $a['slices'][0]['value']);
        self::assertStringContainsString('Acao A', $a['slices'][0]['assets'][0]['name']);
    }

    public function test_proventos_somam_o_liquido_no_mes_do_pagamento(): void
    {
        $acao = $this->ativo('Acao A', AssetClass::Acao, '100.00');
        $fii = $this->ativo('Fii B', AssetClass::Fii, '100.00');

        $this->provento($acao, TransactionType::Jcp, '10.00', '1.50', '2026-09-30');
        $this->provento($acao, TransactionType::Dividend, '20.00', '0.00', '2026-09-10');
        $this->provento($fii, TransactionType::Dividend, '5.00', '0.00', '2026-10-08');
        $this->provento($fii, TransactionType::Dividend, '5.00', '0.00', '2025-01-08'); // fora de 12 meses

        $p = app(InvestmentPerformanceService::class)->forPeriod(null, 12)['incomes'];

        self::assertSame('33.50', $p['total']);
        self::assertCount(12, $p['months']);
        self::assertSame('nov/25', $p['months'][0]);
        self::assertSame('out/26', $p['months'][11]);
        self::assertSame([20.0, 5.0], [$p['dividends'][10], $p['dividends'][11]]);
        self::assertSame(8.5, $p['jcp'][10]);
        self::assertStringContainsString('Acao A', $p['by_asset'][0]['name']);
        self::assertSame('28.50', $p['by_asset'][0]['total']);

        $tudo = app(InvestmentPerformanceService::class)->forPeriod(null, 0)['incomes'];
        self::assertSame('38.50', $tudo['total']);
        self::assertSame('jan/25', $tudo['months'][0]);
    }

    public function test_filtro_por_pessoa_separa_os_dados(): void
    {
        $outra = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => null, 'name' => 'Helen', 'role' => MemberRole::Secondary]);
        $this->mes(2026, 1, ['return_percent' => '1.0000', 'cdi_percent' => '1.0000', 'closing_balance' => '1000.00']);
        $this->mes(2026, 1, ['return_percent' => '3.0000', 'cdi_percent' => '1.0000', 'closing_balance' => '500.00'], $outra);
        $this->ativo('Dele', AssetClass::Acao, '100.00');
        $this->ativo('Dela', AssetClass::Acao, '300.00', $outra);

        $servico = app(InvestmentPerformanceService::class);

        self::assertSame('400.00', $servico->forPeriod(null, 12)['allocation']['total']);
        self::assertSame('300.00', $servico->forPeriod($outra->id, 12)['allocation']['total']);
        self::assertSame('1500.00', $servico->forPeriod(null, 12)['portfolio']['patrimony']['last'], 'o mês com duas linhas soma os saldos');
        self::assertSame('500.00', $servico->forPeriod($outra->id, 12)['portfolio']['patrimony']['last']);
        // Ponderada pela abertura (sem abertura, peso igual): (1 + 3) / 2.
        self::assertSame('2.00', $servico->forPeriod(null, 12)['portfolio']['summary']['return']);
    }

    public function test_a_tela_renderiza_os_graficos_e_o_corretor_nao_entra(): void
    {
        $this->mes(2026, 9, ['return_percent' => '1.0000', 'cdi_percent' => '1.0000', 'closing_balance' => '1000.00', 'gain' => '10.00']);
        $this->mes(2026, 10, ['return_percent' => '1.5000', 'cdi_percent' => '1.0000', 'closing_balance' => '1015.00', 'gain' => '15.00']);
        $acao = $this->ativo('Acao A', AssetClass::Acao, '1015.00');
        $this->provento($acao, TransactionType::Dividend, '12.00', '0.00', '2026-10-05');

        Livewire::test(InvestmentPerformance::class)
            ->assertSee('Evolução do patrimônio')
            ->assertSee('Rentabilidade acumulada contra o CDI')
            ->assertSee('Composição da carteira')
            ->assertSee('Quem mais pagou')
            ->assertSee('Acao A')
            ->set('periodo', '24')
            ->assertSee('Proventos recebidos por mês')
            ->set('periodo', 'qualquer')
            ->assertOk();

        $this->get(route('investments.performance'))->assertOk()->assertSee('Desempenho');
    }

    public function test_a_aba_performance_do_investimentos_mostra_os_graficos_da_carteira_importada(): void
    {
        Livewire::test(\App\Livewire\Investments\InvestmentsIndex::class)
            ->call('setTab', 'performance')
            ->assertSee('Nenhuma rentabilidade registrada')
            ->assertDontSee('Rentabilidade acumulada contra o CDI');

        $this->mes(2026, 9, ['return_percent' => '1.0000', 'cdi_percent' => '1.0000', 'closing_balance' => '1000.00']);
        $this->mes(2026, 10, ['return_percent' => '1.5000', 'cdi_percent' => '1.0000', 'closing_balance' => '1015.00']);

        Livewire::test(\App\Livewire\Investments\InvestmentsIndex::class)
            ->call('setTab', 'performance')
            ->assertSee('Rentabilidade acumulada contra o CDI')
            ->assertSee('Escolher período e pessoa')
            ->assertDontSee('Nenhuma rentabilidade registrada');
    }

    public function test_sem_dados_mostra_o_aviso(): void
    {
        Livewire::test(InvestmentPerformance::class)->assertSee('Ainda não há dados de investimentos');
    }

    public function test_dados_de_outro_perfil_nao_aparecem(): void
    {
        $outroUsuario = User::factory()->create();
        $outroPerfil = FinancialProfile::factory()->create(['owner_user_id' => $outroUsuario->id]);
        $outroMembro = ProfileMember::factory()->create(['profile_id' => $outroPerfil->id, 'user_id' => $outroUsuario->id, 'name' => 'Outro', 'role' => MemberRole::Primary]);
        InvestmentPortfolioMonth::create(['profile_id' => $outroPerfil->id, 'member_id' => $outroMembro->id, 'institution' => 'XP', 'year' => 2026, 'month' => 9, 'closing_balance' => '99999.00', 'return_percent' => '9.0000', 'cdi_percent' => '1.0000']);
        InvestmentRecord::factory()->for($outroPerfil, 'profile')->for($outroMembro, 'member')->create(['asset_class' => AssetClass::Acao, 'sector' => InvestmentSector::VariableIncome, 'current_amount' => '88888.00', 'quantity' => null]);

        $dados = app(InvestmentPerformanceService::class)->forPeriod(null, 0);

        self::assertFalse($dados['has_data']);
        self::assertSame([], $dados['portfolio']['months']);
        self::assertSame('0.00', $dados['allocation']['total']);
    }
}
