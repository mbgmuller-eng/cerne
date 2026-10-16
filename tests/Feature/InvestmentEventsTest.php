<?php

namespace Tests\Feature;

use App\Enums\AssetClass;
use App\Enums\InvestmentSector;
use App\Enums\TransactionType;
use App\Livewire\Investments\InvestmentEvents;
use App\Livewire\Investments\InvestmentsIndex;
use App\Models\BankAccount;
use App\Models\FinancialProfile;
use App\Models\IncomeCategory;
use App\Models\IncomeRecord;
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
 * Proventos (dividendo, JCP, amortização) e desdobramento ou grupamento de cotas.
 */
class InvestmentEventsTest extends TestCase
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
        IncomeCategory::factory()->create(['name' => 'Dividendos']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function ativo(string $qtd = '100', string $preco = '10.00', string $data = '2026-09-01'): InvestmentRecord
    {
        $total = bcmul($qtd, $preco, 2);
        $ativo = InvestmentRecord::factory()->for($this->perfil, 'profile')->for($this->membro, 'member')->create([
            'name' => 'Fundo Logístico', 'ticker' => 'XPLG11', 'asset_class' => AssetClass::Fii, 'sector' => InvestmentSector::VariableIncome,
            'current_amount' => $total, 'invested_amount' => null, 'quantity' => null,
        ]);
        $this->servico->record($ativo, ['type' => TransactionType::Buy, 'quantity' => $qtd, 'unit_price' => $preco, 'total_amount' => $total, 'operation_date' => CarbonImmutable::parse($data)], $this->usuario->id);
        $this->servico->seedQuote($ativo->refresh(), $preco, CarbonImmutable::parse($data));

        return $ativo->refresh();
    }

    private function conta(string $saldo = '1000.00'): BankAccount
    {
        return BankAccount::factory()->for($this->perfil, 'profile')->for($this->membro, 'member')->create(['current_balance' => $saldo]);
    }

    // ---- proventos

    public function test_dividendo_registra_bruto_imposto_e_liquido_sem_mexer_na_posicao(): void
    {
        $ativo = $this->ativo('100', '10.00');

        $tx = $this->servico->registerIncome($ativo, TransactionType::Dividend, '85.00', '0', CarbonImmutable::parse('2026-10-05'), null, $this->usuario->id);

        self::assertSame('85.00', $tx->total_amount);
        self::assertSame('85.00', $tx->net_amount);
        $ativo->refresh();
        self::assertSame('100.000000', $ativo->quantity);
        self::assertSame('10.000000', $ativo->average_price);
        self::assertSame(0, IncomeRecord::withoutProfileScope()->count(), 'sem conta, só o histórico do ativo');
    }

    public function test_jcp_com_imposto_retido_fica_com_o_liquido(): void
    {
        $ativo = $this->ativo();

        $tx = $this->servico->registerIncome($ativo, TransactionType::Jcp, '100.00', '15.00', CarbonImmutable::parse('2026-10-05'), null, $this->usuario->id);

        self::assertSame('100.00', $tx->total_amount);
        self::assertSame('15.00', $tx->other_fees);
        self::assertSame('85.00', $tx->net_amount);
    }

    public function test_provento_com_conta_vira_receita_e_credita_o_saldo_liquido(): void
    {
        $ativo = $this->ativo();
        $conta = $this->conta('1000.00');

        $this->servico->registerIncome($ativo, TransactionType::Jcp, '100.00', '15.00', CarbonImmutable::parse('2026-10-05'), $conta, $this->usuario->id);

        self::assertSame('1085.00', $conta->fresh()->current_balance);
        $receita = IncomeRecord::withoutProfileScope()->sole();
        self::assertSame('85.00', $receita->amount);
        self::assertSame($conta->id, $receita->bank_account_id);
        self::assertSame('2026-10-05', $receita->received_date->toDateString());
        self::assertStringContainsString('JCP', $receita->description);
        self::assertTrue($receita->affects_balance);
    }

    public function test_amortizacao_reduz_o_custo_e_o_preco_medio(): void
    {
        $ativo = $this->ativo('100', '10.00'); // custo 1.000

        $this->servico->registerIncome($ativo, TransactionType::Amortization, '200.00', '0', CarbonImmutable::parse('2026-10-05'), null, $this->usuario->id);

        $ativo->refresh();
        self::assertSame('100.000000', $ativo->quantity);
        self::assertSame('8.000000', $ativo->average_price, '(1.000 - 200) / 100');
        self::assertSame('800.00', $ativo->invested_amount);
    }

    public function test_amortizacao_maior_que_o_custo_zera_sem_ficar_negativo(): void
    {
        $ativo = $this->ativo('10', '10.00'); // custo 100

        $this->servico->registerIncome($ativo, TransactionType::Amortization, '150.00', '0', CarbonImmutable::parse('2026-10-05'), null, $this->usuario->id);

        self::assertSame('0.000000', $ativo->fresh()->average_price);
    }

    public function test_provento_antigo_lancado_depois_de_uma_venda_refaz_a_posicao_na_ordem_das_datas(): void
    {
        $ativo = $this->ativo('100', '10.00', '2026-09-01');
        $this->servico->trade($ativo, TransactionType::Sell, '40', '12.00', null, CarbonImmutable::parse('2026-09-20'), $this->usuario->id);

        // Amortização de 02/09, antes da venda: o custo cai antes de vender.
        $this->servico->registerIncome($ativo->refresh(), TransactionType::Amortization, '100.00', '0', CarbonImmutable::parse('2026-09-02'), null, $this->usuario->id);

        $ativo->refresh();
        self::assertSame('60.000000', $ativo->quantity);
        self::assertSame('9.000000', $ativo->average_price, '(1.000 - 100) / 100');
        self::assertSame('540.00', $ativo->invested_amount);
    }

    public function test_provento_recusa_valor_zero_imposto_igual_ao_bruto_e_tipo_errado(): void
    {
        $ativo = $this->ativo();
        $data = CarbonImmutable::parse('2026-10-05');

        foreach ([['0', '0'], ['50', '50'], ['50', '60']] as [$bruto, $imposto]) {
            try {
                $this->servico->registerIncome($ativo, TransactionType::Dividend, $bruto, $imposto, $data, null, $this->usuario->id);
                self::fail("deveria recusar bruto $bruto e imposto $imposto");
            } catch (\InvalidArgumentException) {
                // esperado
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->servico->registerIncome($ativo, TransactionType::Buy, '50', '0', $data, null, $this->usuario->id);
    }

    // ---- desdobramento e grupamento

    public function test_desdobramento_multiplica_cotas_divide_o_preco_medio_e_a_cotacao_mantendo_o_valor(): void
    {
        $ativo = $this->ativo('100', '10.00', '2026-09-01');
        $valorAntes = $ativo->current_amount;

        $this->servico->registerRatio($ativo, TransactionType::Split, '1', '2', CarbonImmutable::parse('2026-10-05'), $this->usuario->id);

        $ativo->refresh();
        self::assertSame('200.000000', $ativo->quantity);
        self::assertSame('5.000000', $ativo->average_price);
        self::assertSame('5.000000', $ativo->current_price, 'a cotação acompanha a proporção');
        self::assertSame($valorAntes, $ativo->current_amount);
        $tx = InvestmentTransaction::withoutProfileScope()->where('transaction_type', TransactionType::Split)->sole();
        self::assertSame('1.000000', $tx->ratio_from);
        self::assertSame('2.000000', $tx->ratio_to);
    }

    public function test_grupamento_divide_cotas_e_multiplica_o_preco_medio(): void
    {
        $ativo = $this->ativo('100', '2.00', '2026-09-01');

        $this->servico->registerRatio($ativo, TransactionType::Grouping, '10', '1', CarbonImmutable::parse('2026-10-05'), $this->usuario->id);

        $ativo->refresh();
        self::assertSame('10.000000', $ativo->quantity);
        self::assertSame('20.000000', $ativo->average_price);
        self::assertSame('20.000000', $ativo->current_price);
    }

    public function test_grupamento_pode_deixar_fracao_de_cota(): void
    {
        $ativo = $this->ativo('105', '2.00');

        $this->servico->registerRatio($ativo, TransactionType::Grouping, '10', '1', CarbonImmutable::parse('2026-10-05'), $this->usuario->id);

        self::assertSame('10.500000', $ativo->fresh()->quantity);
    }

    public function test_desdobramento_antigo_so_multiplica_o_que_havia_naquele_dia(): void
    {
        $ativo = $this->ativo('100', '10.00', '2026-09-01');
        $this->servico->trade($ativo, TransactionType::Buy, '50', '5.00', null, CarbonImmutable::parse('2026-10-01'), $this->usuario->id); // já nas cotas novas

        // Desdobramento 1 para 2 em 15/09: as 100 cotas viram 200; as 50 compradas depois já são cotas novas.
        $this->servico->registerRatio($ativo->refresh(), TransactionType::Split, '1', '2', CarbonImmutable::parse('2026-09-15'), $this->usuario->id);

        $ativo->refresh();
        self::assertSame('250.000000', $ativo->quantity);
        // Custo: 1.000 + 250 = 1.250 sobre 250 cotas.
        self::assertSame('5.000000', $ativo->average_price);
        self::assertSame('1250.00', $ativo->invested_amount);
    }

    public function test_evento_anterior_a_ultima_cotacao_nao_mexe_na_cotacao_guardada(): void
    {
        $ativo = $this->ativo('100', '10.00', '2026-09-01');
        $this->servico->updateQuote($ativo, '5.00', CarbonImmutable::parse('2026-10-06')); // cotação já pós-desdobramento
        $ativo->refresh();

        $this->servico->registerRatio($ativo, TransactionType::Split, '1', '2', CarbonImmutable::parse('2026-09-15'), $this->usuario->id);

        self::assertSame('5.000000', $ativo->fresh()->current_price, 'a cotação de 06/10 já era a nova');
    }

    public function test_proporcao_invalida_ou_que_nao_combina_com_o_tipo_e_recusada(): void
    {
        $ativo = $this->ativo();
        $data = CarbonImmutable::parse('2026-10-05');

        foreach ([[TransactionType::Split, '2', '1'], [TransactionType::Split, '1', '1'], [TransactionType::Grouping, '1', '2'], [TransactionType::Split, '0', '2'], [TransactionType::Dividend, '1', '2']] as [$tipo, $de, $para]) {
            try {
                $this->servico->registerRatio($ativo, $tipo, $de, $para, $data, $this->usuario->id);
                self::fail("deveria recusar {$tipo->value} $de para $para");
            } catch (\InvalidArgumentException) {
                // esperado
            }
        }

        self::assertSame('100.000000', $ativo->fresh()->quantity);
    }

    public function test_evento_antigo_sem_proporcao_continua_lendo_a_quantidade_final(): void
    {
        $ativo = $this->ativo('100', '10.00');
        $this->servico->record($ativo, ['type' => TransactionType::Split, 'quantity' => '300', 'total_amount' => '0', 'operation_date' => CarbonImmutable::parse('2026-10-01')], $this->usuario->id);

        $ativo->refresh();
        self::assertSame('300.000000', $ativo->quantity);
        self::assertSame('3.333333', $ativo->average_price);
    }

    public function test_refazer_a_posicao_reproduz_o_mesmo_resultado(): void
    {
        $ativo = $this->ativo('100', '10.00');
        $this->servico->registerRatio($ativo, TransactionType::Split, '1', '2', CarbonImmutable::parse('2026-09-10'), $this->usuario->id);
        $this->servico->registerIncome($ativo->refresh(), TransactionType::Amortization, '100.00', '0', CarbonImmutable::parse('2026-09-20'), null, $this->usuario->id);
        $antes = $ativo->refresh()->only(['quantity', 'average_price', 'invested_amount']);

        $this->servico->rebuildPosition($ativo);

        self::assertSame($antes, $ativo->refresh()->only(['quantity', 'average_price', 'invested_amount']));
    }

    // ---- as telas

    public function test_formulario_de_provento_mostra_a_previa_e_salva_com_conta(): void
    {
        $ativo = $this->ativo();
        $conta = $this->conta('500.00');

        Livewire::test(InvestmentEvents::class)
            ->dispatch('open-event', id: $ativo->id, action: 'income')
            ->assertSet('show', true)
            ->set('type', 'jcp')
            ->set('gross', '100,00')
            ->call('applyJcpTax')
            ->assertSet('tax', '15.00')
            ->assertSee('Valor líquido')
            ->set('accountId', $conta->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('show', false)
            ->assertDispatched('position-saved');

        self::assertSame('585.00', $conta->fresh()->current_balance);
        self::assertSame('85.00', InvestmentTransaction::withoutProfileScope()->where('transaction_type', TransactionType::Jcp)->sole()->net_amount);
    }

    public function test_formulario_de_proporcao_mostra_a_previa_e_salva(): void
    {
        $ativo = $this->ativo('100', '10.00');

        Livewire::test(InvestmentEvents::class)
            ->dispatch('open-event', id: $ativo->id, action: 'ratio')
            ->set('type', 'split')
            ->set('from', '1')
            ->set('to', '2')
            ->assertSee('100 → 200')
            ->call('save')
            ->assertHasNoErrors();

        self::assertSame('200.000000', $ativo->fresh()->quantity);
    }

    public function test_formulario_mostra_o_erro_da_regra_no_campo(): void
    {
        $ativo = $this->ativo();

        Livewire::test(InvestmentEvents::class)
            ->dispatch('open-event', id: $ativo->id, action: 'income')
            ->set('gross', '50')
            ->set('tax', '50')
            ->call('save')
            ->assertHasErrors('tax');

        Livewire::test(InvestmentEvents::class)
            ->dispatch('open-event', id: $ativo->id, action: 'ratio')
            ->set('type', 'split')
            ->set('from', '2')
            ->set('to', '1')
            ->call('save')
            ->assertHasErrors('to');
    }

    public function test_so_abre_para_ativo_com_posicao_e_nunca_para_corretor(): void
    {
        $semCotas = InvestmentRecord::factory()->for($this->perfil, 'profile')->for($this->membro, 'member')->create([
            'asset_class' => AssetClass::Fii, 'sector' => InvestmentSector::VariableIncome, 'quantity' => null,
        ]);

        Livewire::test(InvestmentEvents::class)->dispatch('open-event', id: $semCotas->id, action: 'income')->assertSet('show', false);

        $this->actingAs(User::factory()->broker()->create());
        Livewire::test(InvestmentEvents::class)->assertStatus(403);
    }

    public function test_a_lista_oferece_os_botoes_e_o_historico_mostra_o_que_aconteceu(): void
    {
        $ativo = $this->ativo();
        $this->servico->registerIncome($ativo, TransactionType::Jcp, '100.00', '15.00', CarbonImmutable::parse('2026-10-05'), null, $this->usuario->id);
        $this->servico->registerRatio($ativo->refresh(), TransactionType::Split, '1', '2', CarbonImmutable::parse('2026-10-06'), $this->usuario->id);

        Livewire::test(InvestmentsIndex::class)
            ->assertSee('Provento')
            ->assertSee('Desdobramento ou grupamento')
            ->set('tab', 'transactions')
            ->assertSee('JCP')
            ->assertSee('imposto retido')
            ->assertSee('cada 1 vira 2');
    }
}
