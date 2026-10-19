<?php

namespace Tests\Feature;

use App\Enums\FundingMethod;
use App\Enums\GoalStatus;
use App\Enums\MemberRole;
use App\Enums\UserRole;
use App\Livewire\Goals\GoalsIndex;
use App\Models\Debt;
use App\Models\FinancialProfile;
use App\Models\Goal;
use App\Models\InvestmentRecord;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\DebtService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Objetivos: cadastro, edição, ligação a investimento ou consórcio, marcar como conquistado e excluir.
 */
class GoalFormTest extends TestCase
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

    private function consorcio(string $nome = 'Consórcio do apartamento'): Debt
    {
        return app(DebtService::class)->createConsortium([
            'consortium_type' => 'property', 'name' => $nome, 'credit_amount' => '100000.00', 'installment_amount' => '1000.00', 'term' => 100,
            'first_due_date' => '2026-11-10', 'paid_before' => 20,
        ]);
    }

    private function objetivo(array $campos = []): Goal
    {
        return Goal::create(array_merge([
            'name' => 'Viagem', 'priority' => 1, 'estimated_value' => '10000.00', 'funding_method' => FundingMethod::LumpSum, 'current_amount' => '0.00',
            'status' => GoalStatus::Active, 'created_by_user_id' => $this->usuario->id,
        ], $campos));
    }

    // ------------------------------------------------------------------ criar

    public function test_cria_objetivo_pelo_dinheiro_informado_com_prioridade_no_fim_da_fila(): void
    {
        $this->objetivo(['priority' => 3]);

        Livewire::test(GoalsIndex::class)->call('openForm')
            ->assertSet('priority', '4')
            ->set('name', 'Trocar de carro')->set('estimatedValue', '80.000,00')->set('targetDate', '2028-10-01')->set('currentAmount', '5000,00')
            ->call('save')->assertHasNoErrors();

        $novo = Goal::query()->where('name', 'Trocar de carro')->sole();
        self::assertSame('80000.00', (string) $novo->estimated_value);
        self::assertSame('5000.00', (string) $novo->current_amount);
        self::assertSame(4, $novo->priority);
        self::assertSame('2028-10-01', $novo->target_date->toDateString());
        self::assertSame(GoalStatus::Active, $novo->status);
        self::assertSame($this->usuario->id, $novo->created_by_user_id);
    }

    public function test_objetivo_parcelado_exige_o_valor_da_parcela(): void
    {
        Livewire::test(GoalsIndex::class)->call('openForm')
            ->set('name', 'Intercâmbio')->set('estimatedValue', '85000')->set('fundingMethod', 'installments')
            ->call('save')->assertHasErrors(['installmentAmount']);

        Livewire::test(GoalsIndex::class)->call('openForm')
            ->set('name', 'Intercâmbio')->set('estimatedValue', '85000')->set('fundingMethod', 'installments')->set('installmentAmount', '1400,00')
            ->call('save')->assertHasNoErrors();

        self::assertSame('1400.00', (string) Goal::query()->where('name', 'Intercâmbio')->sole()->installment_amount);
    }

    public function test_valida_nome_valor_e_formato(): void
    {
        Livewire::test(GoalsIndex::class)->call('openForm')->call('save')->assertHasErrors(['name', 'estimatedValue']);

        Livewire::test(GoalsIndex::class)->call('openForm')
            ->set('name', 'X')->set('estimatedValue', 'muito dinheiro')->call('save')->assertHasErrors(['estimatedValue']);

        Livewire::test(GoalsIndex::class)->call('openForm')
            ->set('name', 'X')->set('estimatedValue', '0,00')->call('save')->assertHasErrors(['estimatedValue']);

        self::assertSame(0, Goal::query()->count());
    }

    // ------------------------------------------------------------------ ligações

    public function test_liga_a_um_investimento_e_o_acumulado_e_o_saldo_dele(): void
    {
        $inv = InvestmentRecord::factory()->create(['profile_id' => $this->perfil->id, 'member_id' => $this->membro->id, 'current_amount' => '30000.00', 'name' => 'CDB Reserva']);

        Livewire::test(GoalsIndex::class)->call('openForm')
            ->set('name', 'Reserva')->set('estimatedValue', '60000')->set('linkType', 'investment')->set('linkedInvestmentId', $inv->id)->set('currentAmount', '999')
            ->call('save')->assertHasNoErrors();

        $objetivo = Goal::query()->where('name', 'Reserva')->sole();
        self::assertSame($inv->id, $objetivo->linked_investment_id);
        self::assertSame('0.00', (string) $objetivo->current_amount, 'com investimento ligado, o valor informado não vale');
        self::assertSame('30000.00', (string) $objetivo->accumulated());
    }

    public function test_ligar_sem_escolher_o_investimento_da_erro(): void
    {
        Livewire::test(GoalsIndex::class)->call('openForm')
            ->set('name', 'Reserva')->set('estimatedValue', '60000')->set('linkType', 'investment')
            ->call('save')->assertHasErrors(['linkedInvestmentId']);
    }

    public function test_liga_a_um_consorcio_usa_a_carta_e_avanca_pelas_parcelas(): void
    {
        $consorcio = $this->consorcio();

        Livewire::test(GoalsIndex::class)->call('openForm')
            ->set('linkType', 'debt')->set('linkedDebtId', $consorcio->id)
            ->assertSet('estimatedValue', '100000.00')->assertSet('name', 'Consórcio do apartamento')->assertSet('installmentAmount', '1000.00')
            ->call('save')->assertHasNoErrors();

        $objetivo = Goal::query()->sole();
        self::assertSame($consorcio->id, $objetivo->linked_debt_id);
        self::assertSame(FundingMethod::Installments, $objetivo->funding_method);
        self::assertSame(['paid' => 20, 'total' => 100], $objetivo->installmentProgress());
        self::assertSame(20.0, $objetivo->progressPercentage());
    }

    public function test_consorcio_que_ja_tem_objetivo_nao_e_oferecido_nem_aceito(): void
    {
        $consorcio = $this->consorcio();
        $this->objetivo(['name' => 'Já ligado', 'linked_debt_id' => $consorcio->id]);

        Livewire::test(GoalsIndex::class)->call('openForm')->set('linkType', 'debt')
            ->assertDontSee('Consórcio do apartamento · carta')
            ->set('name', 'Outro')->set('estimatedValue', '100000')->set('linkedDebtId', $consorcio->id)
            ->call('save')->assertHasErrors(['linkedDebtId']);

        self::assertSame(1, Goal::query()->count());
    }

    public function test_ao_editar_o_consorcio_do_proprio_objetivo_continua_na_lista(): void
    {
        $consorcio = $this->consorcio();
        $objetivo = $this->objetivo(['name' => 'Meu', 'linked_debt_id' => $consorcio->id, 'funding_method' => FundingMethod::Installments]);

        Livewire::test(GoalsIndex::class)->call('edit', $objetivo->id)
            ->assertSet('linkType', 'debt')->assertSet('linkedDebtId', $consorcio->id)->assertSee('Consórcio do apartamento')
            ->set('name', 'Meu renomeado')->call('save')->assertHasNoErrors();

        self::assertSame('Meu renomeado', $objetivo->fresh()->name);
        self::assertSame($consorcio->id, $objetivo->fresh()->linked_debt_id);
    }

    public function test_desligar_o_consorcio_volta_ao_dinheiro_informado(): void
    {
        $consorcio = $this->consorcio();
        $objetivo = $this->objetivo(['linked_debt_id' => $consorcio->id, 'funding_method' => FundingMethod::Installments]);

        Livewire::test(GoalsIndex::class)->call('edit', $objetivo->id)
            ->set('linkType', '')->set('fundingMethod', 'lump_sum')->set('currentAmount', '2500')->call('save')->assertHasNoErrors();

        $objetivo->refresh();
        self::assertNull($objetivo->linked_debt_id);
        self::assertFalse($objetivo->usesInstallments());
        self::assertSame('2500.00', (string) $objetivo->accumulated());
    }

    // ------------------------------------------------------------------ editar, conquistar, excluir

    public function test_edita_os_dados(): void
    {
        $objetivo = $this->objetivo(['name' => 'Viagem', 'estimated_value' => '10000.00']);

        Livewire::test(GoalsIndex::class)->call('edit', $objetivo->id)
            ->assertSet('name', 'Viagem')->assertSet('estimatedValue', '10000.00')
            ->set('estimatedValue', '12.500,50')->set('priority', '2')->call('save')->assertHasNoErrors();

        $objetivo->refresh();
        self::assertSame('12500.50', (string) $objetivo->estimated_value);
        self::assertSame(2, $objetivo->priority);
    }

    public function test_marca_como_conquistado_e_reabre(): void
    {
        $objetivo = $this->objetivo(['name' => 'Viagem para o Japão']);

        $tela = Livewire::test(GoalsIndex::class)->assertSee('Marcar como conquistado')->call('markAchieved', $objetivo->id);
        self::assertSame(GoalStatus::Achieved, $objetivo->fresh()->status);
        $tela->assertSee('Conquistados')->assertSee('Reabrir');

        $tela->call('reopen', $objetivo->id);
        self::assertSame(GoalStatus::Active, $objetivo->fresh()->status);
    }

    public function test_muda_a_situacao_pelo_formulario(): void
    {
        $objetivo = $this->objetivo();

        Livewire::test(GoalsIndex::class)->call('edit', $objetivo->id)->set('status', 'achieved')->call('save')->assertHasNoErrors();

        self::assertSame(GoalStatus::Achieved, $objetivo->fresh()->status);
    }

    public function test_exclui_o_objetivo_sem_apagar_o_investimento_ou_o_consorcio(): void
    {
        $consorcio = $this->consorcio();
        $inv = InvestmentRecord::factory()->create(['profile_id' => $this->perfil->id, 'member_id' => $this->membro->id]);
        $a = $this->objetivo(['name' => 'A', 'linked_debt_id' => $consorcio->id, 'funding_method' => FundingMethod::Installments]);
        $b = $this->objetivo(['name' => 'B', 'linked_investment_id' => $inv->id]);

        Livewire::test(GoalsIndex::class)->call('delete', $a->id)->call('delete', $b->id);

        self::assertSame(0, Goal::query()->count());
        self::assertNotNull($consorcio->fresh());
        self::assertNotNull($inv->fresh());
    }

    // ------------------------------------------------------------------ dono e privacidade

    public function test_objetivo_privado_do_conjuge_nao_aparece_nem_pode_ser_mexido_pelo_outro(): void
    {
        $conjuge = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => User::factory()->create()->id, 'role' => MemberRole::Secondary, 'name' => 'Helen']);
        $privado = $this->objetivo(['name' => 'Presente surpresa', 'member_id' => $this->membro->id, 'is_private' => true]);

        $this->actingAs($conjuge->user);
        app(ProfileContext::class)->set($this->perfil, $conjuge);

        Livewire::test(GoalsIndex::class)->assertDontSee('Presente surpresa');

        $this->expectException(ModelNotFoundException::class);
        Livewire::test(GoalsIndex::class)->call('delete', $privado->id);
    }

    public function test_guarda_dono_e_privacidade_so_quando_ha_dono(): void
    {
        ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => User::factory()->create()->id, 'role' => MemberRole::Secondary, 'name' => 'Helen']);

        Livewire::test(GoalsIndex::class)->call('openForm')
            ->set('name', 'Meu')->set('estimatedValue', '1000')->set('memberId', $this->membro->id)->set('isPrivate', true)->call('save')->assertHasNoErrors();
        Livewire::test(GoalsIndex::class)->call('openForm')
            ->set('name', 'Do casal')->set('estimatedValue', '1000')->set('memberId', '')->set('isPrivate', true)->call('save')->assertHasNoErrors();

        self::assertTrue(Goal::query()->where('name', 'Meu')->sole()->is_private);
        self::assertFalse(Goal::query()->where('name', 'Do casal')->sole()->is_private, 'objetivo do casal nunca é privado');
    }

    public function test_corretor_nao_entra(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Broker]));

        $this->get(route('goals.index'))->assertForbidden();
    }

    public function test_tela_mostra_o_botao_e_a_lista(): void
    {
        $this->objetivo(['name' => 'Viagem']);

        Livewire::test(GoalsIndex::class)->assertSee('Novo objetivo')->assertSee('Viagem')->assertSee('Editar');
    }
}
