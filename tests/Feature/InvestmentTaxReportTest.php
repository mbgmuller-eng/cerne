<?php

namespace Tests\Feature;

use App\Enums\AssetClass;
use App\Enums\InvestmentSector;
use App\Enums\MemberRole;
use App\Enums\TransactionType;
use App\Livewire\Investments\InvestmentTaxReport;
use App\Models\FinancialProfile;
use App\Models\InvestmentRecord;
use App\Models\InvestmentTransaction;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\InvestmentTaxReportService;
use App\Services\InvestmentTransactionService;
use App\Support\PositionReplay;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Apoio ao imposto de renda dos investimentos: posição em 31/12, proventos e vendas do ano.
 */
class InvestmentTaxReportTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private FinancialProfile $perfil;

    private ProfileMember $membro;

    private InvestmentTransactionService $servico;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-10 09:00:00'));

        $this->usuario = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->usuario->id]);
        $this->membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuario->id, 'name' => 'Marcelo', 'role' => MemberRole::Primary]);
        $this->actingAs($this->usuario);
        app(ProfileContext::class)->set($this->perfil, $this->membro);

        $this->servico = app(InvestmentTransactionService::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function ativo(string $nome, AssetClass $classe = AssetClass::Acao, ?ProfileMember $dono = null): InvestmentRecord
    {
        return InvestmentRecord::factory()->for($this->perfil, 'profile')->for($dono ?? $this->membro, 'member')->create([
            'name' => $nome, 'ticker' => strtoupper(substr($nome, 0, 5)), 'asset_class' => $classe, 'sector' => InvestmentSector::VariableIncome,
            'current_amount' => '1000.00', 'quantity' => null,
        ]);
    }

    private function compra(InvestmentRecord $a, string $qtd, string $preco, string $data, ?string $taxa = null): void
    {
        $this->servico->record($a, [
            'type' => TransactionType::Buy, 'quantity' => $qtd, 'unit_price' => $preco, 'total_amount' => bcmul($qtd, $preco, 2),
            'broker_fee' => $taxa, 'operation_date' => CarbonImmutable::parse($data),
        ], $this->usuario->id);
    }

    private function venda(InvestmentRecord $a, string $qtd, string $preco, string $data, ?string $taxa = null): void
    {
        $this->servico->record($a->refresh(), [
            'type' => TransactionType::Sell, 'quantity' => $qtd, 'unit_price' => $preco, 'total_amount' => bcmul($qtd, $preco, 2),
            'broker_fee' => $taxa, 'operation_date' => CarbonImmutable::parse($data),
        ], $this->usuario->id);
    }

    private function relatorio(int $ano, ?string $membroId = null): array
    {
        return app(InvestmentTaxReportService::class)->forYear($ano, $membroId);
    }

    // ---- o cálculo

    public function test_o_calculo_em_memoria_chega_a_mesma_posicao_que_o_servico(): void
    {
        $a = $this->ativo('Petro');
        $this->compra($a, '100', '10.00', '2025-02-01', '5');
        $this->compra($a, '50', '16.00', '2025-04-01');
        $this->venda($a, '30', '20.00', '2025-06-01', '2');
        $this->servico->registerRatio($a->refresh(), TransactionType::Split, '1', '2', CarbonImmutable::parse('2025-07-01'), $this->usuario->id);
        $this->servico->registerIncome($a->refresh(), TransactionType::Amortization, '120.00', '0', CarbonImmutable::parse('2025-08-01'), null, $this->usuario->id);
        $this->compra($a->refresh(), '10', '7.00', '2025-09-01');

        $replay = new PositionReplay;
        InvestmentTransaction::withoutProfileScope()->where('investment_id', $a->id)->orderBy('operation_date')->orderBy('created_at')->get()->each(fn ($tx) => $replay->apply($tx));

        $a->refresh();
        self::assertSame($a->quantity, $replay->quantity);
        self::assertSame($a->average_price, $replay->averagePrice);
        self::assertSame($a->invested_amount, $replay->cost());
    }

    public function test_posicao_em_31_12_e_a_do_ano_anterior_pelo_custo(): void
    {
        $a = $this->ativo('Petro');
        $this->compra($a, '100', '10.00', '2024-05-01'); // custo 1.000 no fim de 2024
        $this->compra($a, '100', '20.00', '2025-05-01'); // 3.000 / 200 = 15 no fim de 2025
        $this->compra($a, '100', '30.00', '2026-01-10'); // fora de 2025

        $r = $this->relatorio(2025);

        self::assertCount(1, $r['positions']);
        $p = $r['positions'][0];
        self::assertSame('200.000000', $p['quantity']);
        self::assertSame('15.000000', $p['average_price']);
        self::assertSame('3000.00', $p['cost']);
        self::assertSame('1000.00', $p['previous_cost']);
        self::assertSame('3000.00', $r['positions_cost']);
        self::assertSame('1000.00', $r['previous_cost']);
    }

    public function test_ativo_vendido_inteiro_no_ano_aparece_so_com_o_custo_do_ano_anterior(): void
    {
        $a = $this->ativo('Petro');
        $this->compra($a, '100', '10.00', '2024-05-01');
        $this->venda($a, '100', '12.00', '2025-03-01');

        $r = $this->relatorio(2025);

        self::assertSame('0.00', $r['positions'][0]['cost']);
        self::assertSame('1000.00', $r['positions'][0]['previous_cost']);
    }

    public function test_ativo_sem_posicao_nem_movimento_do_ano_fica_fora(): void
    {
        $a = $this->ativo('Petro');
        $this->compra($a, '10', '10.00', '2020-01-01');
        $this->venda($a, '10', '11.00', '2020-06-01');

        self::assertFalse($this->relatorio(2025)['has_data']);
    }

    public function test_venda_apura_o_resultado_sobre_o_custo_medio_e_as_taxas(): void
    {
        $a = $this->ativo('Petro');
        $this->compra($a, '100', '10.00', '2025-01-10'); // PM 10
        $this->venda($a, '40', '15.00', '2025-02-10', '10'); // 600 bruto, 590 líquido; custo 400

        $m = $this->relatorio(2025)['months'][0];

        self::assertSame(2, $m['month']);
        self::assertSame('190.00', $m['sales'][0]['result']);
        self::assertSame('400.00', $m['sales'][0]['cost']);
        self::assertSame('590.00', $m['sales'][0]['net']);
        self::assertSame('600.00', $m['groups'][0]['sold']);
    }

    public function test_limite_de_isencao_por_classe_e_por_mes(): void
    {
        $acao = $this->ativo('Petro');
        $fii = $this->ativo('Logistico', AssetClass::Fii);
        $cripto = $this->ativo('Bitcoin', AssetClass::Cripto);
        foreach ([$acao, $fii, $cripto] as $a) {
            $this->compra($a, '10000', '1.00', '2025-01-02');
        }

        $this->venda($acao, '1000', '20.00', '2025-03-05'); // 20.000: no limite
        $this->venda($acao, '1', '25.00', '2025-03-20'); // passa para 20.025: acima
        $this->venda($acao, '500', '20.00', '2025-04-05'); // 10.000 em abril: dentro
        $this->venda($fii, '100', '30.00', '2025-04-06');
        $this->venda($cripto, '1000', '30.00', '2025-04-07'); // 30.000: dentro dos 35 mil

        $meses = collect($this->relatorio(2025)['months'])->keyBy('month');
        $porGrupo = fn (int $mes) => collect($meses[$mes]['groups'])->keyBy('key');

        self::assertSame('20025.00', $porGrupo(3)['acao']['sold']);
        self::assertFalse($porGrupo(3)['acao']['exempt']);
        self::assertTrue($porGrupo(4)['acao']['exempt']);
        self::assertTrue($porGrupo(4)['cripto']['exempt']);
        self::assertNull($porGrupo(4)['fii']['limit'], 'FII não tem isenção');
        self::assertFalse($porGrupo(4)['fii']['exempt']);
    }

    public function test_exatamente_no_limite_ainda_e_isento(): void
    {
        $a = $this->ativo('Petro');
        $this->compra($a, '1000', '10.00', '2025-01-02');
        $this->venda($a, '1000', '20.00', '2025-03-05');

        self::assertTrue($this->relatorio(2025)['months'][0]['groups'][0]['exempt']);
    }

    public function test_proventos_somam_por_ativo_e_natureza_e_fii_vira_rendimento_de_fundo(): void
    {
        $acao = $this->ativo('Petro');
        $fii = $this->ativo('Logistico', AssetClass::Fii);
        $this->compra($acao, '100', '10.00', '2024-01-02');
        $this->compra($fii, '100', '10.00', '2024-01-02');

        $this->servico->registerIncome($acao, TransactionType::Dividend, '50.00', '0', CarbonImmutable::parse('2025-03-01'), null, $this->usuario->id);
        $this->servico->registerIncome($acao->refresh(), TransactionType::Dividend, '30.00', '0', CarbonImmutable::parse('2025-06-01'), null, $this->usuario->id);
        $this->servico->registerIncome($acao->refresh(), TransactionType::Jcp, '100.00', '15.00', CarbonImmutable::parse('2025-06-01'), null, $this->usuario->id);
        $this->servico->registerIncome($fii, TransactionType::Dividend, '80.00', '0', CarbonImmutable::parse('2025-06-10'), null, $this->usuario->id);
        $this->servico->registerIncome($fii->refresh(), TransactionType::Amortization, '20.00', '0', CarbonImmutable::parse('2025-07-10'), null, $this->usuario->id);
        $this->servico->registerIncome($acao->refresh(), TransactionType::Dividend, '999.00', '0', CarbonImmutable::parse('2024-12-30'), null, $this->usuario->id); // outro ano

        $r = $this->relatorio(2025);
        $linhas = collect($r['incomes'])->mapWithKeys(fn ($l) => [$l['investment']->name.'|'.$l['kind'] => $l]);

        self::assertSame('80.00', $linhas['Petro|dividend']['gross'], '50 + 30');
        self::assertSame('100.00', $linhas['Petro|jcp']['gross']);
        self::assertSame('15.00', $linhas['Petro|jcp']['tax']);
        self::assertSame('85.00', $linhas['Petro|jcp']['net']);
        self::assertSame('80.00', $linhas['Logistico|fii']['gross'], 'dividendo de FII é rendimento de fundo');
        self::assertSame('20.00', $linhas['Logistico|amortization']['net']);
        self::assertSame('Rendimentos de FII e fundos', $r['income_totals']['fii']['label']);
        self::assertSame('80.00', $r['income_totals']['dividend']['net']);
        self::assertCount(4, $r['incomes']);
    }

    public function test_filtra_por_pessoa_e_so_enxerga_o_perfil_ativo(): void
    {
        $conjuge = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => null, 'name' => 'Ana']);
        $meu = $this->ativo('Petro');
        $dela = $this->ativo('Vale', AssetClass::Acao, $conjuge);
        $this->compra($meu, '10', '10.00', '2025-01-02');
        $this->compra($dela, '20', '10.00', '2025-01-02');

        $outroPerfil = FinancialProfile::factory()->create();
        $outroMembro = ProfileMember::factory()->create(['profile_id' => $outroPerfil->id]);
        $alheio = InvestmentRecord::factory()->create(['profile_id' => $outroPerfil->id, 'member_id' => $outroMembro->id, 'asset_class' => AssetClass::Acao, 'sector' => InvestmentSector::VariableIncome, 'name' => 'Alheio', 'quantity' => null]);
        $this->servico->record($alheio, ['type' => TransactionType::Buy, 'quantity' => '5', 'unit_price' => '1', 'total_amount' => '5.00', 'operation_date' => CarbonImmutable::parse('2025-01-02')], $this->usuario->id);

        self::assertSame(['Petro'], collect($this->relatorio(2025, $this->membro->id)['positions'])->map(fn ($p) => $p['investment']->name)->all());
        self::assertSame(['Petro', 'Vale'], collect($this->relatorio(2025)['positions'])->map(fn ($p) => $p['investment']->name)->all(), 'sem filtro: só os do perfil ativo');
    }

    // ---- a tela

    public function test_a_tela_abre_no_ano_anterior_na_pessoa_que_esta_vendo_e_mostra_as_secoes(): void
    {
        $a = $this->ativo('Petro');
        $this->compra($a, '100', '10.00', '2025-01-10');
        $this->venda($a, '10', '15.00', '2025-05-10');
        $this->servico->registerIncome($a->refresh(), TransactionType::Jcp, '100.00', '15.00', CarbonImmutable::parse('2025-06-01'), null, $this->usuario->id);

        Livewire::test(InvestmentTaxReport::class)
            ->assertSet('ano', '2025')
            ->assertSet('pessoa', $this->membro->id)
            ->assertSee('Bens e direitos')
            ->assertSee('Petro')
            ->assertSee('Juros sobre capital próprio')
            ->assertSee('Maio')
            ->assertSee('dentro do limite')
            ->assertSee('não substitui o seu contador');
    }

    public function test_ano_e_pessoa_invalidos_voltam_ao_padrao_e_ano_sem_dados_avisa(): void
    {
        Livewire::withQueryParams(['ano' => '1999', 'pessoa' => 'fulano'])->test(InvestmentTaxReport::class)
            ->assertSet('ano', '2025')
            ->assertSet('pessoa', $this->membro->id)
            ->assertSee('Nenhuma movimentação de investimentos em 2025');
    }

    public function test_exporta_csv_com_as_tres_secoes(): void
    {
        $a = $this->ativo('Petro');
        $this->compra($a, '100', '10.00', '2025-01-10');
        $this->venda($a, '10', '15.00', '2025-05-10');
        $this->servico->registerIncome($a->refresh(), TransactionType::Dividend, '50.00', '0', CarbonImmutable::parse('2025-06-01'), null, $this->usuario->id);

        $tela = Livewire::test(InvestmentTaxReport::class)->call('baixarCsv');
        $tela->assertFileDownloaded('investimentos-imposto-de-renda-2025.csv');

        $csv = base64_decode($tela->effects['download']['content']);
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        self::assertStringContainsString('BENS E DIREITOS', $csv);
        self::assertStringContainsString('PROVENTOS RECEBIDOS', $csv);
        self::assertStringContainsString('VENDAS DO ANO', $csv);
        self::assertStringContainsString(';Ação;90;10,00;900,00;0,00', $csv);
        self::assertStringContainsString(';10;150,00;150,00;100,00;50,00', $csv);
        self::assertStringContainsString('10/05/2025;', $csv);
    }

    public function test_exporta_pdf(): void
    {
        $a = $this->ativo('Petro');
        $this->compra($a, '100', '10.00', '2025-01-10');

        $tela = Livewire::test(InvestmentTaxReport::class)->call('baixarPdf');

        $tela->assertFileDownloaded('investimentos-imposto-de-renda-2025.pdf');
        self::assertStringStartsWith('%PDF', base64_decode($tela->effects['download']['content']));
    }

    public function test_corretor_nao_abre_o_relatorio(): void
    {
        $this->actingAs(User::factory()->broker()->create());

        Livewire::test(InvestmentTaxReport::class)->assertStatus(403);
    }
}
