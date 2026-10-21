<?php

namespace Tests\Feature;

use App\Enums\AssetClass;
use App\Enums\ConsultantClientStatus;
use App\Livewire\Consultant\PortfolioInvestments;
use App\Livewire\Consultant\PortfolioOverview;
use App\Livewire\Investments\InvestmentsIndex;
use App\Models\ConsultantClient;
use App\Models\CurrencyRate;
use App\Models\FinancialProfile;
use App\Models\InvestmentRecord;
use App\Models\InvestmentSnapshot;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\ConsultantPortfolioService;
use App\Services\CurrencyRateService;
use App\Services\Investments\OnilBasePerformance;
use App\Services\Investments\OnilPositionsImporter;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Base e performance da carteira da Onil (por moeda, com o resgate já feito) e o painel do consultor: filtro Em reais / Por moeda,
 * valor inteiro sem arredondar e o gráfico batendo com o patrimônio de hoje.
 */
class OnilBaseAndCarteiraTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-21 12:00:00'));

        foreach (['USDT' => '5.00', 'BTC' => '400000.00', 'ETH' => '10000.00'] as $moeda => $valor) {
            CurrencyRate::create(['currency' => $moeda, 'rate_brl' => $valor, 'rate_date' => '2026-10-21', 'source' => 'teste']);
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    // --- A regra da base ----------------------------------------------------------------------------------------------------

    public function test_com_deposito_a_base_e_o_deposito_e_o_resultado_e_o_do_fluxo(): void
    {
        // André de Faria Müller: depositou 25.000, tem 37.018,42 e os contratos informam 12.018,42 de performance.
        $r = OnilBasePerformance::resolve('37018.42', ['depositos' => '25000', 'valor_inicial_contratos' => '25000', 'performance_contratos' => '12018.42', 'saques_aprovados' => '0']);

        self::assertSame(['invested' => '25000', 'realized' => '0', 'method' => 'fluxo'], $r);
    }

    public function test_o_que_ja_foi_sacado_conta_no_resultado_sem_entrar_no_saldo(): void
    {
        // Dilermando: depositou 279.000, tem 334.483,25 e já sacou 46.002,74: resultado 101.485,99 (36,4% de 279.000).
        $r = OnilBasePerformance::resolve('334483.25', ['depositos' => '279000', 'valor_inicial_contratos' => '279000', 'performance_contratos' => '101485.98', 'saques_aprovados' => '46002.74']);

        self::assertSame('fluxo', $r['method']);
        self::assertSame('279000', $r['invested']);
        self::assertSame('46002.74', $r['realized']);
    }

    public function test_sem_deposito_a_base_e_o_valor_inicial_dos_contratos(): void
    {
        // Hugo, BTC: sem depósito em BTC, contrato inicial de 0,00739728 e saldo de 0,0086703.
        $r = OnilBasePerformance::resolve('0.0086703', ['depositos' => '0', 'valor_inicial_contratos' => '0.00739728', 'performance_contratos' => '0.00127302', 'saques_aprovados' => '0']);

        self::assertSame('contrato inicial', $r['method']);
        self::assertSame('0.00739728', $r['invested']);
        self::assertSame('0.00127302', bcsub($r['invested'] === '0.00739728' ? '0.0086703' : '0', $r['invested'], 8));
    }

    public function test_quando_o_fluxo_nao_concorda_com_os_contratos_houve_conversao_e_vale_a_performance_dos_contratos(): void
    {
        // Hugo, reais: depositou 17.000 mas 7.000 viraram cripto. O fluxo daria prejuízo; os contratos dizem +4.680,11 sobre 10.000.
        $r = OnilBasePerformance::resolve('14680.11', ['depositos' => '17000', 'valor_inicial_contratos' => '10000', 'performance_contratos' => '4680.11', 'saques_aprovados' => '0']);

        self::assertSame('contratos', $r['method']);
        self::assertSame('10000.0000000000', $r['invested']);
    }

    public function test_sem_deposito_nem_contrato_nao_ha_base_e_o_resultado_fica_zerado(): void
    {
        // Davi, reais: o saldo veio da conversão de USDT; nada diz quanto custou.
        $r = OnilBasePerformance::resolve('10017.52', ['depositos' => '0', 'valor_inicial_contratos' => '0', 'performance_contratos' => '0', 'saques_aprovados' => '0']);

        self::assertSame(['invested' => '10017.52', 'realized' => '0', 'method' => 'sem base'], $r);
        self::assertSame('sem base', OnilBasePerformance::resolve('1000', ['depositos' => '40000'])['method'], 'depósito sem contrato nem saldo que o explique');
    }

    public function test_uma_diferenca_pequena_entre_fluxo_e_contratos_ainda_e_fluxo(): void
    {
        // Ricardo: depositou 10.000, tem 10.169,50 e os contratos não informam performance: 1,7% de folga, dentro dos 5%.
        self::assertSame('fluxo', OnilBasePerformance::resolve('10169.5', ['depositos' => '10000', 'performance_contratos' => '0'])['method']);
        // 6% de distância já não é.
        self::assertNotSame('fluxo', OnilBasePerformance::resolve('10600', ['depositos' => '10000', 'performance_contratos' => '0'])['method']);
    }

    // --- A carga com base ---------------------------------------------------------------------------------------------------

    private function consultor(): User
    {
        return User::factory()->consultant()->create(['email' => 'consultor@teste.com']);
    }

    /** @return array<string, mixed> */
    private function linha(string $id, string $email, array $saldo, array $saque = [], array $base = []): array
    {
        $zero = ['BRL' => '0', 'USDT' => '0', 'BTC' => '0', 'ETH' => '0'];

        return ['onil_id' => $id, 'nome' => 'Cliente '.$id, 'email' => $email, 'saldo' => $saldo + $zero, 'saque' => $saque + $zero, 'base' => $base];
    }

    private function registro(string $email, string $moeda): ?InvestmentRecord
    {
        $perfilId = FinancialProfile::query()->where('owner_user_id', User::where('email', $email)->value('id'))->value('id');

        return InvestmentRecord::withoutGlobalScopes()->where('profile_id', $perfilId)->where('external_asset_id', 'like', "onil:%:{$moeda}")->first();
    }

    public function test_a_carga_grava_base_e_resgate_em_reais_e_na_moeda(): void
    {
        $consultor = $this->consultor();

        app(OnilPositionsImporter::class)->run($consultor, [$this->linha('1', 'a@teste.com', ['BRL' => '334483.25', 'BTC' => '0.0086703'], [], [
            'BRL' => ['depositos' => '279000', 'valor_inicial_contratos' => '279000', 'performance_contratos' => '101485.98', 'saques_aprovados' => '46002.74', 'origem' => 'Depósito'],
            'BTC' => ['depositos' => '0', 'valor_inicial_contratos' => '0.00739728', 'performance_contratos' => '0.00127302', 'saques_aprovados' => '0'],
        ])], aplicar: true);

        $brl = $this->registro('a@teste.com', 'BRL');
        self::assertSame('279000.00', $brl->invested_amount);
        self::assertSame('46002.74', $brl->realized_amount);
        self::assertSame('101485.99', $brl->unrealizedGain(), 'atual + resgatado - base');
        self::assertSame(36.37, round($brl->gainPercentage(), 2));

        $btc = $this->registro('a@teste.com', 'BTC');
        self::assertSame('0.0073972800', $btc->invested_native);
        self::assertNull($btc->realized_native, 'sem resgate fica vazio');
        self::assertSame('2958.91', $btc->invested_amount, '0,00739728 x 400.000');
        self::assertSame('3468.12', $btc->current_amount);

        $perf = $btc->nativePerformance();
        self::assertSame('0.0073972800', $perf['base']);
        self::assertSame('0.0012730200', $perf['gain']);
        self::assertSame(17.21, $perf['pct']);
    }

    public function test_o_valor_inicial_somado_da_empresa_entra_na_conta_da_pessoa(): void
    {
        $consultor = $this->consultor();
        $this->cliente($consultor, 'priscila@teste.com');

        app(OnilPositionsImporter::class)->run($consultor, [
            $this->linha('72961', 'happy@teste.com', [], ['BRL' => '219943.2'], ['BRL' => ['depositos' => '370000', 'valor_inicial_contratos' => '155000', 'performance_contratos' => '78965.98', 'saques_aprovados' => '14022.75']]),
            $this->linha('73062', 'priscila@teste.com', [], ['BRL' => '20772.42'], ['BRL' => ['depositos' => '14434.98', 'valor_inicial_contratos' => '14434.98', 'performance_contratos' => '7298.8', 'saques_aprovados' => '961.35']]),
        ], ['happy@teste.com' => 'priscila@teste.com'], aplicar: true);

        $r = $this->registro('priscila@teste.com', 'BRL');

        self::assertSame('240715.62', $r->current_amount);
        self::assertSame('14984.10', $r->realized_amount, 'os resgates das duas contas');
        // Parte da base virou cripto (conversão): vale o resultado dos contratos, 86.264,78.
        self::assertSame('86264.78', $r->unrealizedGain());
        self::assertSame('169434.94', $r->invested_amount);
    }

    public function test_rodar_de_novo_com_a_base_nao_duplica_e_a_ausencia_de_base_nao_apaga_a_que_existe(): void
    {
        $consultor = $this->consultor();
        $importador = app(OnilPositionsImporter::class);
        $base = ['BRL' => ['depositos' => '1000', 'valor_inicial_contratos' => '1000', 'performance_contratos' => '100', 'saques_aprovados' => '0']];

        $importador->run($consultor, [$this->linha('1', 'a@teste.com', ['BRL' => '1100'], [], $base)], aplicar: true);
        $importador->run($consultor, [$this->linha('1', 'a@teste.com', ['BRL' => '1200'])], aplicar: true);

        self::assertSame(1, InvestmentRecord::withoutGlobalScopes()->count());
        $r = $this->registro('a@teste.com', 'BRL');
        self::assertSame('1200.00', $r->current_amount);
        self::assertSame('1000.00', $r->invested_amount, 'sem a aba de base, o custo que já estava fica');
    }

    public function test_o_relatorio_diz_com_que_regra_cada_base_saiu(): void
    {
        $r = app(OnilPositionsImporter::class)->run($this->consultor(), [
            $this->linha('1', 'a@teste.com', ['BRL' => '10017.52'], [], ['BRL' => ['depositos' => '0']]),
        ]);

        self::assertSame('sem base', $r[0]['posicoes']['BRL']['base']);
    }

    // --- Cotação mexe na base ------------------------------------------------------------------------------------------------

    public function test_a_atualizacao_diaria_move_valor_base_e_resgate_juntos_e_o_percentual_nao_muda(): void
    {
        $consultor = $this->consultor();
        app(OnilPositionsImporter::class)->run($consultor, [$this->linha('1', 'a@teste.com', ['BTC' => '0.2'], [], [
            'BTC' => ['depositos' => '0.1', 'valor_inicial_contratos' => '0.1', 'performance_contratos' => '0.1', 'saques_aprovados' => '0'],
        ])], aplicar: true);

        $antes = $this->registro('a@teste.com', 'BTC');
        self::assertSame(100.0, $antes->gainPercentage());

        CurrencyRate::query()->where('currency', 'BTC')->update(['rate_brl' => '500000.00']);
        app(CurrencyRateService::class)->reprice();

        $depois = $this->registro('a@teste.com', 'BTC');
        self::assertSame('100000.00', $depois->current_amount);
        self::assertSame('50000.00', $depois->invested_amount);
        self::assertSame(100.0, $depois->gainPercentage(), 'a performance é da quantidade, não do preço');
    }

    // --- Tela de Investimentos -----------------------------------------------------------------------------------------------

    /** @return array{0: User, 1: FinancialProfile, 2: ProfileMember} */
    private function cliente(?User $consultor = null, string $email = 'cliente@teste.com'): array
    {
        $usuario = User::factory()->create(['email' => $email]);
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id]);

        if ($consultor !== null) {
            ConsultantClient::create(['consultant_id' => $consultor->id, 'client_id' => $usuario->id, 'status' => ConsultantClientStatus::Active, 'invited_at' => now(), 'accepted_at' => now()]);
        }

        return [$usuario, $perfil, $membro];
    }

    public function test_a_tela_mostra_base_e_resultado_na_moeda_e_o_ganho_inclui_o_que_ja_foi_resgatado(): void
    {
        $consultor = $this->consultor();
        [$usuario, $perfil, $membro] = $this->cliente($consultor);
        app(OnilPositionsImporter::class)->run($consultor, [$this->linha('1', $usuario->email, ['BRL' => '334483.25', 'BTC' => '0.0086703'], [], [
            'BRL' => ['depositos' => '279000', 'valor_inicial_contratos' => '279000', 'performance_contratos' => '101485.98', 'saques_aprovados' => '46002.74'],
            'BTC' => ['depositos' => '0', 'valor_inicial_contratos' => '0.00739728', 'performance_contratos' => '0.00127302', 'saques_aprovados' => '0'],
        ])], aplicar: true);

        $this->actingAs($usuario);
        app(ProfileContext::class)->set($perfil, $membro);

        Livewire::test(InvestmentsIndex::class)
            ->assertSee('Base BTC 0,00739728')->assertSee('+17,21%')->assertSee('BTC 0,00127302')
            ->assertSee('Base R$ 279.000,00')->assertSee('já resgatado R$ 46.002,74')
            ->assertSee('Ganho (inclui o que já foi resgatado)');
    }

    public function test_o_formulario_aceita_a_base_na_moeda_e_a_quantidade_nova_mantem_a_base(): void
    {
        [$usuario, $perfil, $membro] = $this->cliente();
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($perfil, $membro);

        Livewire::test(InvestmentsIndex::class)->call('toggleInvestmentForm')
            ->set('investmentName', 'BTC')->set('investmentAssetClass', 'btc')->set('investmentMemberId', $membro->id)
            ->set('investmentNativeAmount', '0.2')->set('investmentInvestedNative', '0.1')->call('saveInvestment')->assertHasNoErrors();

        $r = InvestmentRecord::query()->firstOrFail();
        self::assertSame('0.1000000000', $r->invested_native);
        self::assertSame('40000.00', $r->invested_amount);
        self::assertSame(100.0, $r->nativePerformance()['pct']);

        Livewire::test(InvestmentsIndex::class)->call('editInvestment', $r->id)
            ->assertSet('investmentInvestedNative', '0.1000000000')
            ->set('investmentNativeAmount', '0.3')->set('investmentInvestedNative', '')->call('saveInvestment')->assertHasNoErrors();

        $r->refresh();
        self::assertSame('0.3000000000', $r->native_amount);
        self::assertSame('0.1000000000', $r->invested_native, 'base vazia ao editar mantém a que existe');
    }

    // --- Painel da carteira do consultor ------------------------------------------------------------------------------------

    /** Um cliente com BRL e BTC (Daniela) e outro só em reais (André). */
    private function carteira(): User
    {
        $consultor = $this->consultor();
        [$daniela] = $this->cliente($consultor, 'daniela@teste.com');
        [$andre] = $this->cliente($consultor, 'andre@teste.com');

        app(OnilPositionsImporter::class)->run($consultor, [
            $this->linha('1', 'daniela@teste.com', ['BTC' => '0.01516957', 'ETH' => '0.46213857']),
            $this->linha('2', 'andre@teste.com', ['BRL' => '37018.42']),
        ], aplicar: true);

        return $consultor;
    }

    public function test_o_painel_mostra_o_valor_inteiro_e_nunca_arredonda(): void
    {
        $consultor = $this->carteira();
        $this->actingAs($consultor);

        $tela = Livewire::test(PortfolioOverview::class);

        $tela->assertSee('R$ 37.018,42')->assertDontSee('37,0 mil');
        // 0,01516957 x 400.000 + 0,46213857 x 10.000 = 6.067,83 + 4.621,39 = 10.689,22 da Daniela; mais os 37.018,42 do André.
        $tela->assertSee('R$ 47.707,64');
    }

    public function test_o_painel_oferece_o_filtro_por_moeda_com_o_total_de_cada_uma_e_a_linha_de_cada_cliente(): void
    {
        $consultor = $this->carteira();
        $this->actingAs($consultor);

        Livewire::test(PortfolioOverview::class)
            ->assertSee('Em reais')->assertSee('Por moeda')->assertDontSee('BTC 0,01516957')
            ->call('exibir', 'moedas')
            ->assertSee('BTC 0,01516957')->assertSee('ETH 0,46213857')->assertSee('Tudo em reais: R$ 47.707,64')
            ->assertSee('R$ 37.018,42');
    }

    public function test_sem_cliente_em_outra_moeda_o_filtro_nao_aparece(): void
    {
        $consultor = $this->consultor();
        $this->cliente($consultor);
        $this->actingAs($consultor);

        Livewire::test(PortfolioOverview::class)->assertDontSee('Por moeda');
    }

    public function test_a_escolha_do_painel_vale_tambem_em_investimentos_da_carteira(): void
    {
        $consultor = $this->carteira();
        $this->actingAs($consultor);

        Livewire::test(PortfolioOverview::class)->call('exibir', 'moedas');

        Livewire::test(PortfolioInvestments::class)
            ->assertSet('exibicao', 'moedas')
            ->assertSee('Total da carteira, por moeda')->assertSee('BTC 0,01516957')->assertSee('ETH 0,46213857')
            ->call('exibir', 'reais')->assertDontSee('Total da carteira, por moeda')->assertDontSee('BTC 0,01516957');
    }

    public function test_investimentos_da_carteira_traz_o_valor_inteiro_por_cliente_e_por_ativo(): void
    {
        $consultor = $this->carteira();
        $this->actingAs($consultor);

        Livewire::test(PortfolioInvestments::class)->assertSee('R$ 37.018,42')->assertDontSee('37,0 mil');
    }

    public function test_cada_cliente_da_carteira_traz_o_patrimonio_dividido_por_moeda(): void
    {
        $consultor = $this->carteira();

        $linhas = collect(app(ConsultantPortfolioService::class)->overview($consultor)['por_cliente'])->keyBy('email');

        self::assertSame(['BTC', 'ETH'], array_column($linhas['daniela@teste.com']['moedas'], 'currency'));
        self::assertSame('0.0151695700', $linhas['daniela@teste.com']['moedas'][0]['native']);
        self::assertSame(['BRL'], array_column($linhas['andre@teste.com']['moedas'], 'currency'));
        self::assertSame(['BRL', 'BTC', 'ETH'], array_column(app(ConsultantPortfolioService::class)->overview($consultor)['patrimonio']['por_moeda'], 'currency'));
    }

    // --- O gráfico bate com o patrimônio -------------------------------------------------------------------------------------

    public function test_o_ultimo_mes_do_grafico_e_o_valor_de_hoje_mesmo_com_investimento_sem_foto(): void
    {
        $consultor = $this->consultor();
        [$usuario, $perfil, $membro] = $this->cliente($consultor);
        $antigo = InvestmentRecord::factory()->create(['profile_id' => $perfil->id, 'member_id' => $membro->id, 'current_amount' => '1000.00']);
        InvestmentSnapshot::create(['profile_id' => $perfil->id, 'investment_id' => $antigo->id, 'year' => 2026, 'month' => 10, 'amount' => '900.00']);
        // Entrou hoje e ainda não tem foto: a foto mensal é do dia 1.
        InvestmentRecord::factory()->create(['profile_id' => $perfil->id, 'member_id' => $membro->id, 'current_amount' => '4000.00']);

        $dados = app(ConsultantPortfolioService::class)->overview($consultor);

        self::assertSame('5000.00', collect($dados['evolucao_investido'])->last()['valor']);
        self::assertSame($dados['patrimonio']['investimentos'], collect($dados['evolucao_investido'])->last()['valor'], 'o gráfico fecha no número do cartão');
    }

    public function test_um_mes_sem_foto_nao_vira_buraco_no_grafico(): void
    {
        $consultor = $this->consultor();
        [$usuario, $perfil, $membro] = $this->cliente($consultor);
        $a = InvestmentRecord::factory()->create(['profile_id' => $perfil->id, 'member_id' => $membro->id, 'current_amount' => '1000.00']);

        // Fotos em agosto e outubro; setembro ficou sem (a rotina mensal não rodou).
        InvestmentSnapshot::create(['profile_id' => $perfil->id, 'investment_id' => $a->id, 'year' => 2026, 'month' => 8, 'amount' => '800.00']);
        InvestmentSnapshot::create(['profile_id' => $perfil->id, 'investment_id' => $a->id, 'year' => 2026, 'month' => 10, 'amount' => '900.00']);

        $serie = collect(app(ConsultantPortfolioService::class)->overview($consultor)['evolucao_investido']);

        self::assertSame('800.00', $serie[$serie->count() - 3]['valor'], 'agosto');
        self::assertSame('800.00', $serie[$serie->count() - 2]['valor'], 'setembro segue com o último valor conhecido');
        self::assertSame('1000.00', $serie->last()['valor'], 'outubro é o valor de hoje');
    }

    public function test_investimento_encerrado_nao_continua_no_grafico_depois_da_ultima_foto(): void
    {
        $consultor = $this->consultor();
        [$usuario, $perfil, $membro] = $this->cliente($consultor);
        $vendido = InvestmentRecord::factory()->create(['profile_id' => $perfil->id, 'member_id' => $membro->id, 'current_amount' => '0.00', 'is_active' => false]);
        InvestmentSnapshot::create(['profile_id' => $perfil->id, 'investment_id' => $vendido->id, 'year' => 2026, 'month' => 6, 'amount' => '700.00']);

        $serie = collect(app(ConsultantPortfolioService::class)->overview($consultor)['evolucao_investido']);

        // 12 meses terminando em outubro/26: junho é o 8º.
        self::assertSame('700.00', $serie[7]['valor']);
        self::assertSame('0.00', $serie[8]['valor'], 'julho: já foi encerrado');
        self::assertSame('0.00', $serie->last()['valor']);
    }

    public function test_cartao_mostra_investimentos_menos_faturas_quando_ha_fatura(): void
    {
        $consultor = $this->consultor();
        [$usuario, $perfil, $membro] = $this->cliente($consultor);
        InvestmentRecord::factory()->create(['profile_id' => $perfil->id, 'member_id' => $membro->id, 'current_amount' => '5000.00']);
        $this->actingAs($consultor);

        Livewire::test(PortfolioOverview::class)->assertSee('R$ 5.000,00')->assertDontSee('faturas em aberto R$');
    }

    public function test_o_tipo_brl_da_onil_e_classe_de_ativo_valida(): void
    {
        self::assertSame('BRL', AssetClass::from('brl')->currency());
    }
}
