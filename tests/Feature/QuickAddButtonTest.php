<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\MemberRole;
use App\Livewire\CashFlow\CashFlowIndex;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Botão redondo "+" fixo (x-quick-add): quem vê, para onde leva e como o
 * Fluxo de caixa reage ao atalho.
 */
class QuickAddButtonTest extends TestCase
{
    use RefreshDatabase;

    private User $cliente;

    private FinancialProfile $perfil;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cliente = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->cliente->id]);
        ProfileMember::factory()->create([
            'profile_id' => $this->perfil->id,
            'user_id' => $this->cliente->id,
            'role' => MemberRole::Primary,
        ]);
    }

    private function comoCliente(): void
    {
        $this->actingAs($this->cliente);
        app(ProfileContext::class)->set($this->perfil, $this->perfil->members()->first());
    }

    public function test_cliente_ve_o_botao_com_as_tres_acoes(): void
    {
        $this->comoCliente();

        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        self::assertStringContainsString('data-quick-add', $html);
        foreach (['Adicionar receita', 'Adicionar despesa', 'Falar despesa'] as $acao) {
            self::assertStringContainsString($acao, $html);
        }
    }

    public function test_fora_do_fluxo_de_caixa_o_atalho_leva_para_la(): void
    {
        $this->comoCliente();

        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        self::assertStringContainsString('naTela: false', $html);
        self::assertStringContainsString(str_replace('/', '\/', route('cashflow.index')), $html);
    }

    public function test_no_fluxo_de_caixa_o_atalho_abre_na_hora(): void
    {
        $this->comoCliente();

        $html = $this->get(route('cashflow.index'))->assertOk()->getContent();

        self::assertStringContainsString('naTela: true', $html);
    }

    public function test_consultor_dentro_do_perfil_de_um_cliente_ve_o_botao(): void
    {
        $consultor = User::factory()->consultant()->create();
        ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id,
            'client_id' => $this->cliente->id,
            'status' => ConsultantClientStatus::Active,
        ]);

        $this->actingAs($consultor)
            ->withSession(['cerne.active_profile_id' => $this->perfil->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-quick-add', false);
    }

    public function test_corretor_nao_ve_o_botao(): void
    {
        $corretor = User::factory()->broker()->create();
        ConsultantClient::factory()->create([
            'consultant_id' => $corretor->id,
            'client_id' => $this->cliente->id,
            'status' => ConsultantClientStatus::Active,
        ]);

        $this->actingAs($corretor)
            ->withSession(['cerne.active_profile_id' => $this->perfil->id])
            ->get(route('insurance.index'))
            ->assertOk()
            ->assertDontSee('data-quick-add', false);
    }

    public function test_painel_do_consultor_nao_tem_o_botao(): void
    {
        $consultor = User::factory()->consultant()->create();

        $this->actingAs($consultor)
            ->get(route('consultant.portfolio'))
            ->assertOk()
            ->assertDontSee('data-quick-add', false);
    }

    public function test_minha_conta_e_assinatura_nao_tem_o_botao(): void
    {
        $this->comoCliente();

        $this->get(route('my-account'))->assertOk()->assertDontSee('data-quick-add', false);
        $this->get(route('subscription.index'))->assertOk()->assertDontSee('data-quick-add', false);
    }

    public function test_acao_despesa_na_url_abre_o_formulario_de_despesa(): void
    {
        $this->comoCliente();

        Livewire::withQueryParams(['acao' => 'despesa'])
            ->test(CashFlowIndex::class)
            ->assertSet('showExpenseForm', true)
            ->assertSet('showIncomeForm', false);
    }

    public function test_acao_receita_na_url_abre_o_formulario_de_receita(): void
    {
        $this->comoCliente();

        Livewire::withQueryParams(['acao' => 'receita'])
            ->test(CashFlowIndex::class)
            ->assertSet('showIncomeForm', true)
            ->assertSet('showExpenseForm', false);
    }

    public function test_acao_voz_so_destaca_o_botao_de_voz(): void
    {
        $this->comoCliente();

        Livewire::withQueryParams(['acao' => 'voz'])
            ->test(CashFlowIndex::class)
            ->assertSet('realcarVoz', true)
            ->assertSet('showExpenseForm', false)
            ->assertSet('showIncomeForm', false)
            ->assertSee('Toque em "Falar despesa"', false);
    }

    public function test_sem_acao_ou_com_acao_desconhecida_nada_abre(): void
    {
        $this->comoCliente();

        foreach ([[], ['acao' => 'qualquer-coisa']] as $query) {
            Livewire::withQueryParams($query)
                ->test(CashFlowIndex::class)
                ->assertSet('showExpenseForm', false)
                ->assertSet('showIncomeForm', false)
                ->assertSet('realcarVoz', false);
        }
    }

    public function test_evento_do_botao_sempre_abre_e_nunca_fecha(): void
    {
        $this->comoCliente();

        Livewire::test(CashFlowIndex::class)
            ->dispatch('quick-add', tipo: 'despesa')
            ->assertSet('showExpenseForm', true)
            ->dispatch('quick-add', tipo: 'despesa')
            ->assertSet('showExpenseForm', true)
            ->dispatch('quick-add', tipo: 'receita')
            ->assertSet('showIncomeForm', true)
            ->assertSet('showExpenseForm', false);
    }

    public function test_voz_pelo_botao_nao_e_seguida_de_gravacao_de_lancamento(): void
    {
        $this->comoCliente();

        Livewire::test(CashFlowIndex::class)
            ->dispatch('quick-add', tipo: 'voz')
            ->assertSet('realcarVoz', true);

        $this->assertDatabaseCount('expense_records', 0);
        $this->assertDatabaseCount('income_records', 0);
    }
}
