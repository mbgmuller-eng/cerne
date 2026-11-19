<?php

namespace Tests\Feature;

use App\Enums\MemberRole;
use App\Enums\UserRole;
use App\Livewire\CashFlow\CashFlowAnalysis;
use App\Livewire\Debts\DebtAnalysis;
use App\Livewire\Investments\InvestmentAnalysis;
use App\Models\Debt;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\IncomeCategory;
use App\Models\IncomeRecord;
use App\Models\InvestmentRecord;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\Analytics\CashFlowAnalytics;
use App\Services\Analytics\CategoryColors;
use App\Services\Analytics\DebtAnalytics;
use App\Services\Analytics\InvestmentAnalytics;
use App\Services\DebtService;
use App\Support\Charts\ChartPalette;
use App\Support\Charts\SvgCharts;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Análises (gráficos e PDF) de fluxo de caixa, dívidas e investimentos. Hoje é 18/11/2026.
 */
class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private FinancialProfile $perfil;

    private ProfileMember $ana;

    private ProfileMember $bruno;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-18 10:00:00'));
        Cache::flush();
        app()->setLocale('pt_BR');
        $this->seed(\Database\Seeders\TaxonomySeeder::class);

        $this->usuario = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $this->usuario->id, 'profile_name' => 'Família Teste']);
        $this->ana = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuario->id, 'role' => MemberRole::Primary, 'name' => 'Ana']);
        $this->bruno = ProfileMember::factory()->secondary()->create(['profile_id' => $this->perfil->id, 'user_id' => User::factory()->create()->id, 'name' => 'Bruno']);
        $this->actingAs($this->usuario);
        app(ProfileContext::class)->set($this->perfil, $this->ana);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function despesa(string $valor, string $data, ?string $categoria = null, array $extra = []): ExpenseRecord
    {
        $campos = array_merge(['amount' => $valor, 'expense_date' => $data, 'member_id' => null, 'is_private' => false], $extra);
        if ($categoria !== null) {
            $campos['category_id'] = ExpenseCategory::factory()->create(['name' => $categoria])->id;
        }

        return ExpenseRecord::factory()->for($this->perfil, 'profile')->create($campos);
    }

    private function receita(string $valor, string $data): IncomeRecord
    {
        return IncomeRecord::factory()->for($this->perfil, 'profile')->create([
            'amount' => $valor, 'received_date' => $data, 'member_id' => null, 'category_id' => IncomeCategory::factory()->create()->id,
        ]);
    }

    // ------------------------------------------------------------------ gráficos (SVG)

    public function test_toda_forma_de_grafico_gera_svg_valido(): void
    {
        $p = ChartPalette::pdf();
        $svgs = [
            SvgCharts::rosca([['A', '600.00'], ['B', '300.00'], ['C', '100.00']], $p, 'R$ 1.000,00', 'total'),
            SvgCharts::colunasEmpilhadas(['jan', 'fev'], [['A', ['100.00', '200.00'], $p->serie(0)], ['B', ['50.00', '0.00'], $p->serie(1)]], $p),
            SvgCharts::linhas(['1', '2', '3'], [['Atual', ['10.00', '20.00', null], $p->serie(0)], ['Antes', ['5.00', '15.00', '30.00'], $p->gray]], $p),
            SvgCharts::barrasHorizontais([['XP', 100.0, '60%'], ['Itaú', 40.0, '40%']], $p),
            SvgCharts::barraUnica([['Essencial', 40.5, $p->serie(0)], ['Sobra', 59.5, $p->gray]], $p),
        ];

        foreach ($svgs as $svg) {
            $doc = new \DOMDocument;
            self::assertTrue($doc->loadXML($svg), 'o SVG precisa ser XML bem formado: '.substr($svg, 0, 80));
            self::assertSame('svg', $doc->documentElement->nodeName);
            self::assertNotSame('', $doc->documentElement->getAttribute('aria-label'));
        }
    }

    public function test_texto_vindo_do_banco_e_escapado_no_svg(): void
    {
        $svg = SvgCharts::rosca([['<script>alert(1)</script> & cia', '100.00']], ChartPalette::pdf(), '', '');

        self::assertStringNotContainsString('<script>', $svg);
        self::assertStringContainsString('&lt;script&gt;', $svg);
        $doc = new \DOMDocument;
        self::assertTrue($doc->loadXML($svg));
    }

    public function test_rosca_de_uma_fatia_so_fecha_o_circulo(): void
    {
        $svg = SvgCharts::rosca([['Tudo', '500.00']], ChartPalette::pdf());

        self::assertStringContainsString('<circle', $svg);
        self::assertStringContainsString('Tudo: R$ 500,00 (100%)', $svg);
    }

    public function test_linha_interrompe_no_valor_nulo_e_marca_o_ultimo_ponto(): void
    {
        $svg = SvgCharts::linhas(['1', '2', '3', '4'], [['Mês', ['10.00', '20.00', null, null], '#2a78d6']], ChartPalette::pdf());

        self::assertSame(1, substr_count($svg, '<path'), 'uma linha só, sem seguir pelo que ainda não aconteceu');
        self::assertSame(1, substr_count($svg, '<circle'), 'marcador só no último ponto com valor');
    }

    public function test_paleta_da_tela_usa_variaveis_css_e_a_do_pdf_usa_hexadecimal(): void
    {
        self::assertStringStartsWith('var(--chart-1', ChartPalette::screen()->serie(0));
        self::assertSame('#2a78d6', ChartPalette::pdf()->serie(0));
        self::assertSame(ChartPalette::pdf()->gray, ChartPalette::pdf()->serie(8), 'a nona cor nunca existe: cai no cinza');
    }

    public function test_cor_segue_a_categoria_e_a_nona_nunca_existe(): void
    {
        $p = ChartPalette::pdf();
        $a = CategoryColors::assign(['Habitação', 'Alimentação', 'Saúde'], $p);
        $b = CategoryColors::assign(['Saúde', 'Habitação'], $p);

        self::assertSame($a['Habitação'], $b['Habitação'], 'a mesma categoria prefere a mesma cor');
        self::assertCount(3, array_unique($a), 'categorias do mesmo gráfico não dividem cor');

        $muitas = CategoryColors::assign(['A1', 'B2', 'C3', 'D4', 'E5', 'F6', 'G7', 'H8', 'I9', 'Outras'], $p);
        self::assertSame($p->gray, $muitas['H8'], 'a oitava categoria já cai no cinza');
        self::assertSame($p->gray, $muitas['Outras']);
    }

    // ------------------------------------------------------------------ fluxo de caixa

    public function test_categorias_do_mes_somam_em_sql_e_ficam_em_ordem(): void
    {
        $this->despesa('600.00', '2026-11-03', 'Mercado Teste');
        $this->despesa('200.00', '2026-11-04', 'Lazer Teste');
        $this->despesa('999.00', '2026-10-05', 'Fora do mês');

        $r = app(CashFlowAnalytics::class)->report(2026, 11, ChartPalette::pdf());
        $categorias = collect($r['secoes'])->firstWhere('id', 'categorias');

        self::assertSame('Mercado Teste', $categorias['legenda'][0][1]);
        self::assertStringContainsString('R$ 600,00', $categorias['legenda'][0][2]);
        self::assertStringContainsString('75%', $categorias['legenda'][0][2]);
        self::assertCount(2, $categorias['legenda'], 'a despesa de outro mês não entra');
    }

    public function test_o_que_o_conjuge_ocultou_nao_entra_nos_totais(): void
    {
        $this->despesa('100.00', '2026-11-03', 'Presentes Teste', ['member_id' => $this->bruno->id, 'is_private' => true]);
        $this->despesa('40.00', '2026-11-03', 'Presentes Teste B', ['member_id' => $this->bruno->id, 'is_private' => false]);
        $outro = FinancialProfile::factory()->create(['owner_user_id' => User::factory()->create()->id]);
        ExpenseRecord::factory()->for($outro, 'profile')->create(['amount' => '999.00', 'expense_date' => '2026-11-03', 'member_id' => null]);

        $r = app(CashFlowAnalytics::class)->report(2026, 11, ChartPalette::pdf());

        self::assertSame('R$ 40,00', $r['kpis'][1][1], 'só o visível, sem o privado do cônjuge nem o de outro perfil');
    }

    public function test_evolucao_separa_as_cinco_maiores_e_junta_o_resto_em_outras(): void
    {
        foreach (['Aa', 'Bb', 'Cc', 'Dd', 'Ee', 'Ff', 'Gg'] as $i => $nome) {
            $this->despesa((string) (700 - $i * 100).'.00', '2026-11-03', $nome.' Cat');
        }

        $r = app(CashFlowAnalytics::class)->report(2026, 11, ChartPalette::pdf());
        $evolucao = collect($r['secoes'])->firstWhere('id', 'evolucao');

        self::assertSame(['Mês', 'Aa Cat', 'Bb Cat', 'Cc Cat', 'Dd Cat', 'Ee Cat', 'Outras', 'Total'], $evolucao['tabela']['colunas']);
        $ultima = end($evolucao['tabela']['linhas']);
        self::assertStringContainsString('em andamento', $ultima[0]);
        self::assertSame('R$ 300,00', $ultima[6], 'Outras = as duas menores (200 + 100)');
    }

    public function test_ritmo_compara_com_o_mes_anterior_no_mesmo_dia(): void
    {
        $this->despesa('1000.00', '2026-10-05');
        $this->despesa('3000.00', '2026-10-25');
        $this->despesa('500.00', '2026-11-10');

        $r = app(CashFlowAnalytics::class)->report(2026, 11, ChartPalette::pdf());
        $ritmo = collect($r['secoes'])->firstWhere('id', 'ritmo');

        // Até o dia 18: novembro 500, outubro 1000 (o gasto de 25/10 ainda não tinha acontecido): 50% a menos.
        self::assertStringContainsString('Até o dia 18 você gastou R$ 500,00', $ritmo['nota']);
        self::assertStringContainsString('R$ 1.000,00', $ritmo['nota']);
        self::assertStringContainsString('50% a menos', $ritmo['nota']);
    }

    public function test_destino_de_cada_100_reais_e_a_divisao_da_receita(): void
    {
        $this->receita('1000.00', '2026-11-05');
        $this->despesa('400.00', '2026-11-06', null, ['necessity' => 'essential']);
        $this->despesa('100.00', '2026-11-07', null, ['necessity' => 'discretionary']);

        $r = app(CashFlowAnalytics::class)->report(2026, 11, ChartPalette::pdf());
        $destino = collect($r['secoes'])->firstWhere('id', 'destino');

        self::assertSame(['Essencial', 'Supérfluo', 'Investimentos', 'Sobra'], array_column($destino['legenda'], 1));
        self::assertSame('R$ 40,00', $destino['legenda'][0][2]);
        self::assertSame('R$ 50,00', $destino['legenda'][3][2]);
        self::assertArrayNotHasKey('nota', array_filter($destino, fn ($v, $k) => $k === 'nota' && $v !== null, ARRAY_FILTER_USE_BOTH));
    }

    public function test_despesa_maior_que_a_receita_nao_inventa_sobra(): void
    {
        $this->receita('100.00', '2026-11-05');
        $this->despesa('300.00', '2026-11-06', null, ['necessity' => 'essential']);

        $destino = collect(app(CashFlowAnalytics::class)->report(2026, 11, ChartPalette::pdf())['secoes'])->firstWhere('id', 'destino');

        self::assertSame('R$ 0,00', $destino['legenda'][3][2]);
        self::assertStringContainsString('passaram da receita em R$ 200,00', $destino['nota']);
    }

    public function test_sem_lancamentos_nao_ha_secoes_e_a_tela_avisa(): void
    {
        $r = app(CashFlowAnalytics::class)->report(2026, 11, ChartPalette::pdf());

        self::assertSame([], $r['secoes']);
        Livewire::test(CashFlowAnalysis::class)->assertSeeHtml('data-analise-vazia');
    }

    public function test_subcategorias_de_uma_categoria_e_categoria_invalida_da_404(): void
    {
        $cat = ExpenseCategory::factory()->create(['name' => 'Alimentação Teste']);
        $sub = \App\Models\ExpenseSubcategory::factory()->create(['category_id' => $cat->id, 'name' => 'Delivery Teste']);
        $this->despesa('80.00', '2026-11-03', null, ['category_id' => $cat->id, 'subcategory_id' => $sub->id]);
        $this->despesa('20.00', '2026-11-04', null, ['category_id' => $cat->id, 'subcategory_id' => null]);

        $r = app(CashFlowAnalytics::class)->subcategorias(2026, 11, $cat->id);

        self::assertSame('Alimentação Teste', $r['categoria']);
        self::assertSame('Delivery Teste', $r['linhas'][0]['nome']);
        self::assertSame('Sem subcategoria', $r['linhas'][1]['nome']);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(CashFlowAnalytics::class)->subcategorias(2026, 11, 'id-inventado');
    }

    public function test_tela_navega_entre_meses_e_abre_uma_categoria(): void
    {
        $cat = ExpenseCategory::factory()->create(['name' => 'Moradia Teste']);
        $this->despesa('300.00', '2026-11-03', null, ['category_id' => $cat->id]);

        Livewire::test(CashFlowAnalysis::class)
            ->assertSee('novembro de 2026')->assertSeeHtml('data-analise="categorias"')
            ->call('abrirCategoria', $cat->id)->assertSeeHtml('data-subcategorias')->assertSee('Subcategorias de Moradia Teste')
            ->call('abrirCategoria', $cat->id)->assertDontSeeHtml('data-subcategorias')
            ->call('previousMonth')->assertSet('mes', 10)->assertSee('outubro de 2026')
            ->call('currentMonth')->assertSet('mes', null);
    }

    public function test_pdf_do_fluxo_de_caixa_baixa_um_arquivo(): void
    {
        $this->receita('1000.00', '2026-11-05');
        $this->despesa('200.00', '2026-11-06', 'Mercado Teste', ['necessity' => 'essential']);

        Livewire::test(CashFlowAnalysis::class)->call('baixarPdf')->assertFileDownloaded('analises-fluxo-de-caixa-2026-11.pdf');
    }

    // ------------------------------------------------------------------ dívidas

    private function financiamento(string $nome, int $parcelas, string $primeiroVencimento = '2026-11-05'): Debt
    {
        $cat = ExpenseCategory::available()->where('name', 'Habitação')->firstOrFail();

        return app(DebtService::class)->create([
            'kind' => 'financing', 'name' => $nome, 'creditor' => 'Banco', 'amortization' => 'price', 'principal' => '120000.00', 'rate' => '12',
            'rate_basis' => 'annual_nominal', 'term' => $parcelas, 'first_due_date' => $primeiroVencimento, 'paid_before' => 0, 'member_id' => $this->ana->id,
            'category_id' => $cat->id, 'subcategory_id' => $cat->subcategories()->where('name', 'Prestação da casa')->firstOrFail()->id,
        ]);
    }

    public function test_dividas_mostram_juros_e_amortizacao_do_cronograma(): void
    {
        $divida = $this->financiamento('Apartamento', 24);
        app(DebtService::class)->pay($divida->installments[0], CarbonImmutable::parse('2026-11-05'), null, null, null, true);

        $r = app(DebtAnalytics::class)->report($divida->fresh(), ChartPalette::pdf());
        $juros = collect($r['secoes'])->firstWhere('id', 'juros');

        self::assertSame('Já pago em juros', $r['kpis'][0][0]);
        self::assertNotSame('R$ 0,00', $r['kpis'][0][1]);
        self::assertStringContainsString('do que foi pago até aqui', $r['kpis'][0][2]);
        self::assertCount(12, $juros['tabela']['linhas'], 'no máximo 12 parcelas futuras');
        self::assertStringContainsString('é juro', $juros['nota']);
        self::assertSame(['Juros', 'Amortização'], array_column($juros['legenda'], 1));
    }

    public function test_dividas_abre_na_que_tem_mais_parcelas_por_pagar(): void
    {
        $curta = $this->financiamento('A curta', 3);
        $longa = $this->financiamento('B longa', 36);
        $servico = app(DebtAnalytics::class);

        self::assertSame($longa->id, $servico->padrao($servico->selecionaveis())->id);

        Livewire::test(DebtAnalysis::class)->assertSee('B longa')->set('divida', $curta->id)->assertSee('A curta');
    }

    public function test_sem_divida_com_juros_a_tela_explica_e_nao_oferece_pdf(): void
    {
        Livewire::test(DebtAnalysis::class)->assertSeeHtml('data-analise-vazia')->assertDontSeeHtml('data-baixar-pdf');
    }

    public function test_pdf_de_dividas_baixa_um_arquivo(): void
    {
        $this->financiamento('Apartamento', 24);

        Livewire::test(DebtAnalysis::class)->call('baixarPdf')->assertFileDownloaded('analises-dividas-apartamento.pdf');
    }

    public function test_divida_oculta_do_conjuge_nao_aparece_na_lista(): void
    {
        $this->financiamento('Do Ana', 24);
        $oculta = $this->financiamento('Do Bruno', 24);
        $oculta->forceFill(['member_id' => $this->bruno->id, 'is_private' => true])->save();
        \App\Models\DebtInstallment::query()->where('debt_id', $oculta->id)->update(['member_id' => $this->bruno->id, 'is_private' => true]);

        $nomes = app(DebtAnalytics::class)->selecionaveis()->pluck('name')->all();

        self::assertSame(['Do Ana'], $nomes);
    }

    // ------------------------------------------------------------------ investimentos

    public function test_investimentos_mostram_a_concentracao_por_instituicao(): void
    {
        foreach ([['XP', '600.00'], ['XP', '200.00'], ['Itaú', '200.00']] as [$inst, $valor]) {
            InvestmentRecord::factory()->create(['profile_id' => $this->perfil->id, 'member_id' => $this->ana->id, 'institution' => $inst, 'current_amount' => $valor, 'is_private' => false]);
        }
        InvestmentRecord::factory()->inactive()->create(['profile_id' => $this->perfil->id, 'member_id' => $this->ana->id, 'institution' => 'Encerrada', 'current_amount' => '9999.00']);

        $r = app(InvestmentAnalytics::class)->report(ChartPalette::pdf());
        $linhas = $r['secoes'][0]['tabela']['linhas'];

        self::assertSame(['XP', 'R$ 800,00', '80,0%', '2'], $linhas[0]);
        self::assertSame(['Itaú', 'R$ 200,00', '20,0%', '1'], $linhas[1]);
        self::assertCount(2, $linhas, 'investimento encerrado não entra');
        self::assertSame('R$ 1.000,00', $r['kpis'][0][1]);
        self::assertSame('80,0%', $r['kpis'][1][1]);
    }

    public function test_investimento_privado_do_conjuge_nao_entra(): void
    {
        InvestmentRecord::factory()->create(['profile_id' => $this->perfil->id, 'member_id' => $this->ana->id, 'institution' => 'XP', 'current_amount' => '100.00', 'is_private' => false]);
        InvestmentRecord::factory()->create(['profile_id' => $this->perfil->id, 'member_id' => $this->bruno->id, 'institution' => 'Escondida', 'current_amount' => '5000.00', 'is_private' => true]);

        $r = app(InvestmentAnalytics::class)->report(ChartPalette::pdf());

        self::assertSame('R$ 100,00', $r['kpis'][0][1]);
    }

    public function test_mais_de_oito_instituicoes_viram_outras(): void
    {
        foreach (range(1, 10) as $i) {
            InvestmentRecord::factory()->create(['profile_id' => $this->perfil->id, 'member_id' => $this->ana->id, 'institution' => 'Inst '.$i, 'current_amount' => (string) (1000 - $i * 10).'.00', 'is_private' => false]);
        }

        $linhas = app(InvestmentAnalytics::class)->report(ChartPalette::pdf())['secoes'][0]['tabela']['linhas'];

        self::assertCount(9, $linhas);
        self::assertStringStartsWith('Outras (2)', end($linhas)[0]);
    }

    public function test_pagina_e_pdf_de_investimentos(): void
    {
        InvestmentRecord::factory()->create(['profile_id' => $this->perfil->id, 'member_id' => $this->ana->id, 'institution' => 'XP', 'current_amount' => '100.00', 'is_private' => false]);

        Livewire::test(InvestmentAnalysis::class)->assertSee('Onde o patrimônio está concentrado')->call('baixarPdf')->assertFileDownloaded();
    }

    // ------------------------------------------------------------------ acesso e navegação

    public function test_corretor_nao_entra_em_nenhuma_das_tres(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Broker]));

        foreach (['cashflow.analytics', 'debts.analytics', 'investments.analytics'] as $rota) {
            $this->get(route($rota))->assertForbidden();
        }
    }

    public function test_as_tres_areas_levam_as_analises_e_a_pagina_abre(): void
    {
        $this->get(route('cashflow.index'))->assertOk()->assertSeeHtml('data-ir-analises')->assertSee(route('cashflow.analytics'), false);
        $this->get(route('debts.index'))->assertOk()->assertSeeHtml('data-ir-analises');
        $this->get(route('investments.index'))->assertOk()->assertSeeHtml('data-ir-analises');

        $this->get(route('cashflow.analytics'))->assertOk()->assertSee('Análises');
        $this->get(route('debts.analytics'))->assertOk();
        $this->get(route('investments.analytics'))->assertOk();
    }

    public function test_cada_pagina_traz_os_graficos_como_svg_com_variaveis_de_cor(): void
    {
        $this->despesa('300.00', '2026-11-03', 'Mercado Teste');

        $html = $this->get(route('cashflow.analytics'))->assertOk()->getContent();

        self::assertStringContainsString('<svg', $html);
        self::assertStringContainsString('var(--chart-1', $html);
        self::assertStringContainsString('Ver em tabela', $html);
    }
}
