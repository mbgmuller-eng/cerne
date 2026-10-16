<?php

namespace Tests\Feature;

use App\Enums\AssetClass;
use App\Enums\InvestmentSector;
use App\Enums\MemberRole;
use App\Enums\TransactionType;
use App\Models\BankAccount;
use App\Models\FinancialProfile;
use App\Models\InvestmentPortfolioMonth;
use App\Models\InvestmentRecord;
use App\Models\InvestmentTransaction;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\Investments\BrokerPackageImporter;
use App\Services\InvestmentTaxReportService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Carga dos investimentos de uma corretora a partir do pacote lido das notas, do extrato de preço médio e do relatório de
 * performance: simulação que não grava, gravação idempotente e conferência com o que a corretora declara.
 */
class BrokerPackageImportTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private FinancialProfile $perfil;

    private ProfileMember $membro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->usuario->id]);
        $this->membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuario->id, 'name' => 'Marcelo Muller', 'role' => MemberRole::Primary]);
        BankAccount::factory()->create(['profile_id' => $this->perfil->id, 'member_id' => $this->membro->id, 'bank_name' => 'BTG Pactual', 'account_type' => 'investment_account', 'current_balance' => '0']);
    }

    private function importador(): BrokerPackageImporter
    {
        return app(BrokerPackageImporter::class);
    }

    /** Um pacote pequeno: uma ação com compra, JCP e restituição, um FII com posição inicial e leilão, um Tesouro, um fundo. */
    private function pacote(): array
    {
        return [
            'origem' => ['corretora' => 'BTG Pactual', 'conta' => '0030'],
            'ativos' => [
                [
                    'chave' => 'CPLE3', 'classe' => 'acao', 'ticker' => 'CPLE3', 'nome' => 'Copel ON N2',
                    'esperado' => ['quantidade' => '41.00', 'preco_medio' => '11.73', 'valor_aplicado' => '480.91'],
                    'cotacao' => ['preco' => '17.58', 'data' => '2026-10-16', 'valor' => '720.78'],
                    'movimentos' => [
                        ['chave' => 'nota:1', 'tipo' => 'compra', 'data' => '2025-07-04', 'liquidacao' => '2025-07-08', 'quantidade' => '41', 'preco' => '12.38', 'total' => '507.58', 'corretagem' => '4.96', 'outras_taxas' => '0.14', 'liquido' => '512.68'],
                        ['chave' => 'pm:jcp:2025-12-30:1', 'tipo' => 'jcp', 'data' => '2025-12-30', 'bruto' => '15.18', 'imposto' => '2.27'],
                        ['chave' => 'extrato:restituicao:2025-12-30', 'tipo' => 'amortizacao', 'data' => '2025-12-30', 'bruto' => '31.77', 'imposto' => '0.00'],
                    ],
                ],
                [
                    'chave' => 'HGLG11', 'classe' => 'fii', 'ticker' => 'HGLG11', 'nome' => 'CSHG Logística FII',
                    'esperado' => ['quantidade' => '4.00', 'preco_medio' => '141.05', 'valor_aplicado' => '564.20'],
                    'cotacao' => ['preco' => '156.49', 'data' => '2026-10-16', 'valor' => '625.96'],
                    'movimentos' => [
                        ['chave' => 'pm:posicao_inicial:2026-07-01:1', 'tipo' => 'posicao_inicial', 'data' => '2026-07-01', 'quantidade' => '4', 'preco_medio' => '151.55'],
                        ['chave' => 'pm:dividendo:2026-07-31:1', 'tipo' => 'dividendo', 'data' => '2026-07-31', 'bruto' => '4.68', 'imposto' => '0.00'],
                        ['chave' => 'pm:amortizacao:2026-10-15:1', 'tipo' => 'amortizacao', 'data' => '2026-10-15', 'bruto' => '42.00', 'imposto' => '0.00'],
                    ],
                ],
                [
                    'chave' => 'NTN-B 2044', 'classe' => 'tesouro', 'nome' => 'Tesouro IPCA+ 2044 (NTN-B)', 'vencimento' => '2044-12-15', 'taxa' => 'IPCA + 7,14%',
                    'valor_atual' => '1837.70', 'data_inicial' => '2025-02-17', 'esperado' => ['valor_aplicado' => '300.00'],
                    'lotes' => [
                        ['chave' => 'tesouro:2044:2025-02-17:1', 'data' => '2025-02-17', 'quantidade' => '0.02', 'aplicado' => '100.00'],
                        ['chave' => 'tesouro:2044:2025-03-17:2', 'data' => '2025-03-17', 'quantidade' => '0.05', 'aplicado' => '200.00'],
                    ],
                ],
                [
                    'chave' => 'EMP SELIC', 'classe' => 'fundo', 'nome' => 'Empiricus Selic Simples FIRF', 'quantidade' => '653.90', 'valor_atual' => '1252.60', 'valor_aplicado' => '1252.60', 'data' => '2026-10-16',
                    'esperado' => ['quantidade' => '653.90', 'valor_aplicado' => '1252.60'], 'cotacao' => ['valor' => '1252.60'],
                ],
            ],
            'carteira_mensal' => [
                ['ano' => 2025, 'mes' => 11, 'abertura' => '3420.06', 'movimentacoes' => '0.00', 'impostos' => '0.00', 'fechamento' => '3586.13', 'ganho' => '166.07', 'rentabilidade' => '4.88', 'cdi' => '1.05'],
                ['ano' => 2021, 'mes' => 3, 'rentabilidade' => '0.01', 'cdi' => '0.15'],
            ],
        ];
    }

    private function nada(): bool
    {
        return \Illuminate\Support\Facades\DB::table('investment_records')->count() === 0
            && \Illuminate\Support\Facades\DB::table('investment_transactions')->count() === 0
            && \Illuminate\Support\Facades\DB::table('investment_portfolio_months')->count() === 0;
    }

    // ---- simulação e gravação

    public function test_a_simulacao_mostra_tudo_e_nao_grava_nada(): void
    {
        $linhas = $this->importador()->run($this->usuario, $this->pacote(), false);

        self::assertTrue($this->nada(), 'a simulação desfaz tudo');
        $porAtivo = collect($linhas)->keyBy('ativo');
        self::assertSame('criado', $porAtivo['CPLE3']['status']);
        self::assertSame(3, $porAtivo['CPLE3']['criadas']);
        self::assertSame('41', $porAtivo['CPLE3']['posicao']['quantidade']);
        self::assertSame('480.91', $porAtivo['CPLE3']['posicao']['custo']);
        self::assertSame([], $porAtivo['CPLE3']['diferencas'], 'bate com o relatório da corretora');
    }

    public function test_gravar_cria_ativos_compras_proventos_e_a_posicao_exata_da_corretora(): void
    {
        $this->importador()->run($this->usuario, $this->pacote(), true);
        app(ProfileContext::class)->set($this->perfil, $this->membro);

        $copel = InvestmentRecord::where('ticker', 'CPLE3')->sole();
        self::assertSame('41.000000', $copel->quantity);
        self::assertSame('11.729511', $copel->average_price, '(512,68 de custo líquido - 31,77 de restituição) / 41');
        self::assertSame('480.91', $copel->invested_amount);
        self::assertSame('720.78', $copel->current_amount);
        self::assertSame('17.580000', $copel->current_price);
        self::assertSame('2026-10-16', $copel->price_date->toDateString());
        self::assertSame('btg:0030:CPLE3', $copel->external_asset_id);
        self::assertSame('pdf_import', $copel->source->value);
        self::assertSame($this->membro->id, $copel->member_id);

        $compra = InvestmentTransaction::where('investment_id', $copel->id)->where('transaction_type', TransactionType::Buy)->sole();
        self::assertSame('512.68', $compra->net_amount, 'o líquido da nota');
        self::assertSame('4.96', $compra->broker_fee);
        self::assertSame('0.14', $compra->other_fees);
        self::assertSame('2025-07-08', $compra->settlement_date->toDateString());
        self::assertSame('nota:1', $compra->import_key);

        $jcp = InvestmentTransaction::where('investment_id', $copel->id)->where('transaction_type', TransactionType::Jcp)->sole();
        self::assertSame(['15.18', '2.27', '12.91'], [$jcp->total_amount, $jcp->other_fees, $jcp->net_amount]);

        // Posição que veio de incorporação: 4 cotas a 151,55, menos 42,00 do leilão de frações = 564,20 (141,05 por cota).
        $fii = InvestmentRecord::where('ticker', 'HGLG11')->sole();
        self::assertSame('4.000000', $fii->quantity);
        self::assertSame('141.050000', $fii->average_price);
        self::assertSame('564.20', $fii->invested_amount);
        self::assertSame('625.96', $fii->current_amount);
    }

    public function test_tesouro_guarda_os_lotes_como_aportes_sem_virar_cotas(): void
    {
        $this->importador()->run($this->usuario, $this->pacote(), true);
        app(ProfileContext::class)->set($this->perfil, $this->membro);

        $tesouro = InvestmentRecord::where('name', 'like', 'Tesouro IPCA+ 2044%')->sole();
        self::assertSame(AssetClass::Tesouro, $tesouro->asset_class);
        self::assertSame(InvestmentSector::FixedIncome, $tesouro->sector);
        self::assertNull($tesouro->quantity, 'Tesouro não negocia em cotas no Cerne');
        self::assertSame('300.00', $tesouro->invested_amount);
        self::assertSame('1837.70', $tesouro->current_amount);
        self::assertSame('2044-12-15', $tesouro->maturity_date->toDateString());
        self::assertSame('IPCA + 7,14%', $tesouro->return_rate);
        self::assertSame('postfixed_ipca', $tesouro->return_rate_type->value);
        self::assertSame(2, InvestmentTransaction::where('investment_id', $tesouro->id)->count());

        // Os aportes entram no custo de 31/12 do relatório de imposto de renda.
        $posicao = collect(app(InvestmentTaxReportService::class)->forYear(2025)['positions'])->firstWhere(fn ($p) => $p['investment']->id === $tesouro->id);
        self::assertSame('300.00', $posicao['cost']);
    }

    public function test_fundo_vira_posicao_inicial_e_liga_a_conta_de_investimento(): void
    {
        $this->importador()->run($this->usuario, $this->pacote(), true);
        app(ProfileContext::class)->set($this->perfil, $this->membro);

        $fundo = InvestmentRecord::where('name', 'like', 'Empiricus%')->sole();
        self::assertSame('653.900000', $fundo->quantity);
        self::assertSame('1252.60', $fundo->invested_amount);
        self::assertSame('1252.60', $fundo->current_amount);
        self::assertSame(BankAccount::where('account_type', 'investment_account')->sole()->id, $fundo->broker_account_id);
    }

    public function test_a_carteira_mensal_entra_com_saldos_onde_existem_e_so_rentabilidade_onde_nao(): void
    {
        $this->importador()->run($this->usuario, $this->pacote(), true);
        app(ProfileContext::class)->set($this->perfil, $this->membro);

        $nov = InvestmentPortfolioMonth::where(['year' => 2025, 'month' => 11])->sole();
        self::assertSame('3586.13', $nov->closing_balance);
        self::assertSame('4.8800', $nov->return_percent);
        self::assertSame('1.0500', $nov->cdi_percent);
        self::assertSame('464.76', $nov->percentOfCdi(), '4,88% / 1,05% = 464,76% do CDI');

        $antigo = InvestmentPortfolioMonth::where(['year' => 2021, 'month' => 3])->sole();
        self::assertNull($antigo->closing_balance);
        self::assertSame('0.0100', $antigo->return_percent);
    }

    // ---- idempotência e conflito

    public function test_rodar_de_novo_nao_duplica_nada(): void
    {
        $this->importador()->run($this->usuario, $this->pacote(), true);
        $segunda = collect($this->importador()->run($this->usuario, $this->pacote(), true))->keyBy('ativo');

        self::assertSame('existente', $segunda['CPLE3']['status']);
        self::assertSame(0, $segunda['CPLE3']['criadas']);
        self::assertSame(3, $segunda['CPLE3']['puladas']);
        self::assertSame(0, $segunda['Carteira mês a mês']['criadas']);
        self::assertSame(2, InvestmentPortfolioMonth::withoutProfileScope()->count());
        self::assertSame(1, InvestmentRecord::withoutProfileScope()->where('ticker', 'CPLE3')->count());
        self::assertSame(3 + 3 + 2 + 1, \Illuminate\Support\Facades\DB::table('investment_transactions')->count());
    }

    public function test_ativo_ja_cadastrado_a_mao_com_o_mesmo_ticker_nao_e_tocado(): void
    {
        $manual = InvestmentRecord::factory()->for($this->perfil, 'profile')->for($this->membro, 'member')->create([
            'ticker' => 'CPLE3', 'name' => 'Copel', 'asset_class' => AssetClass::Acao, 'sector' => InvestmentSector::VariableIncome, 'current_amount' => '100.00', 'quantity' => null,
        ]);

        $linhas = collect($this->importador()->run($this->usuario, $this->pacote(), true))->keyBy('ativo');

        self::assertSame('conflito', $linhas['CPLE3']['status']);
        self::assertStringContainsString('cadastrado à mão', $linhas['CPLE3']['avisos'][0]);
        self::assertSame('100.00', $manual->fresh()->current_amount);
        self::assertSame(0, InvestmentTransaction::withoutProfileScope()->where('investment_id', $manual->id)->count());
        self::assertSame('criado', $linhas['HGLG11']['status'], 'os demais seguem');
    }

    public function test_diferenca_em_relacao_ao_relatorio_da_corretora_e_apontada(): void
    {
        $pacote = $this->pacote();
        $pacote['ativos'][0]['esperado'] = ['quantidade' => '40.00', 'preco_medio' => '12.00', 'valor_aplicado' => '500.00'];

        $linha = collect($this->importador()->run($this->usuario, $pacote, false))->firstWhere('ativo', 'CPLE3');

        self::assertCount(3, $linha['diferencas']);
        self::assertStringContainsString('cotas: relatório 40.00, Cerne 41', $linha['diferencas'][0]);
    }

    public function test_um_erro_no_meio_desfaz_a_gravacao_inteira(): void
    {
        $pacote = $this->pacote();
        $pacote['ativos'][1]['movimentos'][] = ['chave' => 'x', 'tipo' => 'inventado', 'data' => '2026-10-01'];

        try {
            $this->importador()->run($this->usuario, $pacote, true);
            self::fail('deveria recusar o tipo desconhecido');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('inventado', $e->getMessage());
        }

        self::assertTrue($this->nada(), 'nem o que veio antes do erro ficou');
    }

    public function test_escolhe_o_membro_pelo_nome_e_recusa_quem_nao_existe(): void
    {
        $outro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => null, 'name' => 'Helen Tramontin', 'role' => MemberRole::Secondary]);

        $this->importador()->run($this->usuario, $this->pacote(), true, 'helen');

        self::assertSame($outro->id, InvestmentRecord::withoutProfileScope()->where('ticker', 'CPLE3')->sole()->member_id);

        $this->expectException(\InvalidArgumentException::class);
        $this->importador()->run($this->usuario, $this->pacote(), true, 'ninguem');
    }

    // ---- Tesouro encerrado, proventos a receber e data de pagamento

    private function tesouroEncerrado(): array
    {
        return [
            'chave' => 'Selic 2027', 'classe' => 'tesouro', 'nome' => 'Tesouro Selic 2027', 'taxa' => 'Selic', 'taxa_tipo' => 'variable',
            'valor_atual' => '0.00', 'data_inicial' => '2024-11-26', 'encerrado' => true,
            'lotes' => [['chave' => 'b3:Selic 2027:2024-11-26:c1', 'data' => '2024-11-26', 'quantidade' => '0.02', 'aplicado' => '312.85']],
            'vendas' => [['chave' => 'b3:Selic 2027:2025-04-08:v1', 'data' => '2025-04-08', 'quantidade' => '0.02', 'valor' => '326.60']],
        ];
    }

    public function test_tesouro_vendido_por_inteiro_fica_inativo_e_zerado_com_a_venda_no_historico(): void
    {
        $pacote = $this->pacote();
        $pacote['ativos'] = [$this->tesouroEncerrado()];

        $this->importador()->run($this->usuario, $pacote, true);
        app(ProfileContext::class)->set($this->perfil, $this->membro);

        $titulo = InvestmentRecord::withoutGlobalScopes()->where('name', 'Tesouro Selic 2027')->sole();
        self::assertFalse($titulo->is_active);
        self::assertSame('0.00', $titulo->invested_amount);
        self::assertSame('0.00', $titulo->current_amount);
        self::assertSame('variable', $titulo->return_rate_type->value);

        $venda = InvestmentTransaction::where('investment_id', $titulo->id)->where('transaction_type', TransactionType::Sell)->sole();
        self::assertSame('326.60', $venda->net_amount);
        self::assertSame('b3:Selic 2027:2025-04-08:v1', $venda->import_key);

        // No relatório de imposto de renda o resgate vai para o grupo de renda fixa, com o resultado de 13,75.
        $abril = collect(app(InvestmentTaxReportService::class)->forYear(2025)['months'])->firstWhere('month', 4);
        $grupo = collect($abril['groups'])->sole();
        self::assertSame('renda_fixa', $grupo['key']);
        self::assertSame('13.75', $grupo['result']);
        self::assertNull($grupo['limit']);
    }

    public function test_venda_parcial_de_tesouro_ainda_nao_e_aceita(): void
    {
        $titulo = $this->tesouroEncerrado();
        $titulo['vendas'][0]['quantidade'] = '0.01';
        $pacote = $this->pacote();
        $pacote['ativos'] = [$titulo];

        try {
            $this->importador()->run($this->usuario, $pacote, true);
            self::fail('deveria recusar a venda parcial');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('venda parcial', $e->getMessage());
        }

        self::assertTrue($this->nada());
    }

    public function test_provento_aprovado_e_nao_pago_so_avisa_e_nao_grava(): void
    {
        $pacote = $this->pacote();
        $pacote['pendentes'] = [['ativo' => 'WEGE3', 'descricao' => 'JCP', 'data' => '2026-09-18', 'liquido' => '3.18']];

        $linhas = collect($this->importador()->run($this->usuario, $pacote, true))->keyBy('ativo');

        self::assertSame('pendentes', $linhas['Proventos a receber']['status']);
        self::assertStringContainsString('WEGE3: JCP de 18/09/2026, líquido R$ 3,18', $linhas['Proventos a receber']['avisos'][0]);
        self::assertSame(0, $linhas['Proventos a receber']['criadas']);
        self::assertSame(0, \Illuminate\Support\Facades\DB::table('investment_records')->where('ticker', 'WEGE3')->count());
    }

    public function test_o_provento_entra_na_data_do_pagamento_e_o_imposto_de_renda_conta_nesse_ano(): void
    {
        $pacote = $this->pacote();
        // Aprovado em 30/12/2025, pago em 30/06/2026: é rendimento de 2026.
        $pacote['ativos'][0]['movimentos'][] = ['chave' => 'pm:dividendo:2025-12-30:1', 'tipo' => 'dividendo', 'data' => '2026-06-30', 'competencia' => '2025-12-30', 'bruto' => '18.63', 'imposto' => '0.00'];

        $this->importador()->run($this->usuario, $pacote, true);
        app(ProfileContext::class)->set($this->perfil, $this->membro);

        $dividendo = InvestmentTransaction::where('transaction_type', TransactionType::Dividend)->where('import_key', 'pm:dividendo:2025-12-30:1')->sole();
        self::assertSame('2026-06-30', $dividendo->operation_date->toDateString());

        $totais2025 = app(InvestmentTaxReportService::class)->forYear(2025)['income_totals'];
        $totais2026 = app(InvestmentTaxReportService::class)->forYear(2026)['income_totals'];
        self::assertArrayNotHasKey('dividend', $totais2025);
        self::assertSame('18.63', $totais2026['dividend']['net']);
    }

    // ---- o comando

    public function test_o_comando_simula_por_padrao_e_grava_com_apply(): void
    {
        $arquivo = tempnam(sys_get_temp_dir(), 'pacote');
        file_put_contents($arquivo, json_encode($this->pacote()));

        $this->artisan('cerne:import-investments', ['json' => $arquivo, '--email' => $this->usuario->email])
            ->expectsOutputToContain('Simulação: nada foi gravado')
            ->assertSuccessful();
        self::assertTrue($this->nada());

        $this->artisan('cerne:import-investments', ['json' => $arquivo, '--email' => $this->usuario->email, '--apply' => true])
            ->expectsOutputToContain('Gravado.')
            ->assertSuccessful();
        self::assertSame(4, InvestmentRecord::withoutProfileScope()->count());

        $this->artisan('cerne:import-investments', ['json' => $arquivo, '--email' => 'ninguem@example.com'])->assertFailed();

        unlink($arquivo);
    }
}
