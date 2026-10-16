<?php

namespace Tests\Feature;

use App\Enums\DebtInstallmentStatus;
use App\Enums\DebtKind;
use App\Enums\DebtStatus;
use App\Enums\GoalStatus;
use App\Enums\MemberRole;
use App\Enums\Necessity;
use App\Livewire\Debts\DebtShow;
use App\Livewire\Debts\DebtsIndex;
use App\Models\Debt;
use App\Models\DebtAdjustment;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\Goal;
use App\Models\InvestmentRecord;
use App\Models\ProfileMember;
use App\Models\User;
use App\Notifications\DebtAdjustmentDue;
use App\Services\DebtReminderService;
use App\Services\DebtService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Dívidas, fase 2: consórcio (carta, reajuste, contemplação, objetivo por parcelas) e dívida em aberto (saldo, acordo).
 */
class DebtConsortiumTest extends TestCase
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

    private function servico(): DebtService
    {
        return app(DebtService::class);
    }

    private function consorcio(array $campos = []): Debt
    {
        return $this->servico()->createConsortium(array_merge([
            'consortium_type' => 'property', 'name' => 'Consórcio do apartamento', 'creditor' => 'Ademicon', 'credit_amount' => '100000.00',
            'installment_amount' => '1000.00', 'term' => 100, 'first_due_date' => '2026-11-10', 'paid_before' => 20,
            'adjustment_index' => 'incc', 'adjustment_every_months' => 12, 'adjustment_first_after_months' => 12, 'goal_name' => 'Trocar de carro',
        ], $campos));
    }

    private function classificacao(string $categoria, string $sub): array
    {
        $cat = ExpenseCategory::available()->where('name', $categoria)->firstOrFail();

        return ['category_id' => $cat->id, 'subcategory_id' => $cat->subcategories()->where('name', $sub)->firstOrFail()->id];
    }

    private function contemplar(Debt $divida, array $campos = []): Debt
    {
        return $this->servico()->contemplate($divida, array_merge([
            'contemplated_on' => '2026-10-20', 'mode' => 'free_bid', 'bid_amount' => '30000.00', 'new_installment_amount' => '700.00',
            'remaining_installments' => 60,
        ], $this->classificacao('Habitação', 'Prestação da casa'), $campos));
    }

    // ------------------------------------------------------------------ consórcio antes da contemplação

    public function test_consorcio_cadastra_cronograma_como_investimento_e_calcula_o_primeiro_reajuste(): void
    {
        $divida = $this->consorcio();

        self::assertSame(DebtKind::Consortium, $divida->kind);
        self::assertSame(100, $divida->installments()->count());
        self::assertSame(20, $divida->installments()->where('status', DebtInstallmentStatus::Paid)->count());
        self::assertSame('Consórcio', $divida->category->name);
        self::assertSame(Necessity::Investment, $divida->necessity);
        self::assertSame('2027-11-10', $divida->next_adjustment_on->toDateString());
        self::assertTrue($divida->isSavingForCredit());
    }

    public function test_consorcio_nao_entra_no_total_devedor_nem_vira_patrimonio_so_a_carta_aparece(): void
    {
        $this->consorcio();

        $resumo = $this->servico()->summary(2026, 11);

        self::assertSame('0.00', $resumo['total']);
        self::assertSame('100000.00', $resumo['consortium_credit']);
        self::assertSame(0, InvestmentRecord::query()->count());
    }

    public function test_parcela_paga_vira_despesa_de_investimento_sem_criar_investimento(): void
    {
        $divida = $this->consorcio(['paid_before' => 0]);

        $this->servico()->pay($divida->installments[0], CarbonImmutable::parse('2026-11-10'));

        $despesa = ExpenseRecord::query()->sole();
        self::assertSame('Consórcio', $despesa->category->name);
        self::assertSame(Necessity::Investment, $despesa->necessity);
        self::assertSame(0, InvestmentRecord::query()->count());
    }

    public function test_objetivo_avanca_pelas_parcelas_pagas_e_nao_pelo_dinheiro(): void
    {
        $divida = $this->consorcio();
        $objetivo = Goal::query()->where('linked_debt_id', $divida->id)->sole();

        self::assertSame(['paid' => 20, 'total' => 100], $objetivo->installmentProgress());
        self::assertSame(20.0, $objetivo->progressPercentage());

        $this->servico()->pay($divida->installments()->where('status', DebtInstallmentStatus::Open)->orderBy('number')->first(), CarbonImmutable::parse('2026-11-10'));

        $objetivo = $objetivo->fresh();
        self::assertSame(21, $objetivo->installmentProgress()['paid']);
        self::assertSame(21.0, $objetivo->progressPercentage());
    }

    public function test_pode_ligar_um_objetivo_que_ja_existe(): void
    {
        $objetivo = Goal::create([
            'name' => 'Casa', 'priority' => 1, 'estimated_value' => '100000.00', 'funding_method' => 'installments', 'current_amount' => '0.00', 'status' => GoalStatus::Active->value,
        ]);

        $divida = $this->consorcio(['goal_name' => null, 'goal_id' => $objetivo->id]);

        self::assertSame($divida->id, $objetivo->fresh()->linked_debt_id);
    }

    public function test_consorcio_valida_carta_parcela_e_prazo(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->consorcio(['credit_amount' => '0']);
    }

    // ------------------------------------------------------------------ reajuste

    public function test_reajuste_sobe_a_carta_as_parcelas_em_aberto_e_o_objetivo_e_guarda_o_historico(): void
    {
        $divida = $this->consorcio();

        $ajuste = $this->servico()->registerAdjustment($divida, '10', CarbonImmutable::parse('2027-11-10'));

        $divida->refresh();
        self::assertSame('110000.00', (string) $divida->credit_amount);
        self::assertSame('1100.00', (string) $divida->installment_amount);
        self::assertSame('1100.00', (string) $divida->installments()->where('status', DebtInstallmentStatus::Open)->orderBy('number')->first()->amount);
        self::assertSame('1000.00', (string) $divida->installments()->where('status', DebtInstallmentStatus::Paid)->first()->amount, 'parcela paga não muda');
        self::assertSame('110000.00', (string) Goal::query()->where('linked_debt_id', $divida->id)->sole()->estimated_value);
        self::assertSame('2028-11-10', $divida->next_adjustment_on->toDateString());
        self::assertSame('100000.00', (string) $ajuste->credit_before);
        self::assertSame('110000.00', (string) $ajuste->credit_after);
        self::assertSame(1, DebtAdjustment::query()->count());
    }

    public function test_reajuste_aceita_virgula_e_recusa_percentual_absurdo_ou_contrato_que_nao_e_consorcio(): void
    {
        $divida = $this->consorcio();
        $this->servico()->registerAdjustment($divida, '4,5', CarbonImmutable::parse('2027-11-10'));
        self::assertSame('104500.00', (string) $divida->fresh()->credit_amount);

        try {
            $this->servico()->registerAdjustment($divida->fresh(), '900', CarbonImmutable::parse('2027-11-10'));
            self::fail('deveria recusar o percentual');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('percentual', $e->getMessage());
        }

        $aberta = $this->servico()->createOpenDebt(['name' => 'Cartão', 'current_balance' => '1000']);
        $this->expectException(InvalidArgumentException::class);
        $this->servico()->registerAdjustment($aberta, '5', CarbonImmutable::parse('2027-11-10'));
    }

    public function test_avisa_a_data_do_reajuste_uma_unica_vez_e_o_proximo_reajuste_avisa_de_novo(): void
    {
        Notification::fake();
        $divida = $this->consorcio(['first_due_date' => '2026-10-10', 'adjustment_first_after_months' => 12]);   // reajuste em 10/10/2027
        $servico = app(DebtReminderService::class);

        self::assertSame(0, $servico->notifyAdjustments(CarbonImmutable::parse('2027-10-09')));
        self::assertSame(1, $servico->notifyAdjustments(CarbonImmutable::parse('2027-10-10')));
        self::assertSame(0, $servico->notifyAdjustments(CarbonImmutable::parse('2027-10-11')), 'idempotente para a mesma data');
        Notification::assertSentTo($this->usuario, DebtAdjustmentDue::class, fn (DebtAdjustmentDue $n) => $n->debtId === $divida->id && $n->dueDate === '2027-10-10');

        $this->servico()->registerAdjustment($divida->fresh(), '5', CarbonImmutable::parse('2027-10-10'));

        self::assertSame(0, $servico->notifyAdjustments(CarbonImmutable::parse('2027-10-12')), 'a data seguinte ainda não chegou');
        self::assertSame(1, $servico->notifyAdjustments(CarbonImmutable::parse('2028-10-10')));
    }

    // ------------------------------------------------------------------ contemplação

    public function test_contemplacao_refaz_as_parcelas_com_o_novo_valor_e_passa_a_ser_divida_de_habitacao(): void
    {
        $divida = $this->consorcio();

        $this->contemplar($divida);

        $divida->refresh();
        self::assertTrue($divida->isContemplated());
        self::assertSame(80, $divida->term, 'prazo = pagas + restantes');
        self::assertSame('700.00', (string) $divida->installment_amount);
        self::assertSame('Habitação', $divida->category->name);
        self::assertSame(Necessity::Essential, $divida->necessity);
        self::assertSame(60, $divida->installments()->where('status', DebtInstallmentStatus::Open)->count());
        self::assertSame('700.00', (string) $divida->installments()->where('status', DebtInstallmentStatus::Open)->orderBy('number')->first()->amount);
        self::assertSame(21, (int) $divida->installments()->where('status', DebtInstallmentStatus::Open)->min('number'));
        self::assertSame('30000.00', (string) $divida->bid_amount);
    }

    public function test_depois_de_contemplado_o_que_falta_pagar_entra_no_total_devedor_e_a_carta_sai_do_painel(): void
    {
        $divida = $this->consorcio();
        $this->contemplar($divida);

        $resumo = $this->servico()->summary(2026, 11);

        self::assertSame('42000.00', $resumo['total'], '60 parcelas de 700');
        self::assertSame('0.00', $resumo['consortium_credit']);
    }

    public function test_parcela_flex_pode_subir_na_contemplacao_por_sorteio(): void
    {
        $divida = $this->consorcio(['is_flex' => true, 'installment_amount' => '500.00']);

        $this->contemplar($divida, ['mode' => 'draw', 'bid_amount' => null, 'new_installment_amount' => '1450.00', 'remaining_installments' => 80]);

        $divida->refresh();
        self::assertNull($divida->bid_amount);
        self::assertSame('1450.00', (string) $divida->installments()->where('status', DebtInstallmentStatus::Open)->first()->amount);
    }

    public function test_parcela_paga_depois_da_contemplacao_cai_na_habitacao_e_as_anteriores_ficam_como_investimento(): void
    {
        $divida = $this->consorcio(['paid_before' => 0]);
        $this->servico()->pay($divida->installments[0], CarbonImmutable::parse('2026-11-10'));
        $this->contemplar($divida->fresh(), ['remaining_installments' => 99]);

        $this->servico()->pay($divida->installments()->where('status', DebtInstallmentStatus::Open)->orderBy('number')->first(), CarbonImmutable::parse('2026-12-10'));

        $categorias = ExpenseRecord::query()->orderBy('expense_date')->get()->map(fn (ExpenseRecord $e) => $e->category->name)->all();
        self::assertSame(['Consórcio', 'Habitação'], $categorias);
    }

    public function test_contemplacao_exige_novo_valor_prazo_lance_e_nao_repete(): void
    {
        $divida = $this->consorcio();

        foreach ([['new_installment_amount' => '0'], ['remaining_installments' => 0], ['bid_amount' => null]] as $ruim) {
            try {
                $this->contemplar($divida, $ruim);
                self::fail('deveria recusar '.json_encode($ruim));
            } catch (InvalidArgumentException) {
                self::assertFalse($divida->fresh()->isContemplated());
            }
        }

        $this->contemplar($divida);
        $this->expectException(InvalidArgumentException::class);
        $this->contemplar($divida->fresh());
    }

    public function test_contemplacao_pode_marcar_o_objetivo_como_alcancado(): void
    {
        $divida = $this->consorcio();

        $this->contemplar($divida, ['mark_goal_achieved' => true]);

        self::assertSame(GoalStatus::Achieved, Goal::query()->where('linked_debt_id', $divida->id)->sole()->status);
    }

    // ------------------------------------------------------------------ dívida em aberto

    public function test_divida_em_aberto_conta_no_total_mas_nao_nas_parcelas_do_mes(): void
    {
        $divida = $this->servico()->createOpenDebt(['name' => 'Cartão atrasado', 'creditor' => 'Banco X', 'principal' => '2900.00', 'current_balance' => '4250.00']);

        $resumo = $this->servico()->summary(2026, 10);

        self::assertSame(DebtStatus::Open, $divida->status);
        self::assertSame(0, $divida->installments()->count());
        self::assertSame('4250.00', $divida->balance());
        self::assertSame('4250.00', $resumo['total']);
        self::assertSame('0.00', $resumo['month_total']);
    }

    public function test_atualizar_saldo_muda_o_total_e_a_situacao(): void
    {
        $divida = $this->servico()->createOpenDebt(['name' => 'Cartão', 'current_balance' => '4250.00']);

        $this->servico()->updateBalance($divida, '4600,00', CarbonImmutable::parse('2026-10-20'), DebtStatus::Negotiating);

        $divida->refresh();
        self::assertSame('4600.00', $divida->balance());
        self::assertSame(DebtStatus::Negotiating, $divida->status);
        self::assertSame('4600.00', $this->servico()->summary(2026, 10)['total']);
    }

    public function test_acordo_cria_as_parcelas_guarda_o_desconto_e_a_ultima_absorve_os_centavos(): void
    {
        $divida = $this->servico()->createOpenDebt(['name' => 'Cartão', 'current_balance' => '4250.00']);
        $cat = $this->classificacao('Financeiros', 'Empréstimo');

        $this->servico()->registerAgreement($divida, ['total' => '3000.01', 'installments' => 3, 'first_due_date' => '2026-11-10'] + $cat);

        $divida->refresh();
        self::assertSame(DebtStatus::Active, $divida->status);
        self::assertSame(3, $divida->installments()->count());
        self::assertSame('3000.01', bcadd((string) $divida->installments()->sum('amount'), '0', 2));
        self::assertSame('1000.01', (string) $divida->installments()->where('number', 3)->first()->amount);
        self::assertSame('1249.99', (string) $divida->agreement_discount);
        self::assertSame('3000.01', (string) $divida->agreement_total);
        self::assertSame('Financeiros', $divida->category->name);
        self::assertSame('3000.01', $this->servico()->summary(2026, 11)['total']);
        self::assertSame('1000.00', $this->servico()->summary(2026, 11)['month_total']);
    }

    public function test_so_divida_sem_acordo_aceita_acordo_e_marcar_como_quitada(): void
    {
        $divida = $this->servico()->createOpenDebt(['name' => 'Cartão', 'current_balance' => '1000.00']);

        $this->servico()->markPaidOff($divida);

        $divida->refresh();
        self::assertSame(DebtStatus::PaidOff, $divida->status);
        self::assertSame('0.00', $divida->balance());

        $this->expectException(InvalidArgumentException::class);
        $this->servico()->registerAgreement($divida, ['total' => '500', 'installments' => 2, 'first_due_date' => '2026-11-10'] + $this->classificacao('Financeiros', 'Empréstimo'));
    }

    // ------------------------------------------------------------------ telas

    public function test_formulario_cadastra_consorcio_com_objetivo_novo(): void
    {
        $teste = Livewire::test(DebtsIndex::class)->call('openForm')
            ->set('kind', 'consortium')
            ->set('consortiumType', 'vehicle')->set('creditor', 'Ademicon')->set('creditAmount', '80000,00')->set('installmentAmount', '900,00')
            ->set('term', '80')->set('firstDueDate', '2026-11-10')->set('paidBefore', '3')
            ->set('adjustmentIndex', 'ipca')->set('adjustmentEvery', '12')->set('adjustmentFirstAfter', '6')
            ->set('goalChoice', 'new')->set('goalName', 'Trocar de carro')
            ->call('save')->assertHasNoErrors();

        $divida = Debt::query()->sole();
        self::assertSame(DebtKind::Consortium, $divida->kind);
        self::assertSame('2027-05-10', $divida->next_adjustment_on->toDateString());
        self::assertSame($divida->id, Goal::query()->sole()->linked_debt_id);
        $teste->assertRedirect(route('debts.show', $divida));
    }

    public function test_formulario_de_consorcio_valida_e_o_de_divida_em_aberto_cadastra(): void
    {
        Livewire::test(DebtsIndex::class)->call('openForm')->set('kind', 'consortium')->call('save')
            ->assertHasErrors(['creditAmount', 'installmentAmount', 'firstDueDate']);

        Livewire::test(DebtsIndex::class)->call('openForm')->set('kind', 'debt')
            ->set('name', 'Cartão atrasado')->set('currentBalance', '4250,00')->set('openStatus', 'negotiating')
            ->call('save')->assertHasNoErrors();

        self::assertSame(DebtStatus::Negotiating, Debt::query()->sole()->status);
    }

    public function test_lista_separa_consorcios_e_dividas_em_aberto_e_mostra_a_carta(): void
    {
        $this->consorcio();
        $this->servico()->createOpenDebt(['name' => 'Cartão atrasado', 'current_balance' => '4250.00']);

        Livewire::test(DebtsIndex::class)->assertSee('Consórcios')->assertSee('Dívidas em aberto')->assertSee('Cartas de consórcio')->assertSee('Cartão atrasado');
    }

    public function test_tela_do_consorcio_registra_reajuste_e_contemplacao(): void
    {
        $divida = $this->consorcio();

        Livewire::test(DebtShow::class, ['debt' => $divida])
            ->assertSee('Registrar reajuste')->assertSee('Registrar contemplação')
            ->call('openAdjustment')->set('adjustmentPercent', '5')->call('saveAdjustment')->assertHasNoErrors()
            ->call('openContemplation')
            ->assertSet('remainingInstallments', '80')
            ->assertSet('contemplationCategoryId', $this->classificacao('Habitação', 'Prestação da casa')['category_id'])
            ->set('contemplationMode', 'free_bid')->set('bidAmount', '25000')->set('newInstallmentAmount', '800')->set('remainingInstallments', '70')
            ->call('saveContemplation')->assertHasNoErrors();

        $divida->refresh();
        self::assertSame('105000.00', (string) $divida->credit_amount);
        self::assertTrue($divida->isContemplated());
        self::assertSame(90, $divida->term);
    }

    public function test_tela_da_contemplacao_mostra_erro_de_regra(): void
    {
        $divida = $this->consorcio();

        Livewire::test(DebtShow::class, ['debt' => $divida])
            ->call('openContemplation')->set('newInstallmentAmount', '0')->call('saveContemplation')->assertHasErrors(['newInstallmentAmount']);

        self::assertFalse($divida->fresh()->isContemplated());
    }

    public function test_tela_da_divida_em_aberto_atualiza_saldo_registra_acordo_e_quita(): void
    {
        $divida = $this->servico()->createOpenDebt(['name' => 'Cartão', 'current_balance' => '4250.00']);

        Livewire::test(DebtShow::class, ['debt' => $divida])
            ->assertSee('Atualizar saldo')->assertSee('Registrar acordo')->assertSee('Sem parcelas ainda')
            ->call('openBalance')->set('balanceAmount', '4400')->call('saveBalance')->assertHasNoErrors()
            ->call('openAgreement')
            ->assertSet('agreementTotal', '4400.00')
            ->set('agreementTotal', '3000')->set('agreementInstallments', '4')->set('agreementFirstDue', '2026-11-15')
            ->call('saveAgreement')->assertHasNoErrors();

        $divida->refresh();
        self::assertSame(4, $divida->installments()->count());
        self::assertSame('1400.00', (string) $divida->agreement_discount);

        $outra = $this->servico()->createOpenDebt(['name' => 'Loja', 'current_balance' => '300.00']);
        Livewire::test(DebtShow::class, ['debt' => $outra])->call('markPaidOff');
        self::assertSame(DebtStatus::PaidOff, $outra->fresh()->status);
    }

    public function test_editar_consorcio_antes_da_contemplacao_nao_exige_categoria_de_despesa(): void
    {
        $divida = $this->consorcio();

        Livewire::test(DebtShow::class, ['debt' => $divida])
            ->call('openEdit')->set('editName', 'Consórcio da casa')->call('saveEdit')->assertHasNoErrors();

        $divida->refresh();
        self::assertSame('Consórcio da casa', $divida->name);
        self::assertSame('Consórcio', $divida->category->name);
        self::assertSame(Necessity::Investment, $divida->necessity);
    }

    public function test_reajuste_e_historico_so_aparecem_para_o_perfil_do_contrato(): void
    {
        $divida = $this->consorcio();
        $this->servico()->registerAdjustment($divida, '5', CarbonImmutable::parse('2027-11-10'));

        $outro = User::factory()->create();
        $perfilB = FinancialProfile::factory()->create(['owner_user_id' => $outro->id]);
        $membroB = ProfileMember::factory()->create(['profile_id' => $perfilB->id, 'user_id' => $outro->id, 'role' => MemberRole::Primary]);
        $this->actingAs($outro);
        app(ProfileContext::class)->set($perfilB, $membroB);

        self::assertSame(0, DebtAdjustment::query()->count());
        self::assertSame(0, Debt::query()->count());
    }
}
