<?php

namespace Tests\Feature;

use App\Enums\AllocationAssetClass;
use App\Enums\AssetClass;
use App\Enums\ConsultantClientStatus;
use App\Enums\MemberRole;
use App\Enums\ProfileType;
use App\Enums\UserRole;
use App\Livewire\Dashboard;
use App\Livewire\Investments\InvestmentsIndex;
use App\Models\ConsultantClient;
use App\Models\CurrencyRate;
use App\Models\FinancialProfile;
use App\Models\InvestmentRecord;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\ClientOnboardingService;
use App\Services\CurrencyRateService;
use App\Services\DashboardService;
use App\Services\Investments\OnilPositionsImporter;
use App\Support\CurrencyBreakdown;
use App\Support\Money;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Investimento em USDT, BTC e ETH: a quantidade na moeda, o valor em reais pela cotação do dia (atualizada por rotina), o filtro
 * "Em reais / Por moeda" e a carga da posição dos clientes da Onil.
 */
class OnilCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const COTACOES = ['USDT' => '5.40', 'BTC' => '350000.00', 'ETH' => '15000.00'];

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-21 12:00:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @var array<string, array{0: int, 1: mixed}> host => [status, corpo] */
    private array $fontes = [];

    private function responder(): void
    {
        // Um só stub que lê o estado atual: Http::fake() acumula e o primeiro que casa vale, então trocar a resposta no meio do teste
        // com outro fake() não funcionaria.
        Http::fake(function (\Illuminate\Http\Client\Request $req) {
            foreach ($this->fontes as $host => [$status, $corpo]) {
                if (str_contains($req->url(), $host)) {
                    return Http::response($corpo, $status);
                }
            }

            return Http::response('sem stub', 500);
        });
    }

    /** A fonte principal (CoinGecko) responde com estas cotações. */
    private function fakeAwesome(array $cotacoes = self::COTACOES): void
    {
        $this->fontes = ['api.coingecko.com' => [200, [
            'tether' => ['brl' => (float) $cotacoes['USDT']], 'bitcoin' => ['brl' => (float) $cotacoes['BTC']], 'ethereum' => ['brl' => (float) $cotacoes['ETH']],
        ]]];
        $this->responder();
    }

    private function semerCotacoes(): void
    {
        foreach (self::COTACOES as $moeda => $valor) {
            CurrencyRate::create(['currency' => $moeda, 'rate_brl' => $valor, 'rate_date' => '2026-10-21', 'source' => 'teste']);
        }
    }

    /** @return array{0: User, 1: FinancialProfile, 2: ProfileMember} */
    private function cliente(?User $consultor = null, string $email = 'cliente@teste.com'): array
    {
        $usuario = User::factory()->create(['email' => $email, 'role' => UserRole::Client]);
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id, 'role' => MemberRole::Primary]);

        if ($consultor !== null) {
            ConsultantClient::create(['consultant_id' => $consultor->id, 'client_id' => $usuario->id, 'status' => ConsultantClientStatus::Active, 'invited_at' => now(), 'accepted_at' => now()]);
        }

        return [$usuario, $perfil, $membro];
    }

    private function entrar(User $usuario, FinancialProfile $perfil, ProfileMember $membro): void
    {
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($perfil, $membro);
    }

    // --- Tipos de investimento e formatação ----------------------------------------------------------------------------

    public function test_os_quatro_tipos_novos_trazem_a_moeda_e_a_classe_de_alocacao(): void
    {
        self::assertSame('USDT', AssetClass::Usdt->currency());
        self::assertSame('BTC', AssetClass::Btc->currency());
        self::assertSame('ETH', AssetClass::Eth->currency());
        self::assertSame('BRL', AssetClass::Brl->currency());
        self::assertSame('BRL', AssetClass::Cripto->currency(), 'o cripto antigo segue em reais');
        self::assertTrue(AssetClass::Btc->isForeignCurrency());
        self::assertFalse(AssetClass::Brl->isForeignCurrency());

        foreach ([AssetClass::Usdt, AssetClass::Btc, AssetClass::Eth] as $c) {
            self::assertSame(AllocationAssetClass::DigitalAssets, $c->allocationClass());
            self::assertFalse($c->hasQuantity(), 'não passa pelo fluxo de cotas e preço médio');
        }

        self::assertNull(AssetClass::Brl->allocationClass());
        self::assertSame(['USDT', 'BTC', 'ETH', 'BRL'], [AssetClass::Usdt->label(), AssetClass::Btc->label(), AssetClass::Eth->label(), AssetClass::Brl->label()]);
    }

    public function test_a_moeda_do_registro_segue_a_classe_e_nunca_se_informa_a_parte(): void
    {
        [, $perfil, $membro] = $this->cliente();

        $r = InvestmentRecord::create([
            'profile_id' => $perfil->id, 'member_id' => $membro->id, 'sector' => AssetClass::Btc->sector(), 'asset_class' => AssetClass::Btc,
            'name' => 'BTC', 'currency' => 'BRL', 'native_amount' => '0.05216887', 'current_amount' => '100.00',
        ]);

        self::assertSame('BTC', $r->fresh()->currency);
        self::assertSame('0.0521688700', $r->fresh()->native_amount);
        self::assertTrue($r->fresh()->isForeignCurrency());
    }

    public function test_a_quantidade_aparece_no_formato_de_cada_moeda(): void
    {
        self::assertSame('USDT 1.039,20', Money::native('1039.2', 'USDT'));
        self::assertSame('BTC 0,05216887', Money::native('0.0521688700', 'BTC'));
        self::assertSame('ETH 1,19693619', Money::native('1.19693619', 'ETH'));
        self::assertSame('R$ 10,00', Money::native('10', 'BRL'));
    }

    public function test_o_resumo_por_moeda_vem_em_ordem_e_so_com_o_que_existe(): void
    {
        $linhas = CurrencyBreakdown::rows([
            ['currency' => 'ETH', 'brl' => '15000.00', 'native' => '1.0000000000'],
            ['currency' => 'BRL', 'brl' => '1000.00', 'native' => null],
            ['currency' => 'USDT', 'brl' => '0.00', 'native' => '0.0000000000'],
            ['currency' => 'BTC', 'brl' => '350000.00', 'native' => '1.0000000000'],
        ]);

        self::assertSame(['BRL', 'BTC', 'ETH'], array_column($linhas, 'currency'), 'ordem fixa, sem a moeda zerada');
        self::assertSame('1000.00', $linhas[0]['native'], 'o real é a quantidade e o valor ao mesmo tempo');
        self::assertTrue(CurrencyBreakdown::hasForeign($linhas));
        self::assertFalse(CurrencyBreakdown::hasForeign([$linhas[0]]));
    }

    // --- Cotação diária ------------------------------------------------------------------------------------------------

    public function test_a_rotina_grava_as_cotacoes_do_dia_e_refaz_o_valor_em_reais(): void
    {
        $this->fakeAwesome();
        [, $perfil, $membro] = $this->cliente();
        $btc = InvestmentRecord::create([
            'profile_id' => $perfil->id, 'member_id' => $membro->id, 'sector' => AssetClass::Btc->sector(), 'asset_class' => AssetClass::Btc,
            'name' => 'BTC', 'native_amount' => '0.05216887', 'current_amount' => '0.00',
        ]);
        $reais = InvestmentRecord::create([
            'profile_id' => $perfil->id, 'member_id' => $membro->id, 'sector' => AssetClass::Brl->sector(), 'asset_class' => AssetClass::Brl,
            'name' => 'BRL', 'current_amount' => '1000.00',
        ]);

        $r = app(CurrencyRateService::class)->update();

        self::assertSame('coingecko', $r['source']);
        self::assertSame(1, CurrencyRate::query()->where('currency', 'BTC')->count(), 'uma cotação de BTC no dia');
        self::assertSame(3, CurrencyRate::query()->count());
        self::assertSame(1, $r['repriced']);
        self::assertSame('18259.10', $btc->fresh()->current_amount, '0,05216887 x 350.000,00');
        self::assertSame('1000.00', $reais->fresh()->current_amount, 'o que está em reais não é tocado');
    }

    public function test_rodar_de_novo_no_mesmo_dia_atualiza_sem_duplicar(): void
    {
        $this->fakeAwesome();
        app(CurrencyRateService::class)->update();

        $this->fakeAwesome(['USDT' => '5.50', 'BTC' => '360000.00', 'ETH' => '16000.00']);
        app(CurrencyRateService::class)->update();

        self::assertSame(3, CurrencyRate::query()->count());
        self::assertSame('360000.00000000', app(CurrencyRateService::class)->latest('BTC'));
    }

    public function test_cai_para_a_proxima_fonte_e_sem_nenhuma_mantem_as_cotacoes_antigas(): void
    {
        $this->semerCotacoes();

        $this->fontes = [
            'api.coingecko.com' => [429, 'limite'],
            'api.binance.com' => [200, [['symbol' => 'USDTBRL', 'price' => '5.60000000'], ['symbol' => 'BTCBRL', 'price' => '400000.00000000'], ['symbol' => 'ETHBRL', 'price' => '17000.00000000']]],
        ];
        $this->responder();

        self::assertSame('binance', app(CurrencyRateService::class)->update()['source']);
        self::assertSame('400000.00000000', app(CurrencyRateService::class)->latest('BTC'));

        // Última fonte: a AwesomeAPI não tem USDT, então vale o dólar comercial.
        $this->fontes = [
            'api.coingecko.com' => [500, 'erro'], 'api.binance.com' => [451, 'indisponível'],
            'economia.awesomeapi.com.br' => [200, ['USDBRL' => ['bid' => '4.99'], 'BTCBRL' => ['bid' => '410000'], 'ETHBRL' => ['bid' => '17500']]],
        ];
        $r = app(CurrencyRateService::class)->update(CarbonImmutable::parse('2026-10-22'));
        self::assertSame('awesomeapi', $r['source']);
        self::assertSame('4.99', $r['rates']['USDT']);

        $this->fontes = ['api.coingecko.com' => [500, 'erro'], 'api.binance.com' => [500, 'erro'], 'economia.awesomeapi.com.br' => [500, 'erro']];
        self::assertNull(app(CurrencyRateService::class)->update(CarbonImmutable::parse('2026-10-23')));
        self::assertSame('410000.00000000', app(CurrencyRateService::class)->latest('BTC'), 'continua a última boa');

        $this->artisan('cerne:update-currency-rates')->assertFailed();
    }

    public function test_resposta_incompleta_ou_zerada_nao_vira_cotacao(): void
    {
        $this->fontes = [
            'api.coingecko.com' => [200, ['tether' => ['brl' => 5.6], 'bitcoin' => ['brl' => 0]]],
            'api.binance.com' => [200, [['symbol' => 'USDTBRL', 'price' => '5.4']]],
            'economia.awesomeapi.com.br' => [200, ['USDBRL' => ['bid' => '4.99']]],
        ];
        $this->responder();

        self::assertNull(app(CurrencyRateService::class)->update());
        self::assertSame(0, CurrencyRate::query()->count());
    }

    public function test_o_comando_mostra_as_cotacoes(): void
    {
        $this->fakeAwesome();

        $this->artisan('cerne:update-currency-rates')->expectsOutputToContain('Investimentos recalculados')->assertSuccessful();
    }

    public function test_a_cotacao_so_esta_em_dia_com_as_tres_moedas_recentes(): void
    {
        $servico = app(CurrencyRateService::class);

        self::assertFalse($servico->isFresh(), 'sem cotação nenhuma');

        $this->semerCotacoes();
        self::assertTrue($servico->isFresh());
        self::assertTrue($servico->isFresh(CarbonImmutable::parse('2026-10-24')), 'até 3 dias');
        self::assertFalse($servico->isFresh(CarbonImmutable::parse('2026-10-25')));
    }

    public function test_a_rotina_esta_agendada_as_tres_da_manha_antes_da_foto_mensal(): void
    {
        $evento = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn ($e) => $e->description === 'cotacoes-moedas');

        self::assertNotNull($evento);
        self::assertSame('0 3 * * *', $evento->expression);
    }

    public function test_reajustar_a_cotacao_derruba_o_cache_do_painel(): void
    {
        $this->semerCotacoes();
        [$usuario, $perfil, $membro] = $this->cliente();
        InvestmentRecord::create([
            'profile_id' => $perfil->id, 'member_id' => $membro->id, 'sector' => AssetClass::Btc->sector(), 'asset_class' => AssetClass::Btc,
            'name' => 'BTC', 'native_amount' => '1.0000000000', 'current_amount' => '350000.00',
        ]);
        $this->entrar($usuario, $perfil, $membro);

        self::assertSame('350000.00', app(DashboardService::class)->overview()['patrimonio']['investimentos']);

        $this->fakeAwesome(['USDT' => '5.40', 'BTC' => '400000.00', 'ETH' => '15000.00']);
        app(CurrencyRateService::class)->update();

        self::assertSame('400000.00', app(DashboardService::class)->overview()['patrimonio']['investimentos'], 'o painel não serve o número de ontem');
    }

    // --- Cadastro na tela de Investimentos ----------------------------------------------------------------------------------

    public function test_cadastrar_btc_pela_tela_guarda_a_quantidade_e_o_valor_em_reais_do_dia(): void
    {
        $this->semerCotacoes();
        [$usuario, $perfil, $membro] = $this->cliente();
        $this->entrar($usuario, $perfil, $membro);

        Livewire::test(InvestmentsIndex::class)
            ->call('toggleInvestmentForm')
            ->set('investmentName', 'Carteira Onil · BTC')->set('investmentAssetClass', 'btc')->set('investmentMemberId', $membro->id)
            ->set('investmentInstitution', 'Onil')->set('investmentNativeAmount', '0.05216887')
            ->call('saveInvestment')->assertHasNoErrors();

        $r = InvestmentRecord::query()->firstOrFail();
        self::assertSame('BTC', $r->currency);
        self::assertSame('0.0521688700', $r->native_amount);
        self::assertSame('18259.10', $r->current_amount, '0,05216887 x 350.000,00');
        self::assertSame('Onil', $r->institution);
    }

    public function test_a_tela_pede_a_quantidade_e_avisa_quando_ainda_nao_ha_cotacao(): void
    {
        [$usuario, $perfil, $membro] = $this->cliente();
        $this->entrar($usuario, $perfil, $membro);

        $tela = Livewire::test(InvestmentsIndex::class)->call('toggleInvestmentForm')
            ->set('investmentName', 'ETH')->set('investmentAssetClass', 'eth')->set('investmentMemberId', $membro->id);

        $tela->call('saveInvestment')->assertHasErrors('investmentNativeAmount');

        $tela->set('investmentNativeAmount', '1.5')->call('saveInvestment')->assertHasErrors('investmentNativeAmount');
        self::assertSame(0, InvestmentRecord::query()->count(), 'sem cotação nada é gravado');
    }

    public function test_editar_a_quantidade_refaz_o_valor_em_reais(): void
    {
        $this->semerCotacoes();
        [$usuario, $perfil, $membro] = $this->cliente();
        $this->entrar($usuario, $perfil, $membro);

        $r = InvestmentRecord::create([
            'profile_id' => $perfil->id, 'member_id' => $membro->id, 'sector' => AssetClass::Usdt->sector(), 'asset_class' => AssetClass::Usdt,
            'name' => 'USDT', 'native_amount' => '100.0000000000', 'current_amount' => '540.00', 'invested_amount' => '500.00',
        ]);

        Livewire::test(InvestmentsIndex::class)->call('editInvestment', $r->id)
            ->assertSet('investmentNativeAmount', '100.0000000000')
            ->set('investmentNativeAmount', '1039.20')->call('saveInvestment')->assertHasNoErrors();

        $r->refresh();
        self::assertSame('1039.2000000000', $r->native_amount);
        self::assertSame('5611.68', $r->current_amount);
        self::assertSame('500.00', $r->invested_amount, 'o custo não é tocado');
    }

    public function test_o_saldo_em_reais_da_onil_cadastra_como_valor_normal(): void
    {
        [$usuario, $perfil, $membro] = $this->cliente();
        $this->entrar($usuario, $perfil, $membro);

        Livewire::test(InvestmentsIndex::class)->call('toggleInvestmentForm')
            ->set('investmentName', 'Carteira Onil · BRL')->set('investmentAssetClass', 'brl')->set('investmentMemberId', $membro->id)
            ->set('investmentCurrentAmount', '37018.42')->call('saveInvestment')->assertHasNoErrors();

        $r = InvestmentRecord::query()->firstOrFail();
        self::assertSame('BRL', $r->currency);
        self::assertNull($r->native_amount);
        self::assertSame('37018.42', $r->current_amount);
    }

    // --- O resumo ------------------------------------------------------------------------------------------------------------

    private function carteiraComMoedas(): array
    {
        $this->semerCotacoes();
        [$usuario, $perfil, $membro] = $this->cliente();

        foreach ([[AssetClass::Brl, null, '1000.00'], [AssetClass::Usdt, '1039.2', '5611.68'], [AssetClass::Btc, '0.05216887', '18259.11'], [AssetClass::Eth, '0.73479761', '11021.96']] as [$classe, $nativo, $reais]) {
            InvestmentRecord::create([
                'profile_id' => $perfil->id, 'member_id' => $membro->id, 'sector' => $classe->sector(), 'asset_class' => $classe,
                'name' => $classe->label(), 'native_amount' => $nativo, 'current_amount' => $reais, 'institution' => 'Onil',
            ]);
        }

        $this->entrar($usuario, $perfil, $membro);

        return [$usuario, $perfil, $membro];
    }

    public function test_o_painel_soma_tudo_em_reais_e_oferece_o_filtro_por_moeda(): void
    {
        $this->carteiraComMoedas();

        self::assertSame('35892.75', app(DashboardService::class)->overview()['patrimonio']['investimentos']);

        Livewire::test(Dashboard::class)
            ->assertSee('Em reais')->assertSee('Por moeda')
            ->assertSee('R$ 35.892,75')->assertDontSee('BTC 0,05216887')
            ->call('exibir', 'moedas')
            ->assertSee('BTC 0,05216887')->assertSee('ETH 0,73479761')->assertSee('USDT 1.039,20')->assertSee('Bitcoin')
            ->assertSee('Tudo em reais, líquido das faturas em aberto');
    }

    public function test_o_painel_lembra_a_escolha_e_so_mostra_o_filtro_a_quem_tem_moeda(): void
    {
        $this->carteiraComMoedas();

        Livewire::test(Dashboard::class)->call('exibir', 'moedas');
        Livewire::test(Dashboard::class)->assertSet('exibicao', 'moedas')->call('exibir', 'qualquer')->assertSet('exibicao', 'reais');

        [$usuario, $perfil, $membro] = $this->cliente(null, 'so-reais@teste.com');
        InvestmentRecord::create([
            'profile_id' => $perfil->id, 'member_id' => $membro->id, 'sector' => AssetClass::Cdb->sector(), 'asset_class' => AssetClass::Cdb,
            'name' => 'CDB', 'current_amount' => '1000.00',
        ]);
        $this->entrar($usuario, $perfil, $membro);
        session(['dashboard_exibicao' => 'moedas']);

        Livewire::test(Dashboard::class)->assertDontSee('Por moeda')->assertDontSee('USDT');
    }

    public function test_a_tela_de_investimentos_tem_o_mesmo_filtro_e_mostra_a_quantidade_em_cada_ativo(): void
    {
        $this->carteiraComMoedas();

        Livewire::test(InvestmentsIndex::class)
            ->assertSee('Em reais')->assertSee('Por moeda')
            ->assertSee('BTC 0,05216887')->assertSee('≈ R$ 18.259,11')
            ->set('currencyView', 'moedas')
            ->assertSee('Bitcoin')->assertSee('Tudo em reais: R$ 35.892,75')->assertDontSee('Custo de aquisição');
    }

    // --- A carga da Onil -----------------------------------------------------------------------------------------------------

    private function consultor(): User
    {
        return User::factory()->consultant()->create(['email' => 'consultor@teste.com']);
    }

    /** @return array<string, mixed> */
    private function linha(string $id, string $nome, string $email, array $saldo = [], array $saque = []): array
    {
        $zero = ['BRL' => '0', 'USDT' => '0', 'BTC' => '0', 'ETH' => '0'];

        return ['onil_id' => $id, 'nome' => $nome, 'email' => $email, 'saldo' => $saldo + $zero, 'saque' => $saque + $zero];
    }

    private function registro(string $email, string $moeda): ?InvestmentRecord
    {
        $perfilId = FinancialProfile::query()->where('owner_user_id', User::where('email', $email)->value('id'))->value('id');

        return InvestmentRecord::withoutGlobalScopes()->where('profile_id', $perfilId)->where('external_asset_id', 'like', "onil:%:{$moeda}")->first();
    }

    public function test_a_carga_soma_saldo_e_saque_e_grava_um_investimento_por_moeda_com_a_onil_como_instituicao(): void
    {
        $this->semerCotacoes();
        $consultor = $this->consultor();
        $this->cliente($consultor, 'grazi@teste.com');

        $relatorio = app(OnilPositionsImporter::class)->run($consultor, [
            $this->linha('71080', 'Grazielle', 'grazi@teste.com', ['BTC' => '0.01'], ['BRL' => '42605.52', 'USDT' => '5298.56', 'BTC' => '0.09060364', 'ETH' => '2.93416404']),
        ], aplicar: true);

        self::assertSame('existente', $relatorio[0]['conta']);

        $brl = $this->registro('grazi@teste.com', 'BRL');
        self::assertSame('42605.52', $brl->current_amount);
        self::assertSame(AssetClass::Brl, $brl->asset_class);
        self::assertSame('Onil', $brl->institution);
        self::assertSame('Carteira Onil · BRL', $brl->name);

        $btc = $this->registro('grazi@teste.com', 'BTC');
        self::assertSame('0.1006036400', $btc->native_amount, 'saldo 0,01 + saque 0,09060364');
        self::assertSame('35211.27', $btc->current_amount);
        self::assertSame('5298.5600000000', $this->registro('grazi@teste.com', 'USDT')->native_amount);
        self::assertSame('2.9341640400', $this->registro('grazi@teste.com', 'ETH')->native_amount);
        self::assertSame('35211.27', $btc->invested_amount, 'o custo entra uma vez, no cadastro');
        self::assertFalse((bool) $brl->is_private);
    }

    public function test_cliente_sem_conta_e_cadastrado_com_vinculo_ativo_sem_mandar_email(): void
    {
        $this->semerCotacoes();
        \Illuminate\Support\Facades\Mail::fake();
        $consultor = $this->consultor();

        $r = app(OnilPositionsImporter::class)->run($consultor, [$this->linha('73440', 'André Santi', 'Andre@Teste.com', ['BTC' => '0.05216887'])], aplicar: true);

        self::assertSame('criada', $r[0]['conta']);

        $usuario = User::where('email', 'andre@teste.com')->firstOrFail();
        self::assertSame(UserRole::Client, $usuario->role);
        self::assertNull($usuario->email_verified_at, 'ninguém confirmou esse e-mail');
        self::assertNotSame('', $usuario->password);

        $perfil = FinancialProfile::where('owner_user_id', $usuario->id)->firstOrFail();
        self::assertSame(ProfileType::Single, $perfil->profile_type);
        self::assertSame(MemberRole::Primary, ProfileMember::where('profile_id', $perfil->id)->firstOrFail()->role);
        self::assertSame(ConsultantClientStatus::Active, ConsultantClient::where('consultant_id', $consultor->id)->where('client_id', $usuario->id)->firstOrFail()->status);
        self::assertNotNull($this->registro('andre@teste.com', 'BTC'));

        \Illuminate\Support\Facades\Mail::assertNothingSent();
        \Illuminate\Support\Facades\Mail::assertNothingQueued();
    }

    public function test_cliente_com_tudo_zerado_e_cadastrado_mas_nao_ganha_investimento(): void
    {
        $consultor = $this->consultor();

        app(OnilPositionsImporter::class)->run($consultor, [$this->linha('86098', 'Clayton', 'clayton@teste.com')], aplicar: true);

        self::assertNotNull(User::where('email', 'clayton@teste.com')->first());
        self::assertSame(0, InvestmentRecord::withoutGlobalScopes()->count());
    }

    public function test_a_mesclagem_soma_a_empresa_na_conta_da_pessoa_e_a_empresa_nao_e_cadastrada(): void
    {
        $this->semerCotacoes();
        $consultor = $this->consultor();
        $this->cliente($consultor, 'priscila@teste.com');

        app(OnilPositionsImporter::class)->run($consultor, [
            $this->linha('72961', 'Happy Agencia', 'happy@teste.com', [], ['BRL' => '219943.2', 'USDT' => '43977.44']),
            $this->linha('73062', 'Priscila', 'priscila@teste.com', [], ['BRL' => '20772.42']),
        ], ['happy@teste.com' => 'priscila@teste.com'], aplicar: true);

        self::assertSame('240715.62', $this->registro('priscila@teste.com', 'BRL')->current_amount);
        self::assertSame('43977.4400000000', $this->registro('priscila@teste.com', 'USDT')->native_amount);
        self::assertNull(User::where('email', 'happy@teste.com')->first(), 'a empresa não vira cliente');
        self::assertSame(2, InvestmentRecord::withoutGlobalScopes()->count());

        $priscila = ProfileMember::where('user_id', User::where('email', 'priscila@teste.com')->value('id'))->firstOrFail();
        self::assertSame($priscila->id, $this->registro('priscila@teste.com', 'BRL')->member_id, '100% da Priscila');
    }

    public function test_mesclar_exige_os_dois_no_arquivo(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(OnilPositionsImporter::class)->run($this->consultor(), [$this->linha('1', 'A', 'a@teste.com')], ['x@teste.com' => 'a@teste.com']);
    }

    public function test_rodar_de_novo_atualiza_em_vez_de_duplicar_e_desativa_quem_zerou(): void
    {
        $this->semerCotacoes();
        $consultor = $this->consultor();
        $this->cliente($consultor, 'c@teste.com');
        $importador = app(OnilPositionsImporter::class);

        $importador->run($consultor, [$this->linha('1', 'C', 'c@teste.com', ['BRL' => '100', 'BTC' => '0.5'])], aplicar: true);
        $importador->run($consultor, [$this->linha('1', 'C', 'c@teste.com', ['BRL' => '250.5'])], aplicar: true);

        self::assertSame(2, InvestmentRecord::withoutGlobalScopes()->count(), 'nada duplicou');
        self::assertSame('250.50', $this->registro('c@teste.com', 'BRL')->current_amount);
        self::assertSame('100.00', $this->registro('c@teste.com', 'BRL')->invested_amount, 'o custo do cadastro não é refeito');

        $btc = $this->registro('c@teste.com', 'BTC');
        self::assertFalse($btc->is_active, 'zerou: sai do patrimônio');
        self::assertSame('0.00', $btc->current_amount);
    }

    public function test_a_simulacao_nao_grava_nada(): void
    {
        $this->semerCotacoes();
        $consultor = $this->consultor();

        $r = app(OnilPositionsImporter::class)->run($consultor, [$this->linha('1', 'Novo', 'novo@teste.com', ['BRL' => '10'])]);

        self::assertSame('criar', $r[0]['conta']);
        self::assertSame('criar', $r[0]['posicoes']['BRL']['acao']);
        self::assertNull(User::where('email', 'novo@teste.com')->first());
        self::assertSame(0, InvestmentRecord::withoutGlobalScopes()->count());
    }

    public function test_sem_cotacao_a_moeda_e_pulada_com_aviso_e_o_resto_segue(): void
    {
        $consultor = $this->consultor();
        $this->cliente($consultor, 'c@teste.com');

        $r = app(OnilPositionsImporter::class)->run($consultor, [$this->linha('1', 'C', 'c@teste.com', ['BRL' => '100', 'BTC' => '0.5'])], aplicar: true);

        self::assertSame('sem cotação', $r[0]['posicoes']['BTC']['acao']);
        self::assertStringContainsString('cerne:update-currency-rates', $r[0]['aviso']);
        self::assertNull($this->registro('c@teste.com', 'BTC'));
        self::assertNotNull($this->registro('c@teste.com', 'BRL'));
    }

    public function test_conta_sem_vinculo_ativo_nao_e_vinculada_nem_recebe_investimento(): void
    {
        $consultor = $this->consultor();
        $this->cliente(null, 'solto@teste.com');

        $r = app(OnilPositionsImporter::class)->run($consultor, [$this->linha('1', 'Solto', 'solto@teste.com', ['BRL' => '100'])], aplicar: true);

        self::assertSame('nenhum', $r[0]['vinculo']);
        self::assertStringContainsString('sem vínculo ativo', $r[0]['aviso']);
        self::assertSame(0, ConsultantClient::query()->count());
        self::assertSame(0, InvestmentRecord::withoutGlobalScopes()->count());
    }

    public function test_e_mail_de_quem_nao_e_cliente_fica_de_fora(): void
    {
        $consultor = $this->consultor();
        User::factory()->consultant()->create(['email' => 'outro@teste.com']);

        $r = app(OnilPositionsImporter::class)->run($consultor, [$this->linha('1', 'Outro', 'outro@teste.com', ['BRL' => '100'])], aplicar: true);

        self::assertStringContainsString('não é de cliente', $r[0]['aviso']);
        self::assertSame(0, InvestmentRecord::withoutGlobalScopes()->count());
    }

    public function test_o_que_ja_estava_cadastrado_a_mao_nao_e_tocado(): void
    {
        $this->semerCotacoes();
        $consultor = $this->consultor();
        [, $perfil, $membro] = $this->cliente($consultor, 'hugo@teste.com');
        $antigo = InvestmentRecord::create([
            'profile_id' => $perfil->id, 'member_id' => $membro->id, 'sector' => AssetClass::Cripto->sector(), 'asset_class' => AssetClass::Cripto,
            'name' => 'Contrato ONIL', 'institution' => 'Onil', 'current_amount' => '14478.46', 'invested_amount' => '10048.00',
        ]);

        app(OnilPositionsImporter::class)->run($consultor, [$this->linha('74631', 'Hugo', 'hugo@teste.com', ['BRL' => '14680.11'])], aplicar: true);

        self::assertSame('14478.46', $antigo->fresh()->current_amount);
        self::assertTrue($antigo->fresh()->is_active);
        self::assertSame(2, InvestmentRecord::withoutGlobalScopes()->count());
    }

    public function test_o_comando_simula_mescla_pula_e_aplica(): void
    {
        $this->semerCotacoes();
        $consultor = $this->consultor();
        $this->cliente($consultor, 'priscila@teste.com');
        $this->cliente($consultor, 'hugo@teste.com');

        $arquivo = sys_get_temp_dir().'/onil-'.uniqid().'.json';
        file_put_contents($arquivo, json_encode([
            $this->linha('72961', 'Happy', 'happy@teste.com', [], ['BRL' => '100']),
            $this->linha('73062', 'Priscila', 'priscila@teste.com', [], ['BRL' => '50']),
            $this->linha('74631', 'Hugo', 'hugo@teste.com', [], ['BRL' => '999']),
        ]));

        $base = ['json' => $arquivo, '--consultant' => 'consultor@teste.com', '--merge' => ['happy@teste.com:priscila@teste.com'], '--skip' => ['hugo@teste.com']];

        $this->artisan('cerne:import-onil', $base)->expectsOutputToContain('Simulação')->assertSuccessful();
        self::assertSame(0, InvestmentRecord::withoutGlobalScopes()->count());

        $this->artisan('cerne:import-onil', $base + ['--apply' => true])->expectsOutputToContain('Gravado')->assertSuccessful();
        self::assertSame('150.00', $this->registro('priscila@teste.com', 'BRL')->current_amount);
        self::assertNull($this->registro('hugo@teste.com', 'BRL'), 'a pessoa pulada fica como estava');

        $this->artisan('cerne:import-onil', ['json' => $arquivo, '--consultant' => 'ninguem@teste.com'])->assertFailed();
        @unlink($arquivo);
    }

    public function test_a_criacao_direta_de_cliente_nao_repete_um_e_mail_ja_cadastrado(): void
    {
        $consultor = $this->consultor();
        $servico = app(ClientOnboardingService::class);

        $servico->createManagedClient($consultor, 'Fulano', 'fulano@teste.com');

        $this->expectException(\Illuminate\Database\QueryException::class);
        $servico->createManagedClient($consultor, 'Fulano de novo', 'fulano@teste.com');
    }
}
