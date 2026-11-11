<?php

namespace Tests\Feature;

use App\Enums\AssetClass;
use App\Enums\InvestmentSector;
use App\Enums\TransactionType;
use App\Livewire\Investments\InvestmentEvents;
use App\Livewire\Investments\InvestmentsIndex;
use App\Livewire\Investments\PositionActions;
use App\Models\BankAccount;
use App\Models\FinancialProfile;
use App\Models\InvestmentRecord;
use App\Models\InvestmentTransaction;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\InvestmentTransactionService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Investimento: o quadro "Ao salvar" do cadastro (a compra que um ativo com cotas registra, a data do valor, a reserva) e as dicas dos campos e das
 * telas de comprar, vender, cotação e provento. O texto diz o que o serviço de transações faz de fato, e os testes conferem o que é gravado.
 */
class InvestmentFormConsequenceTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private FinancialProfile $perfil;

    private ProfileMember $membro;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('pt_BR');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-07 09:00:00'));

        $this->usuario = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->usuario->id]);
        $this->membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuario->id]);
        $this->actingAs($this->usuario);
        app(ProfileContext::class)->set($this->perfil, $this->membro);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function ativoComCotas(): InvestmentRecord
    {
        $ativo = InvestmentRecord::factory()->for($this->perfil, 'profile')->for($this->membro, 'member')->create([
            'name' => 'Fundo Logístico', 'ticker' => 'XPLG11', 'asset_class' => AssetClass::Fii, 'sector' => InvestmentSector::VariableIncome,
            'current_amount' => '1000.00', 'invested_amount' => null, 'quantity' => null,
        ]);
        $servico = app(InvestmentTransactionService::class);
        $servico->record($ativo, ['type' => TransactionType::Buy, 'quantity' => '100', 'unit_price' => '10.00', 'total_amount' => '1000.00', 'operation_date' => CarbonImmutable::parse('2026-09-01')], $this->usuario->id);
        $servico->seedQuote($ativo->refresh(), '10.00', CarbonImmutable::parse('2026-09-01'));

        return $ativo->refresh();
    }

    public function test_novo_ativo_com_cotas_diz_a_compra_registrada_e_nenhuma_conta_e_debitada(): void
    {
        $conta = BankAccount::factory()->for($this->perfil, 'profile')->for($this->membro, 'member')->create(['current_balance' => '1000.00']);

        Livewire::test(InvestmentsIndex::class)
            ->call('toggleInvestmentForm')
            ->set('investmentName', 'Petrobras')->set('investmentAssetClass', AssetClass::Acao->value)->set('investmentMemberId', $this->membro->id)
            ->set('investmentQuantity', '10')->set('investmentUnitPrice', '25.50')
            ->assertSee('Será registrada uma compra de 10 cotas a R$ 25,50, com custo de R$ 255,00. Nenhuma conta é debitada.')
            ->call('saveInvestment')->assertHasNoErrors();

        // O aviso é o que acontece: uma compra de 10 cotas a 25,50 (custo 255,00) e nenhum saldo de conta mexe.
        $compra = InvestmentTransaction::withoutProfileScope()->sole();
        $this->assertSame('255.00', $compra->total_amount);
        $this->assertSame('1000.00', $conta->refresh()->current_balance);
    }

    public function test_quantidade_fracionada_sai_com_virgula(): void
    {
        Livewire::test(InvestmentsIndex::class)
            ->call('toggleInvestmentForm')
            ->set('investmentAssetClass', AssetClass::Acao->value)
            ->set('investmentQuantity', '0.5')->set('investmentUnitPrice', '10.00')
            ->assertSee('Será registrada uma compra de 0,5 cotas a R$ 10,00, com custo de R$ 5,00.');
    }

    public function test_ativo_sem_cotas_nao_diz_nada_de_compra(): void
    {
        Livewire::test(InvestmentsIndex::class)
            ->call('toggleInvestmentForm')
            ->set('investmentAssetClass', AssetClass::Cdb->value)
            ->assertDontSee('Será registrada uma compra');
    }

    public function test_conta_como_reserva_avisa_que_o_ativo_sai_da_alocacao(): void
    {
        Livewire::test(InvestmentsIndex::class)
            ->call('toggleInvestmentForm')
            ->set('investmentReserveType', 'paz')
            ->assertSee('Este ativo passa a compor a reserva de paz e sai da comparação com a alocação recomendada.')
            ->assertSee('Conta como reserva')
            ->assertDontSee('Conta para reserva');
    }

    public function test_editar_o_valor_diz_em_que_mes_a_data_vai_para_o_historico(): void
    {
        $cdb = InvestmentRecord::factory()->for($this->perfil, 'profile')->for($this->membro, 'member')->create(['asset_class' => AssetClass::Cdb, 'current_amount' => '8000.00']);

        Livewire::test(InvestmentsIndex::class)
            ->call('editInvestment', $cdb->id)
            ->set('investmentValueDate', '2026-09-03')
            ->assertSee('O valor de 03/09/2026 será gravado no histórico de setembro de 2026.');
    }

    public function test_as_dicas_do_cadastro_explicam_o_que_cada_campo_faz(): void
    {
        Livewire::test(InvestmentsIndex::class)
            ->call('toggleInvestmentForm')
            ->set('investmentAssetClass', AssetClass::Cdb->value)
            ->assertSee('Ações, FIIs, ETFs, fundos e cripto pedem quantidade e preço. As demais, só valores.')
            ->assertSee('Soma o valor à reserva escolhida e tira o ativo da alocação recomendada.')
            ->assertSee('Só identifica o ativo na lista. Não busca cotação automática.')
            ->assertSee('Em ativo com cotas, é a data da primeira compra. Sem data, vale hoje.')
            ->assertSee('Base do cálculo do ganho. Se vazio, o ganho começa em zero.')
            ->assertSee('Texto livre exibido na lista. Não entra em nenhum cálculo.');
    }

    public function test_comprar_e_vender_explicam_o_efeito_no_valor_do_ativo(): void
    {
        $ativo = $this->ativoComCotas();

        Livewire::test(PositionActions::class)->call('open', $ativo->id, 'buy')
            ->assertSee('O preço pago vira a cotação do ativo (se a data não for anterior à última cotação)')
            ->assertSee('Na compra, soma ao custo e entra no preço médio. Na venda, desconta do que você recebeu.')
            ->assertSee('Com data anterior a outras operações, o Cerne refaz a posição pela ordem das datas.');

        Livewire::test(PositionActions::class)->call('open', $ativo->id, 'sell')
            ->assertSee('O preço médio não muda. O valor atual passa a ser as cotas que restam × este preço');
    }

    public function test_o_que_a_compra_faz_com_a_cotacao_e_o_que_a_dica_diz(): void
    {
        $ativo = $this->ativoComCotas();

        // Compra de hoje a R$ 12: vira a cotação, e o valor atual passa a ser cotas × preço (110 × 12).
        app(InvestmentTransactionService::class)->trade($ativo, TransactionType::Buy, '10', '12.00', null, CarbonImmutable::today(), $this->usuario->id);

        $ativo->refresh();
        $this->assertSame('12.000000', $ativo->current_price);
        $this->assertSame('1320.00', $ativo->current_amount);
    }

    public function test_a_nota_da_cotacao_nao_promete_o_que_so_vale_para_data_recente(): void
    {
        $ativo = $this->ativoComCotas();

        Livewire::test(PositionActions::class)->call('open', $ativo->id, 'quote')
            ->assertSee('Se a data for a de hoje ou mais nova que a última cotação, o valor do ativo passa a ser cotas × cotação.')
            ->assertSee('De uma data antiga, só o histórico do mês dela é corrigido.');
    }

    public function test_provento_explica_bruto_e_conta_e_o_desdobramento_usa_nomes_claros_no_erro(): void
    {
        $ativo = $this->ativoComCotas();

        Livewire::test(InvestmentEvents::class)->dispatch('open-event', id: $ativo->id, action: 'income')
            ->assertSee('Informe o valor antes do imposto. O líquido é o bruto menos o imposto retido.')
            ->assertSee('Com conta, o líquido entra como receita e soma no saldo dela. Sem conta, fica só no histórico do ativo.');

        Livewire::test(InvestmentEvents::class)->dispatch('open-event', id: $ativo->id, action: 'ratio')
            ->set('from', '')->set('to', '')
            ->call('save')
            ->assertSee('quantidade antes')
            ->assertDontSee('O campo de é obrigatório');
    }
}
