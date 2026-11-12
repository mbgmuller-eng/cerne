<?php

namespace Tests\Feature;

use App\Enums\MemberRole;
use App\Livewire\Debts\DebtProposalsPanel;
use App\Livewire\Debts\DebtShow;
use App\Models\Debt;
use App\Models\DebtSimulation;
use App\Models\ExpenseCategory;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\DebtService;
use App\Support\Debts\DebtProposals;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Propostas de acordo" na tela da dívida em aberto: a lista única das ofertas guardadas (as das simulações e as cadastradas uma a uma),
 * cadastro, edição, exclusão, comparação em duas visões (saldo da época e saldo de hoje) e o atalho para registrar o acordo.
 */
class DebtProposalsPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private FinancialProfile $perfil;

    private ProfileMember $membro;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('pt_BR');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-12 09:00:00'));
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

    private function divida(string $saldo = '5000.00', array $extra = []): Debt
    {
        return app(DebtService::class)->createOpenDebt(array_merge(['name' => 'Nubank atrasado', 'creditor' => 'Nubank', 'current_balance' => $saldo], $extra));
    }

    private function painel(Debt $divida)
    {
        return Livewire::test(DebtProposalsPanel::class, ['debtId' => $divida->id]);
    }

    /** Uma simulação antiga (recipiente) com as propostas dadas, feita quando o saldo era $saldo. */
    private function simulacao(Debt $divida, string $nome, array $propostas, string $saldo = '4250.00', string $quando = '2026-10-20 10:00:00'): DebtSimulation
    {
        $sim = DebtSimulation::create(['profile_id' => $this->perfil->id, 'debt_id' => $divida->id, 'name' => $nome, 'balance' => $saldo, 'proposals' => $propostas]);
        DB::table('debt_simulations')->where('id', $sim->id)->update(['created_at' => $quando, 'updated_at' => $quando]);

        return $sim->fresh();
    }

    private function formulario($painel, array $campos = [])
    {
        $padrao = ['pLabel' => 'Oferta do Banco X', 'pTotal' => '3600,00', 'pDown' => '600,00', 'pInstallments' => '12', 'pReceivedOn' => '2026-11-10', 'pNote' => 'falei com a Maria'];

        foreach (array_merge($padrao, $campos) as $campo => $valor) {
            $painel->set($campo, $valor);
        }

        return $painel;
    }

    // ------------------------------------------------------------------ lista

    public function test_as_propostas_de_uma_simulacao_antiga_aparecem_na_lista_da_mais_nova_para_a_mais_antiga(): void
    {
        $divida = $this->divida();
        $this->simulacao($divida, 'Rodada de outubro', [
            ['label' => 'À vista', 'total' => '2500.00', 'down' => '0.00', 'installments' => 1],
            ['label' => 'Parcelado', 'total' => '3600.00', 'down' => '600.00', 'installments' => 12],
        ]);
        $this->simulacao($divida, 'Oferta de novembro', [['label' => 'Oferta nova', 'total' => '3000.00', 'down' => '0.00', 'installments' => 10, 'received_on' => '2026-11-05']], '4600.00', '2026-11-06 10:00:00');

        $painel = $this->painel($divida)->assertSee('Propostas de acordo')
            ->assertSee('À vista')->assertSee('Parcelado')->assertSee('Oferta nova')
            ->assertSee('entrada de R$ 600,00 + 12 parcelas')
            ->assertSee('recebida em 20/10/2026')->assertSee('recebida em 05/11/2026');

        // Mais nova primeiro: a de 05/11 vem antes das de 20/10.
        $html = $painel->html();
        self::assertLessThan(strpos($html, 'À vista'), strpos($html, 'Oferta nova'));
    }

    public function test_divida_em_aberto_sem_proposta_mostra_o_convite_para_cadastrar(): void
    {
        $this->painel($this->divida())->assertSee('Propostas de acordo')->assertSee('Nenhuma proposta guardada ainda')->assertSee('Nova proposta');
    }

    public function test_divida_com_acordo_e_sem_proposta_nao_mostra_o_painel(): void
    {
        $divida = $this->divida();
        $cat = ExpenseCategory::available()->where('name', 'Financeiros')->firstOrFail();
        app(DebtService::class)->registerAgreement($divida, ['total' => '3000.00', 'installments' => 6, 'first_due_date' => '2026-12-05', 'category_id' => $cat->id]);

        $this->painel($divida->fresh())->assertDontSee('Propostas de acordo');
    }

    public function test_a_tela_da_divida_traz_o_painel(): void
    {
        Livewire::test(DebtShow::class, ['debt' => $this->divida()])->assertSeeLivewire(DebtProposalsPanel::class);
    }

    // ------------------------------------------------------------------ cadastro

    public function test_cadastrar_uma_proposta_nova_cria_um_registro_de_uma_proposta_com_data_observacao_e_saldo_de_hoje(): void
    {
        $divida = $this->divida('5000.00');

        $this->formulario($this->painel($divida)->call('openNew'))->call('save')->assertHasNoErrors()
            ->assertSee('Proposta cadastrada.')->assertSee('Oferta do Banco X')->assertSee('falei com a Maria');

        $sim = DebtSimulation::query()->sole();
        self::assertSame('Oferta do Banco X', $sim->name);
        self::assertSame('5000.00', $sim->balance, 'sem saldo informado vale o de hoje');
        self::assertSame($this->membro->id === null ? null : $divida->member_id, $sim->member_id);
        self::assertSame('2026-11-10', $sim->proposals[0]['received_on']);
        self::assertSame('600.00', $sim->proposals[0]['down']);
        self::assertSame('3600.00', $sim->proposals[0]['total']);

        // As propostas ficam cifradas no banco, como as das simulações.
        $cru = (string) DB::table('debt_simulations')->where('id', $sim->id)->value('proposals');
        self::assertStringNotContainsString('Maria', $cru);
        self::assertStringNotContainsString('3600', $cru);
    }

    public function test_o_saldo_da_epoca_pode_ser_informado(): void
    {
        $divida = $this->divida('5000.00');

        $this->formulario($this->painel($divida)->call('openNew'), ['pBalance' => '4100,00'])->call('save')->assertHasNoErrors();

        self::assertSame('4100.00', DebtSimulation::query()->sole()->balance);
    }

    public function test_validacoes_do_cadastro(): void
    {
        $divida = $this->divida();
        $painel = $this->painel($divida)->call('openNew');

        $this->formulario($painel, ['pLabel' => '', 'pTotal' => 'caro', 'pInstallments' => '0', 'pDown' => ''])->call('save')
            ->assertHasErrors(['pLabel', 'pTotal', 'pInstallments']);

        $this->formulario($painel, ['pLabel' => 'X', 'pTotal' => '1000,00', 'pDown' => '1000,00', 'pInstallments' => '3'])->call('save')
            ->assertHasErrors('pDown')->assertSee('A entrada precisa ser menor que o total.');

        $this->formulario($painel, ['pDown' => '', 'pReceivedOn' => '2026-12-31'])->call('save')->assertHasErrors('pReceivedOn');
        self::assertSame(0, DebtSimulation::query()->count());
    }

    public function test_ha_um_teto_de_registros_por_divida(): void
    {
        $divida = $this->divida();

        for ($i = 1; $i <= DebtShow::MAX_SAVED_SIMULATIONS; $i++) {
            DebtSimulation::create(['profile_id' => $this->perfil->id, 'debt_id' => $divida->id, 'name' => "S{$i}", 'balance' => '5000.00', 'proposals' => [['label' => 'A', 'total' => '100.00', 'down' => '0.00', 'installments' => 1]]]);
        }

        $this->formulario($this->painel($divida)->call('openNew'))->call('save')->assertHasErrors('pLabel');
        self::assertSame(DebtShow::MAX_SAVED_SIMULATIONS, DebtSimulation::query()->count());
    }

    // ------------------------------------------------------------------ edição e exclusão

    public function test_editar_muda_so_a_proposta_escolhida_dentro_de_uma_rodada(): void
    {
        $divida = $this->divida();
        $sim = $this->simulacao($divida, 'Rodada', [
            ['label' => 'À vista', 'total' => '2500.00', 'down' => '0.00', 'installments' => 1],
            ['label' => 'Parcelado', 'total' => '3600.00', 'down' => '0.00', 'installments' => 12],
        ]);

        $this->painel($divida)->call('openEdit', $sim->id.':1')->assertSet('pLabel', 'Parcelado')
            ->set('pTotal', '3300,00')->set('pNote', 'negociei')->call('save')->assertHasNoErrors()->assertSee('Proposta atualizada.');

        $sim->refresh();
        self::assertSame('2500.00', $sim->proposals[0]['total'], 'a outra proposta da rodada não muda');
        self::assertSame('3300.00', $sim->proposals[1]['total']);
        self::assertSame('negociei', $sim->proposals[1]['note']);
        self::assertSame('Rodada', $sim->name, 'rodada de várias propostas mantém o nome');
    }

    public function test_editar_um_registro_de_uma_proposta_atualiza_o_nome_e_o_saldo_da_epoca(): void
    {
        $divida = $this->divida();
        $this->formulario($this->painel($divida)->call('openNew'))->call('save');
        $chave = DebtSimulation::query()->sole()->id.':0';

        $this->painel($divida)->call('openEdit', $chave)->set('pLabel', 'Renomeada')->set('pBalance', '4000,00')->call('save')->assertHasNoErrors();

        $sim = DebtSimulation::query()->sole();
        self::assertSame('Renomeada', $sim->name);
        self::assertSame('4000.00', $sim->balance);
    }

    public function test_excluir_apaga_o_registro_de_uma_proposta_e_so_o_item_numa_rodada(): void
    {
        $divida = $this->divida();
        $rodada = $this->simulacao($divida, 'Rodada', [
            ['label' => 'A', 'total' => '2500.00', 'down' => '0.00', 'installments' => 1],
            ['label' => 'B', 'total' => '3600.00', 'down' => '0.00', 'installments' => 12],
        ]);
        $sozinha = $this->simulacao($divida, 'Sozinha', [['label' => 'C', 'total' => '900.00', 'down' => '0.00', 'installments' => 3]]);

        $this->painel($divida)->set('selected', [$rodada->id.':0'])->call('delete', $sozinha->id.':0')->assertSet('selected', []);
        self::assertNull(DebtSimulation::query()->find($sozinha->id));

        $this->painel($divida)->call('delete', $rodada->id.':0');
        $rodada->refresh();
        self::assertCount(1, $rodada->proposals);
        self::assertSame('B', $rodada->proposals[0]['label']);
    }

    // ------------------------------------------------------------------ comparação

    public function test_comparar_mostra_o_desconto_na_epoca_e_contra_o_saldo_de_hoje_com_os_destaques(): void
    {
        // Hoje o saldo é 5.000. A simulação antiga foi feita com saldo de 4.250.
        $divida = $this->divida('5000.00');
        $antiga = $this->simulacao($divida, 'Antiga', [['label' => 'Antiga 12x', 'total' => '3000.00', 'down' => '0.00', 'installments' => 12]], '4250.00');
        $this->formulario($this->painel($divida)->call('openNew'), ['pLabel' => 'Nova com entrada', 'pTotal' => '2800,00', 'pDown' => '800,00', 'pInstallments' => '4', 'pNote' => ''])->call('save');
        $nova = DebtSimulation::query()->where('name', 'Nova com entrada')->sole();

        $painel = $this->painel($divida)->set('selected', [$antiga->id.':0', $nova->id.':0'])
            ->assertSee('Comparação (2 propostas)')
            // saldo da época: 4.250 − 3.000 = 1.250 (29,4%); hoje: 5.000 − 3.000 = 2.000 (40,0%)
            ->assertSee('R$ 1.250,00 (29,4%)')->assertSee('R$ 2.000,00 a menos (40,0%)')
            // a nova: entrada 800 + 4 × 500; saldo da época = hoje (5.000): 2.200 (44,0%)
            ->assertSee('R$ 2.200,00 (44,0%)')->assertSee('4 × R$ 500,00')->assertSee('R$ 800,00');

        $html = $painel->html();
        self::assertStringContainsString('data-best-total', $html);
        self::assertStringContainsString('menor custo', $html);
    }

    public function test_so_uma_marcada_pede_mais_uma(): void
    {
        $divida = $this->divida();
        $sim = $this->simulacao($divida, 'S', [['label' => 'A', 'total' => '100.00', 'down' => '0.00', 'installments' => 1]]);

        $this->painel($divida)->set('selected', [$sim->id.':0'])->assertSee('Marque mais uma proposta para comparar.')->assertDontSee('Comparação (');
    }

    public function test_a_conta_de_comparacao_isolada(): void
    {
        $linhas = [
            ['key' => 'a:0', 'label' => 'À vista', 'total' => '2500.00', 'down' => '0.00', 'installments' => 1, 'balance_at' => '4250.00', 'received_on' => '2026-10-20'],
            ['key' => 'b:0', 'label' => 'Parcelado', 'total' => '3600.00', 'down' => '600.00', 'installments' => 12, 'balance_at' => '4250.00', 'received_on' => '2026-10-20'],
        ];

        $r = DebtProposals::compare($linhas, '4250.00');

        self::assertSame(0, $r['best_total']);
        self::assertSame(1, $r['lightest_month']);
        self::assertSame('1750.00', $r['rows'][0]['discount_then']);
        self::assertSame('250.00', $r['rows'][1]['monthly']);
        self::assertSame('650.00', $r['rows'][1]['discount_today']);
        self::assertSame([], DebtProposals::compare([], '1.00')['rows']);
    }

    // ------------------------------------------------------------------ usar a proposta

    public function test_usar_esta_proposta_avisa_a_tela_e_ela_abre_o_registro_do_acordo_preenchido(): void
    {
        $divida = $this->divida();
        $sim = $this->simulacao($divida, 'S', [['label' => 'Boa', 'total' => '3600.00', 'down' => '600.00', 'installments' => 12]]);

        $this->painel($divida)->call('useProposal', $sim->id.':0')->assertDispatched('use-agreement-proposal', total: '3600.00', down: '600.00', installments: 12);

        Livewire::test(DebtShow::class, ['debt' => $divida])->dispatch('use-agreement-proposal', total: '3600.00', down: '600.00', installments: 12)
            ->assertSet('showAgreement', true)->assertSet('agreementTotal', '3600.00')->assertSet('agreementDown', '600.00')->assertSet('agreementInstallments', '12');
    }

    public function test_guardar_uma_simulacao_no_simulador_avisa_o_painel(): void
    {
        Livewire::test(DebtShow::class, ['debt' => $this->divida()])->call('openAgreementSim')
            ->set('agSimRows.0.total', '2500,00')->set('agSimRows.1.total', '3600,00')->call('simulateAgreement')
            ->set('agSimName', 'Rodada')->call('saveAgreementSimulation')->assertDispatched('simulations-changed');
    }

    // ------------------------------------------------------------------ isolamento

    public function test_chave_de_outra_divida_ou_inventada_da_404(): void
    {
        $divida = $this->divida();
        $outra = $this->divida('900.00', ['name' => 'Outra']);
        $dela = $this->simulacao($outra, 'Da outra', [['label' => 'X', 'total' => '500.00', 'down' => '0.00', 'installments' => 1]]);

        $this->painel($divida)->call('openEdit', $dela->id.':0')->assertNotFound();
        $this->painel($divida)->call('delete', $dela->id.':0')->assertNotFound();
        $this->painel($divida)->call('useProposal', $dela->id.':0')->assertNotFound();
        $this->painel($divida)->call('openEdit', 'inventada')->assertNotFound();
        self::assertNotNull($dela->fresh());
    }

    public function test_o_conjuge_nao_abre_o_painel_de_uma_divida_oculta(): void
    {
        $bruno = User::factory()->create();
        $membroBruno = ProfileMember::factory()->secondary()->create(['profile_id' => $this->perfil->id, 'user_id' => $bruno->id, 'name' => 'Bruno']);
        $divida = $this->divida('5000.00', ['member_id' => $this->membro->id, 'is_private' => true]);
        $this->formulario($this->painel($divida)->call('openNew'))->call('save');
        self::assertTrue(DebtSimulation::query()->sole()->is_private, 'a proposta herda a privacidade da dívida');

        $this->actingAs($bruno);
        app(ProfileContext::class)->set($this->perfil, $membroBruno);

        Livewire::test(DebtProposalsPanel::class, ['debtId' => $divida->id])->assertNotFound();
    }

    public function test_corretor_nao_abre_o_painel(): void
    {
        $divida = $this->divida();
        $this->actingAs(User::factory()->broker()->create());

        Livewire::test(DebtProposalsPanel::class, ['debtId' => $divida->id])->assertForbidden();
    }
}
