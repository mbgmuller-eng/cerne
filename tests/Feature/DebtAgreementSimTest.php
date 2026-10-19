<?php

namespace Tests\Feature;

use App\Enums\MemberRole;
use App\Livewire\Debts\DebtShow;
use App\Models\ExpenseCategory;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\DebtService;
use App\Support\Debts\DebtAgreementSimulator;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Dívida em aberto: simulador de acordo (à vista com desconto x parcelado), antes de registrar o acordo.
 */
class DebtAgreementSimTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private FinancialProfile $perfil;

    private ProfileMember $membro;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 09:00:00'));
        $this->seed(TaxonomySeeder::class);

        $this->usuario = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->usuario->id]);
        $this->membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuario->id, 'role' => MemberRole::Primary, 'name' => 'Marcelo']);
        $this->actingAs($this->usuario);
        app(ProfileContext::class)->set($this->perfil, $this->membro);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function cartao(string $saldo = '4250.00')
    {
        return app(DebtService::class)->createOpenDebt(['name' => 'Cartão atrasado', 'creditor' => 'Banco Y', 'current_balance' => $saldo]);
    }

    // ------------------------------------------------------------------ cálculo

    public function test_compara_desconto_parcela_e_aponta_o_menor_custo_e_a_menor_parcela(): void
    {
        $r = DebtAgreementSimulator::compare('4250.00', [
            ['label' => 'À vista', 'total' => '2500.00', 'installments' => 1],
            ['label' => 'Parcelado', 'total' => '3600.00', 'installments' => 12],
        ]);

        [$avista, $parcelado] = $r['proposals'];
        self::assertSame('1750.00', $avista['discount']);
        self::assertSame(41.18, $avista['discount_percent']);
        self::assertSame('650.00', $parcelado['discount']);
        self::assertSame('300.00', $parcelado['monthly']);
        self::assertSame(0, $r['best_total']);
        self::assertSame(1, $r['lightest_month']);
    }

    public function test_taxa_implicita_de_parcelar_em_vez_de_pagar_a_vista(): void
    {
        $r = DebtAgreementSimulator::compare('4250.00', [
            ['label' => 'À vista', 'total' => '2500.00', 'installments' => 1],
            ['label' => 'Parcelado', 'total' => '3600.00', 'installments' => 12],
        ]);

        self::assertNull($r['proposals'][0]['implicit_rate']);
        // 2.500 à vista contra 12 x 300: o parcelamento embute juros de uns 6% ao mês
        self::assertEqualsWithDelta(6.1, $r['proposals'][1]['implicit_rate'], 0.2);
        self::assertSame('À vista', $r['proposals'][1]['reference']);
    }

    public function test_parcelado_pelo_mesmo_valor_nao_tem_juros_embutidos(): void
    {
        $r = DebtAgreementSimulator::compare('4250.00', [
            ['label' => 'À vista', 'total' => '2400.00', 'installments' => 1],
            ['label' => 'Parcelado', 'total' => '2400.00', 'installments' => 6],
        ]);

        self::assertSame(0.0, $r['proposals'][1]['implicit_rate']);
    }

    public function test_uma_proposta_so_nao_tem_taxa_e_o_valor_acima_do_saldo_vira_desconto_negativo(): void
    {
        $r = DebtAgreementSimulator::compare('1000.00', [['label' => 'Única', 'total' => '1200.00', 'installments' => 6]]);

        self::assertNull($r['proposals'][0]['implicit_rate']);
        self::assertSame('-200.00', $r['proposals'][0]['discount']);
        self::assertSame(0, $r['best_total']);
    }

    public function test_quanto_a_divida_chegaria_a_ser_se_nada_for_feito(): void
    {
        // 4.250 a 8% ao mês durante 12 meses: x 2,5182
        self::assertEqualsWithDelta(10702.0, (float) DebtAgreementSimulator::ifNothingIsDone('4250.00', '8'), 5.0);
        self::assertSame('1000.00', DebtAgreementSimulator::ifNothingIsDone('1000.00', '0'));
    }

    // ------------------------------------------------------------------ tela

    public function test_tela_compara_as_propostas(): void
    {
        $divida = $this->cartao();

        $teste = Livewire::test(DebtShow::class, ['debt' => $divida])
            ->assertSee('Simular acordo')
            ->call('openAgreementSim')
            ->assertSet('agSimRows.0.label', 'À vista')->assertSet('agSimRows.1.installments', '12')
            ->set('agSimRows.0.total', '2.500,00')->set('agSimRows.1.total', '3600')
            ->call('simulateAgreement')->assertHasNoErrors();

        $teste->assertSee('menor custo')->assertSee('menor parcela')->assertSee('Desconto de')->assertSee('custa cerca de');
        self::assertCount(2, $teste->get('agSimResult')['proposals']);
        self::assertNull($teste->get('agSimResult')['if_nothing']);
    }

    public function test_mostra_o_custo_de_esperar_quando_informam_os_juros(): void
    {
        $divida = $this->cartao();

        Livewire::test(DebtShow::class, ['debt' => $divida])->call('openAgreementSim')
            ->set('agSimRows.0.total', '2500')->set('agSimRows.1.total', '3600')->set('agSimGrowth', '8')
            ->call('simulateAgreement')->assertHasNoErrors()->assertSee('Sem acordo, com 8% ao mês');
    }

    public function test_usar_a_proposta_preenche_o_registro_do_acordo(): void
    {
        $divida = $this->cartao();

        Livewire::test(DebtShow::class, ['debt' => $divida])->call('openAgreementSim')
            ->set('agSimRows.0.total', '2500')->set('agSimRows.1.total', '3600')->call('simulateAgreement')
            ->call('useAgreementProposal', 1)
            ->assertSet('showAgreementSim', false)->assertSet('showAgreement', true)
            ->assertSet('agreementTotal', '3600.00')->assertSet('agreementInstallments', '12');
    }

    public function test_valida_valor_e_parcelas(): void
    {
        $divida = $this->cartao();

        Livewire::test(DebtShow::class, ['debt' => $divida])->call('openAgreementSim')
            ->call('simulateAgreement')->assertHasErrors(['agSimRows.0.total', 'agSimRows.1.total']);

        Livewire::test(DebtShow::class, ['debt' => $divida])->call('openAgreementSim')
            ->set('agSimRows.0.total', 'caro')->set('agSimRows.1.total', '100')->set('agSimRows.1.installments', '0')
            ->call('simulateAgreement')->assertHasErrors(['agSimRows.0.total', 'agSimRows.1.installments']);
    }

    public function test_adiciona_e_remove_propostas_ate_quatro(): void
    {
        $divida = $this->cartao();

        $teste = Livewire::test(DebtShow::class, ['debt' => $divida])->call('openAgreementSim')
            ->call('addAgreementSimRow')->call('addAgreementSimRow')->call('addAgreementSimRow');
        self::assertCount(4, $teste->get('agSimRows'));

        $teste->call('removeAgreementSimRow', 0)->call('removeAgreementSimRow', 0)->call('removeAgreementSimRow', 0)->call('removeAgreementSimRow', 0);
        self::assertCount(1, $teste->get('agSimRows'), 'sempre fica uma proposta');
    }

    public function test_so_existe_para_divida_sem_acordo(): void
    {
        $cat = ExpenseCategory::available()->where('name', 'Financeiros')->firstOrFail();
        $emprestimo = app(DebtService::class)->create([
            'kind' => 'loan', 'name' => 'Empréstimo', 'amortization' => 'fixed', 'installment_amount' => '650.00', 'term' => 6,
            'first_due_date' => '2026-11-10', 'paid_before' => 0, 'category_id' => $cat->id,
        ]);

        Livewire::test(DebtShow::class, ['debt' => $emprestimo])->assertDontSee('wire:click="openAgreementSim"', false)->call('openAgreementSim')->assertStatus(404);
    }

    public function test_saldo_zerado_nao_simula(): void
    {
        $divida = $this->cartao();
        app(DebtService::class)->updateBalance($divida, '0');

        Livewire::test(DebtShow::class, ['debt' => $divida->fresh()])->call('openAgreementSim')
            ->set('agSimRows.0.total', '100')->set('agSimRows.1.total', '100')->call('simulateAgreement')->assertHasErrors(['agSimRows']);
    }
}
