<?php

namespace Tests\Feature;

use App\Enums\DebtInstallmentStatus;
use App\Enums\DebtAmortization;
use App\Enums\MemberRole;
use App\Livewire\Debts\DebtShow;
use App\Models\Debt;
use App\Models\FinancialProfile;
use App\Models\Goal;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\DebtService;
use App\Support\Debts\DebtSimulator;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Consórcio assistido: sugestão e prévia do reajuste, simulação do lance na contemplação.
 */
class DebtConsortiumAssistTest extends TestCase
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

    private function consorcio(array $campos = []): Debt
    {
        return app(DebtService::class)->createConsortium(array_merge([
            'consortium_type' => 'property', 'name' => 'Consórcio', 'credit_amount' => '100000.00', 'installment_amount' => '1000.00', 'term' => 100,
            'first_due_date' => '2026-01-10', 'paid_before' => 0, 'adjustment_index' => 'incc', 'adjustment_every_months' => 12, 'adjustment_first_after_months' => 12,
        ], $campos));
    }

    // ------------------------------------------------------------------ lance

    public function test_lance_baixa_a_parcela_ou_encurta_o_prazo(): void
    {
        $r = DebtSimulator::bid('128238.06', 189, '52.08', '30000.00');

        self::assertFalse($r['paid_off']);
        self::assertSame('730.59', $r['full_installment']);
        self::assertSame(['term' => 189, 'installment' => '571.86'], $r['reduce_installment']);
        self::assertSame(['term' => 145, 'installment' => '730.59'], $r['reduce_term']);
    }

    public function test_lance_que_cobre_tudo_quita_e_dado_invalido_e_recusado(): void
    {
        self::assertTrue(DebtSimulator::bid('10000.00', 10, '0.00', '10000.00')['paid_off']);

        $this->expectException(InvalidArgumentException::class);
        DebtSimulator::bid('10000.00', 0, '0.00', '100.00');
    }

    public function test_base_do_lance_vem_do_extrato_ou_das_parcelas_em_aberto(): void
    {
        $semExtrato = $this->consorcio(['installment_amount' => '1000.00', 'term' => 10, 'paid_before' => 2]);

        self::assertSame(['balance' => '8000.00', 'remaining' => 8, 'insurance' => '0.00', 'source' => 'parcelas'], $semExtrato->contemplationBase());

        $semExtrato->update(['statement_balance' => '128238.06', 'statement_remaining' => 189, 'insurance_percent' => '0.046371', 'credit_amount' => '112316.98']);
        $base = $semExtrato->fresh()->contemplationBase();

        self::assertSame('extrato', $base['source']);
        self::assertSame('128238.06', $base['balance']);
        self::assertSame(189, $base['remaining']);
        self::assertSame('52.08', $base['insurance']);
    }

    public function test_so_consorcio_nao_contemplado_tem_base_de_lance(): void
    {
        $consorcio = $this->consorcio(['term' => 10]);
        app(DebtService::class)->contemplate($consorcio, [
            'contemplated_on' => '2026-10-01', 'mode' => 'draw', 'new_installment_amount' => '700.00', 'remaining_installments' => 5,
            'category_id' => \App\Models\ExpenseCategory::available()->where('name', 'Habitação')->firstOrFail()->id,
        ]);

        self::assertNull($consorcio->fresh()->contemplationBase());
    }

    public function test_tela_simula_o_lance_e_preenche_a_contemplacao(): void
    {
        $consorcio = $this->consorcio(['term' => 10]);
        $consorcio->update(['statement_balance' => '10000.00', 'statement_remaining' => 10, 'insurance_percent' => '0.05']);

        $teste = Livewire::test(DebtShow::class, ['debt' => $consorcio->fresh()])->call('openContemplation')
            ->set('contemplationMode', 'free_bid')->set('bidAmount', '2000');

        $teste->assertSee('O que o lance de')->assertSee('Baixar a parcela e manter o prazo')->assertSee('do último extrato');
        $teste->call('useBidOption', 'installment')->assertSet('newInstallmentAmount', '850.00')->assertSet('remainingInstallments', '10');
        $teste->call('useBidOption', 'term')->assertSet('newInstallmentAmount', '1050.00')->assertSet('remainingInstallments', '8');
    }

    public function test_sorteio_nao_mostra_simulacao_de_lance(): void
    {
        $consorcio = $this->consorcio(['term' => 10]);

        Livewire::test(DebtShow::class, ['debt' => $consorcio])->call('openContemplation')->set('contemplationMode', 'draw')->set('bidAmount', '2000')
            ->assertDontSee('O que o lance de');
    }

    public function test_lance_embutido_sai_da_carta_e_os_outros_nao(): void
    {
        $consorcio = $this->consorcio(['term' => 10]);

        $teste = Livewire::test(DebtShow::class, ['debt' => $consorcio])->call('openContemplation')
            ->assertSet('creditReleased', '100000.00')
            ->set('contemplationMode', 'embedded_bid')->set('bidAmount', '25000')
            ->assertSet('creditReleased', '75000.00');

        $teste->set('contemplationMode', 'free_bid')->assertSet('creditReleased', '100000.00');
        $teste->set('contemplationMode', 'embedded_bid')->set('bidAmount', 'abc')->assertSet('creditReleased', '100000.00');
    }

    // ------------------------------------------------------------------ reajuste

    public function test_previa_do_reajuste_mostra_carta_parcela_e_objetivo(): void
    {
        $consorcio = $this->consorcio(['goal_name' => 'Casa']);

        $p = app(DebtService::class)->previewAdjustment($consorcio, '5');

        self::assertSame('105000.00', $p['credit_after']);
        self::assertSame('1000.00', $p['installment_before']);
        self::assertSame('1050.00', $p['installment_after']);
        self::assertSame('105000.00', $p['goal_after']);
        self::assertSame('100000.00', (string) $consorcio->fresh()->credit_amount, 'a prévia não grava nada');
        self::assertSame('100000.00', (string) Goal::query()->sole()->estimated_value);
    }

    public function test_previa_vale_a_mesma_conta_do_reajuste_registrado(): void
    {
        $consorcio = $this->consorcio();
        $service = app(DebtService::class);

        $previa = $service->previewAdjustment($consorcio, '4,5');
        $ajuste = $service->registerAdjustment($consorcio, '4,5', CarbonImmutable::parse('2027-01-10'));

        self::assertSame((string) $ajuste->credit_after, $previa['credit_after']);
        self::assertSame((string) $ajuste->installment_after, $previa['installment_after']);
    }

    public function test_previa_so_com_percentual_valido_de_consorcio(): void
    {
        $consorcio = $this->consorcio();
        $service = app(DebtService::class);

        foreach (['', 'abc', '900', '-80', '5%'] as $ruim) {
            self::assertNull($service->previewAdjustment($consorcio, $ruim), "percentual [$ruim]");
        }

        self::assertNotNull($service->previewAdjustment($consorcio, '-5'), 'o índice pode ser negativo');

        $cat = \App\Models\ExpenseCategory::available()->where('name', 'Financeiros')->firstOrFail();
        $emprestimo = $service->create([
            'kind' => 'loan', 'name' => 'Empréstimo', 'amortization' => 'fixed', 'installment_amount' => '650.00', 'term' => 6,
            'first_due_date' => '2026-11-10', 'paid_before' => 0, 'category_id' => $cat->id,
        ]);
        self::assertNull($service->previewAdjustment($emprestimo, '5'));
    }

    public function test_sugere_o_reajuste_registrado_antes(): void
    {
        $consorcio = $this->consorcio();
        app(DebtService::class)->registerAdjustment($consorcio, '5', CarbonImmutable::parse('2026-10-10'));

        $teste = Livewire::test(DebtShow::class, ['debt' => $consorcio->fresh()])->call('openAdjustment')
            ->assertSee('Último reajuste registrado: 5% em 10/10/2026');

        $teste->call('useSuggestedAdjustment')->assertSet('adjustmentPercent', '5')->assertSee('Próxima parcela');
    }

    public function test_sem_historico_sugere_o_que_se_ve_nas_parcelas_pagas(): void
    {
        $consorcio = $this->consorcio(['term' => 12, 'paid_before' => 10]);
        // parcelas 1 a 5 a 100,00 e 6 a 10 a 106,00: o salto de 6% apareceu na parcela 6
        foreach ($consorcio->installments as $p) {
            $p->update(['amount' => $p->number <= 5 ? '100.00' : '106.00', 'principal_amount' => $p->number <= 5 ? '100.00' : '106.00']);
        }

        self::assertSame(['percent' => '6.00', 'number' => 6, 'on' => $consorcio->installments()->where('number', 6)->first()->paid_on->toDateString()], $consorcio->fresh()->observedAdjustment());

        Livewire::test(DebtShow::class, ['debt' => $consorcio->fresh()])->call('openAdjustment')
            ->assertSee('Pelas parcelas pagas, a parcela subiu 6% na parcela 6')
            ->call('useSuggestedAdjustment')->assertSet('adjustmentPercent', '6');
    }

    public function test_sem_salto_nas_parcelas_nao_ha_sugestao(): void
    {
        $consorcio = $this->consorcio(['term' => 12, 'paid_before' => 10]);

        self::assertNull($consorcio->observedAdjustment());

        Livewire::test(DebtShow::class, ['debt' => $consorcio])->call('openAdjustment')->assertDontSee('Usar este percentual');
    }

    public function test_prévia_aparece_enquanto_digita_e_o_registro_continua_igual(): void
    {
        $consorcio = $this->consorcio();

        $teste = Livewire::test(DebtShow::class, ['debt' => $consorcio])->call('openAdjustment')->assertDontSee('→')
            ->set('adjustmentPercent', '10')->assertSee('R$ 110.000,00');

        $teste->call('saveAdjustment')->assertHasNoErrors();
        self::assertSame('110000.00', (string) $consorcio->fresh()->credit_amount);
        self::assertSame(DebtInstallmentStatus::Open, $consorcio->installments()->where('number', 100)->first()->status);
        self::assertSame(DebtAmortization::Fixed, $consorcio->fresh()->amortization);
    }
}
