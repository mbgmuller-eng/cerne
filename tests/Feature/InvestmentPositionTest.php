<?php

namespace Tests\Feature;

use App\Enums\AssetClass;
use App\Enums\InvestmentSector;
use App\Enums\TransactionType;
use App\Livewire\Investments\InvestmentsIndex;
use App\Livewire\Investments\PositionActions;
use App\Models\FinancialProfile;
use App\Models\InvestmentRecord;
use App\Models\InvestmentSnapshot;
use App\Models\InvestmentTransaction;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\InvestmentTransactionService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Posição em cotas: comprar mais (preço médio), vender, atualizar a cotação e informar as cotas de um
 * ativo que foi cadastrado só pelo valor total. Nenhuma dessas operações mexe em saldo de conta.
 */
class InvestmentPositionTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private FinancialProfile $perfil;

    private ProfileMember $membro;

    private InvestmentTransactionService $servico;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-07 09:00:00'));

        $this->usuario = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->usuario->id]);
        $this->membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuario->id]);
        $this->actingAs($this->usuario);
        app(ProfileContext::class)->set($this->perfil, $this->membro);

        $this->servico = app(InvestmentTransactionService::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** FII com posição: compra inicial de $qtd cotas a $preco, na $data, com a cotação igual ao preço pago. */
    private function ativoComPosicao(string $qtd = '100', string $preco = '10.00', string $data = '2026-09-01', array $extra = []): InvestmentRecord
    {
        $total = bcmul($qtd, $preco, 2);

        $ativo = InvestmentRecord::factory()->for($this->perfil, 'profile')->for($this->membro, 'member')->create($extra + [
            'name' => 'Fundo Logístico', 'ticker' => 'XPLG11', 'asset_class' => AssetClass::Fii, 'sector' => InvestmentSector::VariableIncome,
            'current_amount' => $total, 'invested_amount' => null, 'quantity' => null,
        ]);

        $this->servico->record($ativo, [
            'type' => TransactionType::Buy, 'quantity' => $qtd, 'unit_price' => $preco, 'total_amount' => $total,
            'operation_date' => CarbonImmutable::parse($data),
        ], $this->usuario->id);

        $this->servico->seedQuote($ativo->refresh(), $preco, CarbonImmutable::parse($data));

        return $ativo->refresh();
    }

    /** FII cadastrado só pelo valor total, como os que já existem (sem cotas). */
    private function ativoLegado(): InvestmentRecord
    {
        return InvestmentRecord::factory()->for($this->perfil, 'profile')->for($this->membro, 'member')->create([
            'name' => 'BDIF11', 'asset_class' => AssetClass::Fii, 'sector' => InvestmentSector::VariableIncome,
            'current_amount' => '9812.69', 'invested_amount' => '10111.50', 'purchase_date' => '2025-04-01', 'quantity' => null,
        ]);
    }

    // ---- comprar mais

    public function test_comprar_mais_recalcula_o_preco_medio_com_as_taxas_e_leva_a_cotacao_para_o_preco_pago(): void
    {
        $ativo = $this->ativoComPosicao('100', '10.00');

        $tx = $this->servico->trade($ativo, TransactionType::Buy, '50', '16.00', '5', CarbonImmutable::parse('2026-10-07'), $this->usuario->id);

        $ativo->refresh();
        self::assertSame('150.000000', $ativo->quantity);
        self::assertSame('12.033333', $ativo->average_price, '(100 x 10 + 50 x 16 + 5 de taxa) / 150');
        self::assertSame('1805.00', $ativo->invested_amount);
        self::assertSame('16.000000', $ativo->current_price);
        self::assertSame('2026-10-07', $ativo->price_date->toDateString());
        self::assertSame('2400.00', $ativo->current_amount, '150 cotas x R$ 16');
        self::assertSame('805.00', $tx->net_amount);
        self::assertSame('5.00', $tx->broker_fee);

        $foto = InvestmentSnapshot::query()->where(['investment_id' => $ativo->id, 'year' => 2026, 'month' => 10])->sole();
        self::assertSame('2400.00', $foto->amount);
        self::assertSame('150.000000', $foto->quantity);
    }

    public function test_compra_nao_mexe_em_saldo_de_conta(): void
    {
        $conta = \App\Models\BankAccount::factory()->for($this->perfil, 'profile')->create(['current_balance' => '1000.00']);
        $ativo = $this->ativoComPosicao();

        $this->servico->trade($ativo, TransactionType::Buy, '10', '12.00', null, CarbonImmutable::parse('2026-10-07'), $this->usuario->id);

        self::assertSame('1000.00', $conta->fresh()->current_balance);
    }

    public function test_compra_retroativa_refaz_a_posicao_pela_ordem_das_datas_e_nao_volta_a_cotacao(): void
    {
        $ativo = $this->ativoComPosicao('100', '10.00', '2026-09-01');
        $this->servico->trade($ativo, TransactionType::Sell, '50', '12.00', null, CarbonImmutable::parse('2026-09-10'), $this->usuario->id);
        $this->servico->trade($ativo->refresh(), TransactionType::Buy, '50', '20.00', null, CarbonImmutable::parse('2026-10-01'), $this->usuario->id);

        // Em ordem de chegada daria 22,5; pela ordem das datas (compra de 30 antes da venda) dá 20.
        $this->servico->trade($ativo->refresh(), TransactionType::Buy, '100', '30.00', null, CarbonImmutable::parse('2026-09-05'), $this->usuario->id);

        $ativo->refresh();
        self::assertSame('200.000000', $ativo->quantity);
        self::assertSame('20.000000', $ativo->average_price);
        self::assertSame('20.000000', $ativo->current_price, 'a cotação mais nova (01/10) continua valendo');
        self::assertSame('2026-10-01', $ativo->price_date->toDateString());
        self::assertSame('4000.00', $ativo->current_amount);
    }

    public function test_quantidade_ou_preco_zerados_sao_recusados(): void
    {
        $ativo = $this->ativoComPosicao();

        foreach ([['0', '10'], ['5', '0'], ['-1', '10']] as [$qtd, $preco]) {
            try {
                $this->servico->trade($ativo, TransactionType::Buy, $qtd, $preco, null, CarbonImmutable::parse('2026-10-07'), $this->usuario->id);
                self::fail('deveria recusar');
            } catch (\InvalidArgumentException) {
                // esperado
            }
        }

        self::assertSame(1, InvestmentTransaction::query()->count());
    }

    // ---- vender

    public function test_venda_reduz_cotas_mantem_o_preco_medio_e_leva_a_cotacao_para_o_preco_recebido(): void
    {
        $ativo = $this->ativoComPosicao('100', '10.00');
        $this->servico->trade($ativo, TransactionType::Buy, '50', '16.00', '5', CarbonImmutable::parse('2026-10-06'), $this->usuario->id);

        $tx = $this->servico->trade($ativo->refresh(), TransactionType::Sell, '30', '20.00', '10', CarbonImmutable::parse('2026-10-07'), $this->usuario->id);

        $ativo->refresh();
        self::assertSame('120.000000', $ativo->quantity);
        self::assertSame('12.033333', $ativo->average_price, 'vender não muda o preço médio');
        self::assertSame('1444.00', $ativo->invested_amount, '120 cotas x preço médio');
        self::assertSame('2400.00', $ativo->current_amount, '120 x R$ 20');
        self::assertSame('590.00', $tx->net_amount, '30 x 20 menos 10 de taxa');
    }

    public function test_vender_mais_do_que_tem_e_recusado_e_nada_e_gravado(): void
    {
        $ativo = $this->ativoComPosicao('100', '10.00');

        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->servico->trade($ativo, TransactionType::Sell, '101', '10.00', null, CarbonImmutable::parse('2026-10-07'), $this->usuario->id);
        } finally {
            self::assertSame(1, InvestmentTransaction::query()->count());
            self::assertSame('100.000000', $ativo->fresh()->quantity);
        }
    }

    public function test_venda_retroativa_que_deixaria_a_posicao_negativa_desfaz_tudo(): void
    {
        $ativo = $this->ativoComPosicao('100', '10.00', '2026-09-10');

        try {
            // Hoje há 100 cotas, mas em 01/09 ainda não havia nenhuma.
            $this->servico->trade($ativo, TransactionType::Sell, '60', '10.00', null, CarbonImmutable::parse('2026-09-01'), $this->usuario->id);
            self::fail('deveria recusar');
        } catch (\InvalidArgumentException) {
            // esperado
        }

        self::assertSame(1, InvestmentTransaction::query()->count());
        $ativo->refresh();
        self::assertSame('100.000000', $ativo->quantity);
        self::assertSame('10.000000', $ativo->average_price);
    }

    public function test_vender_tudo_zera_a_posicao_e_o_ativo_continua_listado(): void
    {
        $ativo = $this->ativoComPosicao('100', '10.00');
        $this->servico->trade($ativo, TransactionType::Sell, '100', '12.00', null, CarbonImmutable::parse('2026-10-07'), $this->usuario->id);

        $ativo->refresh();
        self::assertSame('0.000000', $ativo->quantity);
        self::assertSame('0.00', $ativo->current_amount);
        self::assertTrue($ativo->is_active);
        self::assertFalse($ativo->hasPosition());
        self::assertFalse($ativo->needsQuantity(), 'já negociou: o caminho é comprar de novo, não informar cotas');

        Livewire::test(InvestmentsIndex::class)->assertSee('posição zerada')->assertSee('Comprar mais')->assertDontSee('Informar cotas');
    }

    // ---- cotação

    public function test_atualizar_a_cotacao_recalcula_o_valor_e_a_foto_do_mes(): void
    {
        $ativo = $this->ativoComPosicao('37', '250.00', '2026-09-01');

        $this->servico->updateQuote($ativo, '265.21', CarbonImmutable::parse('2026-10-07'));

        $ativo->refresh();
        self::assertSame('265.210000', $ativo->current_price);
        self::assertSame('9812.77', $ativo->current_amount);
        self::assertSame('9250.00', $ativo->invested_amount, 'o custo não muda com a cotação');
        $foto = InvestmentSnapshot::query()->where(['investment_id' => $ativo->id, 'year' => 2026, 'month' => 10])->sole();
        self::assertSame('9812.77', $foto->amount);
        self::assertSame('37.000000', $foto->quantity);
    }

    public function test_cotacao_de_um_mes_passado_so_corrige_a_foto_daquele_mes(): void
    {
        $ativo = $this->ativoComPosicao('37', '250.00', '2026-09-01');
        $this->servico->updateQuote($ativo, '265.21', CarbonImmutable::parse('2026-10-07'));

        $this->servico->updateQuote($ativo->refresh(), '240.00', CarbonImmutable::parse('2026-08-20'));

        $ativo->refresh();
        self::assertSame('265.210000', $ativo->current_price, 'o valor de agora não volta no tempo');
        self::assertSame('9812.77', $ativo->current_amount);
        $agosto = InvestmentSnapshot::query()->where(['investment_id' => $ativo->id, 'year' => 2026, 'month' => 8])->sole();
        self::assertSame('8880.00', $agosto->amount, '37 x 240');
    }

    public function test_cotar_ativo_sem_cotas_e_recusado(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->servico->updateQuote($this->ativoLegado(), '10.00', CarbonImmutable::parse('2026-10-07'));
    }

    // ---- informar cotas de um ativo cadastrado só pelo valor

    public function test_informar_cotas_mantem_o_valor_atual_e_usa_o_investido_como_custo(): void
    {
        $ativo = $this->ativoLegado();

        $tx = $this->servico->setInitialPosition($ativo, '38', null, CarbonImmutable::parse('2025-04-01'), $this->usuario->id);

        $ativo->refresh();
        self::assertSame('38.000000', $ativo->quantity);
        self::assertSame('10111.50', $ativo->invested_amount, 'o investido que já existia vira o custo');
        self::assertSame('266.092105', $ativo->average_price);
        self::assertSame('9812.69', $ativo->current_amount, 'o valor atual não muda um centavo');
        self::assertSame('258.228684', $ativo->current_price, 'cotação implícita = valor / cotas');
        self::assertSame('2026-10-07', $ativo->price_date->toDateString());
        self::assertSame('2025-04-01', $tx->operation_date->toDateString());
        self::assertSame(TransactionType::Buy, $tx->transaction_type);
    }

    public function test_informar_cotas_com_preco_medio_proprio(): void
    {
        $ativo = $this->ativoLegado();

        $this->servico->setInitialPosition($ativo, '38', '266.10', CarbonImmutable::parse('2025-04-01'), $this->usuario->id);

        $ativo->refresh();
        self::assertSame('10111.80', $ativo->invested_amount, '38 x 266,10');
        self::assertSame('9812.69', $ativo->current_amount);
    }

    public function test_informar_cotas_de_quem_ja_tem_posicao_e_recusado(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->servico->setInitialPosition($this->ativoComPosicao(), '10', null, CarbonImmutable::parse('2026-10-07'), $this->usuario->id);
    }

    // ---- cadastro novo e edição

    public function test_cadastro_novo_com_cotas_guarda_a_cotacao(): void
    {
        Livewire::test(InvestmentsIndex::class)
            ->set('investmentName', 'CSHG Logística')->set('investmentTicker', 'HGLG11')
            ->set('investmentAssetClass', AssetClass::Fii->value)->set('investmentMemberId', $this->membro->id)
            ->set('investmentQuantity', '80')->set('investmentUnitPrice', '150.00')->set('investmentCurrentAmount', '13500.00')
            ->call('saveInvestment')->assertHasNoErrors();

        $ativo = InvestmentRecord::query()->where('ticker', 'HGLG11')->sole();
        self::assertSame('168.750000', $ativo->current_price, '13.500 / 80');
        self::assertSame('2026-10-07', $ativo->price_date->toDateString());
        self::assertSame('13500.00', $ativo->current_amount);
    }

    public function test_editar_o_valor_de_ativo_com_cotas_move_a_cotacao_e_nao_mexe_no_custo(): void
    {
        $ativo = $this->ativoComPosicao('100', '10.00');

        Livewire::test(InvestmentsIndex::class)
            ->call('editInvestment', $ativo->id)
            ->assertSet('investmentHasPosition', true)
            ->set('investmentCurrentAmount', '1250.00')
            ->set('investmentValueDate', '2026-10-05')
            ->call('saveInvestment')->assertHasNoErrors();

        $ativo->refresh();
        self::assertSame('1250.00', $ativo->current_amount);
        self::assertSame('12.500000', $ativo->current_price);
        self::assertSame('2026-10-05', $ativo->price_date->toDateString());
        self::assertSame('1000.00', $ativo->invested_amount, 'o custo continua o das compras');
        self::assertSame('100.000000', InvestmentSnapshot::query()->where(['investment_id' => $ativo->id, 'month' => 10])->sole()->quantity);
    }

    public function test_formulario_de_edicao_de_ativo_com_cotas_nao_oferece_valor_investido(): void
    {
        $ativo = $this->ativoComPosicao();
        $legado = $this->ativoLegado();

        $com = Livewire::test(InvestmentsIndex::class)->call('editInvestment', $ativo->id)->html();
        self::assertStringContainsString('O valor investido vem das compras', $com);
        self::assertStringNotContainsString('investment-field-invested-amount', $com);

        $sem = Livewire::test(InvestmentsIndex::class)->call('editInvestment', $legado->id)->html();
        self::assertStringContainsString('investment-field-invested-amount', $sem);
    }

    // ---- a janela (PositionActions)

    public function test_comprar_mais_pela_janela_mostra_a_previa_e_grava(): void
    {
        $ativo = $this->ativoComPosicao('100', '10.00');

        $tela = Livewire::test(PositionActions::class)
            ->call('open', $ativo->id, 'buy')
            ->assertSet('show', true)
            ->set('quantity', '50')->set('price', '16')->set('fees', '5');

        self::assertSame('R$ 12,033333', $tela->viewData('preview')['Novo preço médio']);
        self::assertSame('R$ 805,00', $tela->viewData('preview')['Custo desta compra']);

        $tela->call('save')->assertHasNoErrors()->assertSet('show', false)->assertDispatched('position-saved');

        self::assertSame('150.000000', $ativo->fresh()->quantity);
    }

    public function test_venda_maior_que_a_posicao_mostra_erro_no_campo(): void
    {
        $ativo = $this->ativoComPosicao('100', '10.00');

        Livewire::test(PositionActions::class)
            ->call('open', $ativo->id, 'sell')
            ->set('quantity', '150')->set('price', '12')
            ->call('save')
            ->assertHasErrors(['quantity']);

        self::assertSame('100.000000', $ativo->fresh()->quantity);
    }

    public function test_venda_retroativa_impossivel_vira_erro_no_campo_e_nada_muda(): void
    {
        $ativo = $this->ativoComPosicao('100', '10.00', '2026-09-10');

        Livewire::test(PositionActions::class)
            ->call('open', $ativo->id, 'sell')
            ->set('quantity', '60')->set('price', '10')->set('date', '2026-09-01')
            ->call('save')
            ->assertHasErrors(['quantity']);

        self::assertSame(1, InvestmentTransaction::query()->count());
    }

    public function test_previa_da_venda_mostra_o_resultado_sobre_o_preco_medio(): void
    {
        $ativo = $this->ativoComPosicao('100', '10.00');

        $previa = Livewire::test(PositionActions::class)
            ->call('open', $ativo->id, 'sell')
            ->set('quantity', '40')->set('price', '12.50')->set('fees', '3')
            ->viewData('preview');

        self::assertSame('R$ 497,00', $previa['Valor recebido (líquido)']);
        self::assertSame('60', $previa['Cotas depois da venda']);
        self::assertSame('+R$ 97,00', $previa['Resultado sobre o preço médio'], '497 recebidos - 40 x 10 de custo');
    }

    public function test_data_no_futuro_e_campos_invalidos_sao_recusados(): void
    {
        $ativo = $this->ativoComPosicao();

        Livewire::test(PositionActions::class)
            ->call('open', $ativo->id, 'buy')
            ->set('quantity', '1')->set('price', '10')->set('date', '2031-01-01')
            ->call('save')->assertHasErrors(['date']);

        foreach ([['', '10'], ['0', '10'], ['1e3', '10'], ['5', ''], ['5', '-2']] as [$qtd, $preco]) {
            Livewire::test(PositionActions::class)
                ->call('open', $ativo->id, 'buy')
                ->set('quantity', $qtd)->set('price', $preco)
                ->call('save')->assertHasErrors();
        }

        self::assertSame(1, InvestmentTransaction::query()->count());
    }

    public function test_atualizar_cotacao_pela_janela(): void
    {
        $ativo = $this->ativoComPosicao('37', '250.00');

        $tela = Livewire::test(PositionActions::class)
            ->call('open', $ativo->id, 'quote')
            ->set('price', '265.21');

        self::assertSame('R$ 9.812,77', $tela->viewData('preview')['Novo valor do ativo']);

        $tela->call('save')->assertHasNoErrors();

        self::assertSame('9812.77', $ativo->fresh()->current_amount);
    }

    public function test_informar_cotas_pela_janela(): void
    {
        $ativo = $this->ativoLegado();

        $tela = Livewire::test(PositionActions::class)
            ->call('open', $ativo->id, 'initial')
            ->assertSet('show', true)
            ->set('quantity', '38');

        self::assertSame('R$ 10.111,50', $tela->viewData('preview')['Custo total']);
        self::assertSame('R$ 9.812,69', $tela->viewData('preview')['Valor atual (mantido)']);

        $tela->call('save')->assertHasNoErrors();

        self::assertSame('38.000000', $ativo->fresh()->quantity);
    }

    public function test_cada_operacao_so_abre_no_estado_certo_do_ativo(): void
    {
        $comPosicao = $this->ativoComPosicao();
        $legado = $this->ativoLegado();
        $cdb = InvestmentRecord::factory()->for($this->perfil, 'profile')->for($this->membro, 'member')->create(['asset_class' => AssetClass::Cdb]);

        // Vender e cotar exigem cotas; informar cotas só para quem não tem.
        Livewire::test(PositionActions::class)->call('open', $legado->id, 'sell')->assertSet('show', false);
        Livewire::test(PositionActions::class)->call('open', $legado->id, 'quote')->assertSet('show', false);
        Livewire::test(PositionActions::class)->call('open', $comPosicao->id, 'initial')->assertSet('show', false);
        // CDB não negocia em cotas: nenhuma operação.
        foreach (['buy', 'sell', 'quote', 'initial'] as $acao) {
            Livewire::test(PositionActions::class)->call('open', $cdb->id, $acao)->assertSet('show', false);
        }
        // Operação desconhecida é ignorada.
        Livewire::test(PositionActions::class)->call('open', $comPosicao->id, 'apagar')->assertSet('show', false);
        // Comprar mais vale para quem tem posição e para quem ainda não informou as cotas.
        Livewire::test(PositionActions::class)->call('open', $comPosicao->id, 'buy')->assertSet('show', true);
    }

    public function test_botoes_da_lista_seguem_o_estado_de_cada_ativo(): void
    {
        $this->ativoComPosicao();
        $this->ativoLegado();
        InvestmentRecord::factory()->for($this->perfil, 'profile')->for($this->membro, 'member')->create(['asset_class' => AssetClass::Cdb, 'name' => 'CDB Banco X']);

        $html = Livewire::test(InvestmentsIndex::class)->html();

        self::assertSame(1, substr_count($html, "action: 'sell'"), 'só o ativo com posição vende');
        self::assertSame(1, substr_count($html, "action: 'quote'"));
        self::assertSame(1, substr_count($html, "action: 'initial'"), 'só o legado informa cotas');
        self::assertSame(1, substr_count($html, "action: 'buy'"), 'o CDB não tem botão nenhum');
    }

    public function test_ativo_de_outro_perfil_nao_abre(): void
    {
        $outroPerfil = FinancialProfile::factory()->create();
        $outroMembro = ProfileMember::factory()->create(['profile_id' => $outroPerfil->id]);
        $alheio = InvestmentRecord::factory()->for($outroPerfil, 'profile')->for($outroMembro, 'member')->create(['asset_class' => AssetClass::Fii]);

        $this->expectException(ModelNotFoundException::class);

        Livewire::test(PositionActions::class)->call('open', $alheio->id, 'buy');
    }

    public function test_ativo_oculto_do_conjuge_nao_abre(): void
    {
        $perfil = FinancialProfile::factory()->couple()->create();
        $ana = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => User::factory()->create()->id]);
        $bruno = ProfileMember::factory()->secondary()->create(['profile_id' => $perfil->id, 'user_id' => User::factory()->create()->id]);
        $oculto = InvestmentRecord::factory()->for($perfil, 'profile')->for($ana, 'member')->create(['asset_class' => AssetClass::Fii, 'is_private' => true, 'quantity' => '10.000000', 'average_price' => '10.000000']);

        $this->actingAs($bruno->user);
        app(ProfileContext::class)->set($perfil, $bruno);

        $this->expectException(ModelNotFoundException::class);

        Livewire::test(PositionActions::class)->call('open', $oculto->id, 'buy');
    }

    public function test_corretor_nao_abre_a_janela(): void
    {
        $corretor = User::factory()->broker()->create();
        $this->actingAs($corretor);
        app(ProfileContext::class)->set($this->perfil, member: null, asConsultant: true);

        Livewire::test(PositionActions::class)->assertStatus(403);
    }

    // ---- o que mexeu no valor das cotas

    public function test_aba_performance_separa_compras_e_vendas_da_variacao_das_cotacoes(): void
    {
        $ativo = $this->ativoComPosicao('100', '10.00', '2026-08-05');
        // O ativoComPosicao já tem uma compra em agosto (1.000); as fotos e demais movimentos vêm abaixo.
        InvestmentSnapshot::query()->delete();
        foreach ([[8, '1000.00', '100'], [9, '1500.00', '140'], [10, '2400.00', '120']] as [$mes, $valor, $qtd]) {
            InvestmentSnapshot::create(['investment_id' => $ativo->id, 'year' => 2026, 'month' => $mes, 'amount' => $valor, 'quantity' => $qtd]);
        }
        foreach ([['buy', '400.00', '2026-09-10'], ['sell', '100.00', '2026-10-02']] as [$tipo, $liquido, $data]) {
            InvestmentTransaction::create([
                'profile_id' => $this->perfil->id, 'member_id' => $this->membro->id, 'investment_id' => $ativo->id,
                'transaction_type' => $tipo, 'quantity' => '10', 'unit_price' => '10', 'total_amount' => $liquido, 'net_amount' => $liquido,
                'operation_date' => $data, 'created_by_user_id' => $this->usuario->id,
            ]);
        }

        $linhas = Livewire::test(InvestmentsIndex::class)->set('tab', 'performance')->viewData('quotaMovement');

        self::assertCount(2, $linhas, 'o primeiro mês não tem com o que comparar');
        // Outubro: valor foi de 1.500 para 2.400 (+900); saiu 100 em vendas, então as cotações valeram +1.000.
        self::assertSame(['valor' => '2400.00', 'variacao' => '900.00', 'aportes' => '-100.00', 'cotacao' => '1000.00'], array_diff_key($linhas[0], ['mes' => 1]));
        // Setembro: 1.000 para 1.500 (+500); entraram 400 em compras, as cotações valeram +100.
        self::assertSame(['valor' => '1500.00', 'variacao' => '500.00', 'aportes' => '400.00', 'cotacao' => '100.00'], array_diff_key($linhas[1], ['mes' => 1]));
    }

    public function test_aba_performance_ignora_ativo_sem_movimentacao(): void
    {
        $cdb = InvestmentRecord::factory()->for($this->perfil, 'profile')->for($this->membro, 'member')->create(['asset_class' => AssetClass::Cdb]);
        foreach ([8, 9, 10] as $mes) {
            InvestmentSnapshot::create(['investment_id' => $cdb->id, 'year' => 2026, 'month' => $mes, 'amount' => '1000.00']);
        }

        self::assertNull(Livewire::test(InvestmentsIndex::class)->set('tab', 'performance')->viewData('quotaMovement'));
    }
}
