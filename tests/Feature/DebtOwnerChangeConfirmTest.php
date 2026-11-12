<?php

namespace Tests\Feature;

use App\Enums\MemberRole;
use App\Livewire\Debts\DebtShow;
use App\Models\Debt;
use App\Models\DebtInstallment;
use App\Models\DebtSimulation;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\DebtService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Mudar o dono da dívida (ou ocultar) leva junto parcelas, despesas dos pagamentos já feitos e simulações guardadas: antes de gravar, a tela mostra o
 * que vai mudar e espera a confirmação. Sem nada para levar junto, ou sem mudança de dono, grava direto.
 */
class DebtOwnerChangeConfirmTest extends TestCase
{
    use RefreshDatabase;

    private FinancialProfile $perfil;

    private ProfileMember $ana;

    private ProfileMember $bruno;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('pt_BR');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-12 09:00:00'));
        $this->seed(TaxonomySeeder::class);

        $usuario = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $usuario->id]);
        $this->ana = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $usuario->id, 'role' => MemberRole::Primary, 'name' => 'Ana']);
        $this->bruno = ProfileMember::factory()->secondary()->create(['profile_id' => $this->perfil->id, 'user_id' => User::factory()->create()->id, 'name' => 'Bruno']);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($this->perfil, $this->ana);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** Financiamento da Ana com uma parcela já paga (uma despesa ligada). */
    private function financiamento(): Debt
    {
        $cat = ExpenseCategory::available()->where('name', 'Habitação')->firstOrFail();
        $divida = app(DebtService::class)->create([
            'kind' => 'financing', 'name' => 'Apartamento', 'creditor' => 'Caixa', 'amortization' => 'sac', 'principal' => '120000.00', 'rate' => '12',
            'rate_basis' => 'annual_nominal', 'term' => 12, 'first_due_date' => '2026-11-05', 'paid_before' => 0, 'member_id' => $this->ana->id,
            'category_id' => $cat->id, 'subcategory_id' => $cat->subcategories()->where('name', 'Prestação da casa')->firstOrFail()->id,
        ]);
        app(DebtService::class)->pay($divida->installments[0], CarbonImmutable::parse('2026-11-05'), null, null, null, true);

        return $divida->fresh();
    }

    private function abrirEdicao(Debt $divida)
    {
        return Livewire::test(DebtShow::class, ['debt' => $divida])->call('openEdit');
    }

    public function test_trocar_o_dono_pede_confirmacao_e_so_grava_no_segundo_clique(): void
    {
        $divida = $this->financiamento();
        $despesa = ExpenseRecord::query()->sole();
        self::assertSame($this->ana->id, $despesa->member_id);

        $tela = $this->abrirEdicao($divida)->set('editMemberId', $this->bruno->id)->call('saveEdit')->assertHasNoErrors()
            ->assertSet('editAwaitingOwnerConfirm', true)->assertSet('showEdit', true)
            ->assertSee('Confirme antes de salvar')->assertSee('12 parcelas e 1 despesa de pagamento já feito')->assertSee('Confirmar e salvar');

        // Nada mudou ainda.
        self::assertSame($this->ana->id, $divida->fresh()->member_id);
        self::assertSame($this->ana->id, $despesa->fresh()->member_id);

        $tela->call('saveEdit')->assertHasNoErrors()->assertSet('editAwaitingOwnerConfirm', false)->assertSet('showEdit', false);

        self::assertSame($this->bruno->id, $divida->fresh()->member_id);
        self::assertSame($this->bruno->id, $despesa->fresh()->member_id);
        self::assertSame($this->bruno->id, DebtInstallment::query()->where('debt_id', $divida->id)->value('member_id'));
    }

    public function test_ocultar_tambem_pede_confirmacao(): void
    {
        $divida = $this->financiamento();

        $this->abrirEdicao($divida)->set('editPrivate', true)->call('saveEdit')->assertSet('editAwaitingOwnerConfirm', true);
        self::assertFalse($divida->fresh()->is_private);
    }

    public function test_voltar_atras_na_escolha_desfaz_o_pedido_de_confirmacao(): void
    {
        $tela = $this->abrirEdicao($this->financiamento())->set('editMemberId', $this->bruno->id)->call('saveEdit')->assertSet('editAwaitingOwnerConfirm', true);

        // Mudou de ideia e escolheu outra pessoa: a confirmação já mostrada não vale para a nova escolha.
        $tela->set('editMemberId', '')->assertSet('editAwaitingOwnerConfirm', false);
        $tela->call('saveEdit')->assertSet('editAwaitingOwnerConfirm', true);
    }

    public function test_so_o_nome_mudou_grava_direto_mesmo_com_pagamentos(): void
    {
        $divida = $this->financiamento();

        $this->abrirEdicao($divida)->set('editName', 'Apartamento novo')->call('saveEdit')->assertSet('editAwaitingOwnerConfirm', false)->assertSet('showEdit', false);

        self::assertSame('Apartamento novo', $divida->fresh()->name);
    }

    public function test_divida_em_aberto_sem_nada_para_levar_junto_grava_direto(): void
    {
        $divida = app(DebtService::class)->createOpenDebt(['name' => 'Cartão atrasado', 'current_balance' => '1000.00', 'member_id' => $this->ana->id]);

        $this->abrirEdicao($divida)->set('editMemberId', $this->bruno->id)->call('saveEdit')->assertSet('editAwaitingOwnerConfirm', false)->assertSet('showEdit', false);

        self::assertSame($this->bruno->id, $divida->fresh()->member_id);
    }

    public function test_as_simulacoes_guardadas_entram_na_conta_do_aviso(): void
    {
        $divida = app(DebtService::class)->createOpenDebt(['name' => 'Cartão atrasado', 'current_balance' => '1000.00', 'member_id' => $this->ana->id]);
        DebtSimulation::create(['profile_id' => $this->perfil->id, 'debt_id' => $divida->id, 'name' => 'S', 'balance' => '1000.00', 'proposals' => []]);

        $this->abrirEdicao($divida)->set('editMemberId', $this->bruno->id)->call('saveEdit')
            ->assertSet('editAwaitingOwnerConfirm', true)->assertSee('1 simulação guardada');

        self::assertSame($this->ana->id, $divida->fresh()->member_id);
    }
}
