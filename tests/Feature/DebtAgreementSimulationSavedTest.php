<?php

namespace Tests\Feature;

use App\Enums\DebtInstallmentStatus;
use App\Enums\MemberRole;
use App\Livewire\Debts\DebtShow;
use App\Models\Debt;
use App\Models\DebtInstallment;
use App\Models\DebtSimulation;
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
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Simulador de acordo: entrada + parcelas, até 10 propostas, e simulações GUARDADAS para comparar com uma proposta nova no futuro. A entrada
 * entra no cálculo, no cronograma do acordo registrado e no aviso do formulário, todos pela mesma conta (DebtService::agreementSchedule).
 */
class DebtAgreementSimulationSavedTest extends TestCase
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

    private function cartao(string $saldo = '4250.00', array $extra = []): Debt
    {
        return app(DebtService::class)->createOpenDebt(array_merge(['name' => 'Cartão atrasado', 'creditor' => 'Banco Y', 'current_balance' => $saldo], $extra));
    }

    private function tela(Debt $divida)
    {
        return Livewire::test(DebtShow::class, ['debt' => $divida])->call('openAgreementSim');
    }

    /** Preenche as duas propostas padrão: à vista 2.500 e parcelado 3.600 com entrada de 600 em 12 parcelas. */
    private function comProposta($tela, string $avista = '2500,00', string $total = '3600,00', string $entrada = '600,00')
    {
        return $tela->set('agSimRows.0.total', $avista)->set('agSimRows.1.total', $total)->set('agSimRows.1.down', $entrada)->set('agSimRows.1.installments', '12');
    }

    // ------------------------------------------------------------------ cálculo com entrada

    public function test_entrada_entra_na_parcela_e_na_taxa_implicita(): void
    {
        $r = DebtAgreementSimulator::compare('4250.00', [
            ['label' => 'À vista', 'total' => '2500.00', 'installments' => 1],
            ['label' => 'Com entrada', 'total' => '3600.00', 'down' => '600.00', 'installments' => 12],
            ['label' => 'Sem entrada', 'total' => '3600.00', 'installments' => 12],
        ]);

        [, $comEntrada, $semEntrada] = $r['proposals'];
        self::assertSame('600.00', $comEntrada['down']);
        self::assertSame('250.00', $comEntrada['monthly'], '(3.600 − 600) ÷ 12');
        self::assertSame('300.00', $semEntrada['monthly']);
        self::assertSame('0.00', $semEntrada['down']);
        self::assertNotNull($comEntrada['implicit_rate']);
        // Os mesmos R$ 1.100 de custo (3.600 − 2.500 à vista) recaem sobre um valor financiado MENOR quando há entrada (2.500 − 600), então a taxa
        // implícita com entrada é MAIOR que a do mesmo total sem entrada. É por isso que a conta desconta a entrada do que está sendo financiado.
        self::assertGreaterThan($semEntrada['implicit_rate'], $comEntrada['implicit_rate']);
    }

    public function test_entrada_maior_ou_igual_ao_total_nao_conta(): void
    {
        $r = DebtAgreementSimulator::compare('1000.00', [['label' => 'X', 'total' => '500.00', 'down' => '500.00', 'installments' => 5]]);

        self::assertSame('0.00', $r['proposals'][0]['down']);
        self::assertSame('100.00', $r['proposals'][0]['monthly']);
    }

    // ------------------------------------------------------------------ cronograma do acordo

    public function test_cronograma_sem_entrada_e_com_entrada(): void
    {
        $primeira = CarbonImmutable::parse('2026-11-10');

        $sem = DebtService::agreementSchedule('100.00', 3, '0.00', $primeira);
        self::assertSame(['33.33', '33.33', '33.34'], array_column($sem, 'amount'));
        self::assertSame(['2026-11-10', '2026-12-10', '2027-01-10'], array_map(fn ($l) => $l['due']->toDateString(), $sem));

        $com = DebtService::agreementSchedule('100.00', 3, '40.00', $primeira);
        self::assertSame(['40.00', '20.00', '20.00', '20.00'], array_column($com, 'amount'));
        self::assertSame(['2026-11-10', '2026-12-10', '2027-01-10', '2027-02-10'], array_map(fn ($l) => $l['due']->toDateString(), $com));
        self::assertTrue($com[0]['is_down']);
        self::assertFalse($com[1]['is_down']);
    }

    public function test_registrar_acordo_com_entrada_cria_a_entrada_como_primeira_parcela(): void
    {
        $divida = $this->cartao();
        $cat = ExpenseCategory::available()->where('name', 'Financeiros')->firstOrFail();

        app(DebtService::class)->registerAgreement($divida, [
            'total' => '3600.00', 'installments' => 12, 'down_payment' => '600.00', 'first_due_date' => '2026-12-05', 'category_id' => $cat->id,
        ]);

        $parcelas = DebtInstallment::query()->where('debt_id', $divida->id)->orderBy('number')->get();
        self::assertCount(13, $parcelas);
        self::assertSame('600.00', $parcelas[0]->amount);
        self::assertSame('2026-12-05', $parcelas[0]->due_date->toDateString());
        self::assertSame('250.00', $parcelas[1]->amount);
        self::assertSame('2027-01-05', $parcelas[1]->due_date->toDateString());
        self::assertSame('3600.00', \App\Support\Money::parse(DB::table('debt_installments')->where('debt_id', $divida->id)->sum('amount')));

        $divida->refresh();
        self::assertSame(13, $divida->term);
        self::assertSame('250.00', $divida->installment_amount, 'a parcela regular é a de depois da entrada');
        self::assertSame('3600.00', $divida->agreement_total);
        self::assertSame(DebtInstallmentStatus::Open, $parcelas[0]->status);
    }

    public function test_entrada_do_tamanho_do_total_e_recusada(): void
    {
        $divida = $this->cartao();
        $cat = ExpenseCategory::available()->where('name', 'Financeiros')->firstOrFail();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('entrada');

        app(DebtService::class)->registerAgreement($divida, ['total' => '1000.00', 'installments' => 2, 'down_payment' => '1000.00', 'first_due_date' => '2026-12-05', 'category_id' => $cat->id]);
    }

    // ------------------------------------------------------------------ tela do simulador

    public function test_o_resultado_mostra_entrada_mais_parcelas_e_ate_dez_propostas(): void
    {
        $tela = $this->comProposta($this->tela($this->cartao()))->call('simulateAgreement')->assertHasNoErrors();

        $tela->assertSee('entrada de R$ 600,00 + 12 parcelas de R$ 250,00');
        self::assertSame(10, DebtShow::MAX_SIM_PROPOSALS);

        $tela = $this->tela($this->cartao());
        for ($i = 0; $i < 20; $i++) {
            $tela->call('addAgreementSimRow');
        }
        self::assertCount(10, $tela->get('agSimRows'));
    }

    public function test_entrada_precisa_ser_menor_que_o_total_na_tela(): void
    {
        $this->comProposta($this->tela($this->cartao()), '2500,00', '3600,00', '3600,00')->call('simulateAgreement')
            ->assertHasErrors('agSimRows.1.down')->assertSee('A entrada precisa ser menor que o total.');
    }

    public function test_usar_a_proposta_leva_a_entrada_ao_registro_e_o_aviso_mostra_as_parcelas(): void
    {
        $divida = $this->cartao();
        $cat = ExpenseCategory::available()->where('name', 'Financeiros')->firstOrFail();

        $tela = $this->comProposta($this->tela($divida))->call('simulateAgreement')->call('useAgreementProposal', 1);

        self::assertSame('600.00', $tela->get('agreementDown'));
        $tela->set('agreementFirstDue', '2026-12-05')
            ->assertSee('Serão criadas 13 parcelas: a entrada de R$ 600,00 em 05/12/2026 e 12 parcelas de R$ 250,00, de 05/01/2027 a 05/12/2027.')
            ->assertSee('Desconto de R$ 650,00 sobre o saldo de hoje.')
            ->set('agreementCategoryId', $cat->id)->call('saveAgreement')->assertHasNoErrors();

        self::assertSame(13, DebtInstallment::query()->where('debt_id', $divida->id)->count());
    }

    public function test_o_aviso_do_acordo_sem_entrada_continua_dizendo_as_parcelas(): void
    {
        $divida = $this->cartao();

        Livewire::test(DebtShow::class, ['debt' => $divida])->call('openAgreement')
            ->set('agreementTotal', '3000,00')->set('agreementInstallments', '12')->set('agreementFirstDue', '2026-12-05')
            ->assertSee('Serão criadas 12 parcelas de R$ 250,00, de 05/12/2026 a 05/11/2027, e a dívida passa a ter parcelas no mês.');
    }

    // ------------------------------------------------------------------ simulações guardadas

    public function test_guardar_a_simulacao_grava_propostas_e_saldo_e_cifra_as_propostas(): void
    {
        $divida = $this->cartao();

        $this->comProposta($this->tela($divida))->set('agSimGrowth', '8')->call('simulateAgreement')
            ->set('agSimName', 'Propostas do Banco Y em nov/2026')->call('saveAgreementSimulation')->assertHasNoErrors()
            ->assertSee('Simulação guardada.')->assertSee('Propostas do Banco Y em nov/2026');

        $sim = DebtSimulation::query()->sole();
        self::assertSame($divida->id, $sim->debt_id);
        self::assertSame('4250.00', $sim->balance);
        self::assertSame('8', $sim->growth_percent);
        self::assertCount(2, $sim->proposals);
        self::assertSame('600.00', $sim->proposals[1]['down']);

        // As propostas (valores de negociação) ficam cifradas no banco.
        $cru = (string) DB::table('debt_simulations')->where('id', $sim->id)->value('proposals');
        self::assertStringNotContainsString('Parcelado', $cru);
        self::assertStringNotContainsString('3600', $cru);
    }

    public function test_guardar_exige_nome_e_propostas_validas(): void
    {
        $divida = $this->cartao();

        $this->comProposta($this->tela($divida))->call('saveAgreementSimulation')->assertHasErrors('agSimName');
        $this->tela($divida)->set('agSimName', 'Sem valores')->call('saveAgreementSimulation')->assertHasErrors(['agSimRows.0.total']);
        self::assertSame(0, DebtSimulation::query()->count());
    }

    public function test_ha_um_teto_de_simulacoes_guardadas_por_divida(): void
    {
        $divida = $this->cartao();

        for ($i = 1; $i <= DebtShow::MAX_SAVED_SIMULATIONS; $i++) {
            DebtSimulation::create(['profile_id' => $this->perfil->id, 'debt_id' => $divida->id, 'name' => "S{$i}", 'balance' => '4250.00', 'proposals' => [['label' => 'A', 'total' => '100.00', 'down' => '0.00', 'installments' => 1]]]);
        }

        $this->comProposta($this->tela($divida))->set('agSimName', 'Uma a mais')->call('saveAgreementSimulation')->assertHasErrors('agSimName');
        self::assertSame(DebtShow::MAX_SAVED_SIMULATIONS, DebtSimulation::query()->count());
    }

    public function test_abrir_uma_guardada_carrega_as_propostas_e_comparar_diz_quanto_ficou_mais_barato(): void
    {
        $divida = $this->cartao('4250.00');

        // Simulação de hoje: melhor custo 3.000 em 12 vezes, com o saldo de 4.250.
        $this->tela($divida)->set('agSimRows.0.total', '3000,00')->set('agSimRows.1.total', '3300,00')->set('agSimRows.1.installments', '12')
            ->call('simulateAgreement')->set('agSimName', 'Nov')->call('saveAgreementSimulation');
        $guardada = DebtSimulation::query()->sole();

        // Semanas depois: o saldo subiu e chega uma proposta melhor.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-01-20 09:00:00'));
        $divida->update(['current_balance' => '4600.00']);

        $tela = Livewire::test(DebtShow::class, ['debt' => $divida->fresh()])->call('openAgreementSim')->call('openSavedSimulation', $guardada->id);
        self::assertSame('3000.00', $tela->get('agSimRows.0.total'), 'as propostas antigas voltam ao formulário');
        self::assertSame($guardada->id, $tela->get('agCompareId'));

        $tela->set('agSimRows.0.total', '2800,00')->call('simulateAgreement')->call('compareWithSaved', $guardada->id)
            ->assertSee('Comparação com «Nov» (12/11/2026)')
            ->assertSee('A melhor proposta de agora custa R$ 200,00 a menos no total que a melhor daquela simulação.')
            ->assertSee('O saldo da dívida subiu R$ 350,00 desde aquela simulação.');
    }

    public function test_comparar_com_pior_proposta_diz_a_mais(): void
    {
        $divida = $this->cartao('4250.00');
        DebtSimulation::create(['profile_id' => $this->perfil->id, 'debt_id' => $divida->id, 'name' => 'Antiga', 'balance' => '4250.00',
            'proposals' => [['label' => 'À vista', 'total' => '2500.00', 'down' => '0.00', 'installments' => 1]]]);
        $id = DebtSimulation::query()->sole()->id;

        $this->tela($divida)->set('agSimRows.0.total', '2700,00')->set('agSimRows.1.total', '3300,00')->call('simulateAgreement')
            ->call('compareWithSaved', $id)
            ->assertSee('A melhor proposta de agora custa R$ 200,00 a mais no total que a melhor daquela simulação.');
    }

    public function test_excluir_remove_a_guardada_e_nao_mexe_em_outra_divida(): void
    {
        $divida = $this->cartao();
        $outra = $this->cartao('900.00', ['name' => 'Outra dívida']);
        $dela = DebtSimulation::create(['profile_id' => $this->perfil->id, 'debt_id' => $outra->id, 'name' => 'Da outra', 'balance' => '900.00', 'proposals' => [['label' => 'A', 'total' => '500.00', 'down' => '0.00', 'installments' => 1]]]);
        $minha = DebtSimulation::create(['profile_id' => $this->perfil->id, 'debt_id' => $divida->id, 'name' => 'Minha', 'balance' => '4250.00', 'proposals' => [['label' => 'A', 'total' => '500.00', 'down' => '0.00', 'installments' => 1]]]);

        // Uma simulação de OUTRA dívida não abre nem apaga por esta tela.
        $this->tela($divida)->call('openSavedSimulation', $dela->id)->assertNotFound();
        $this->tela($divida)->call('deleteSavedSimulation', $dela->id)->assertNotFound();
        self::assertNotNull($dela->fresh());

        $this->tela($divida)->call('deleteSavedSimulation', $minha->id);
        self::assertNull(DebtSimulation::query()->find($minha->id));
    }

    public function test_simulacao_de_outro_perfil_nunca_aparece(): void
    {
        $outroPerfil = FinancialProfile::factory()->create();
        $outraDivida = Debt::withoutGlobalScopes()->create(['profile_id' => $outroPerfil->id, 'kind' => 'debt', 'status' => 'open', 'name' => 'X', 'principal' => '100.00', 'amortization' => 'fixed', 'term' => 0, 'necessity' => 'essential', 'current_balance' => '100.00']);
        DebtSimulation::withoutGlobalScopes()->create(['profile_id' => $outroPerfil->id, 'debt_id' => $outraDivida->id, 'name' => 'Alheia', 'balance' => '100.00', 'proposals' => []]);

        self::assertSame(0, DebtSimulation::query()->count());
    }

    public function test_a_simulacao_herda_a_privacidade_da_divida_e_o_conjuge_nao_a_ve(): void
    {
        $bruno = User::factory()->create();
        $membroBruno = ProfileMember::factory()->secondary()->create(['profile_id' => $this->perfil->id, 'user_id' => $bruno->id, 'name' => 'Bruno']);
        $divida = $this->cartao('4250.00', ['member_id' => $this->membro->id, 'is_private' => true]);

        $this->comProposta($this->tela($divida))->call('simulateAgreement')->set('agSimName', 'Privada')->call('saveAgreementSimulation');
        $sim = DebtSimulation::query()->sole();
        self::assertTrue($sim->is_private);
        self::assertSame($this->membro->id, $sim->member_id);

        $this->actingAs($bruno);
        app(ProfileContext::class)->set($this->perfil, $membroBruno);
        self::assertSame(0, DebtSimulation::query()->count(), 'o cônjuge não vê a simulação de uma dívida oculta');
    }

    public function test_ocultar_a_divida_depois_oculta_tambem_as_simulacoes_ja_guardadas(): void
    {
        $divida = $this->cartao();
        $this->comProposta($this->tela($divida))->call('simulateAgreement')->set('agSimName', 'Antes')->call('saveAgreementSimulation');
        self::assertFalse(DebtSimulation::query()->sole()->is_private);

        app(DebtService::class)->update($divida->fresh(), ['member_id' => $this->membro->id, 'is_private' => true]);

        $sim = DebtSimulation::query()->sole();
        self::assertTrue($sim->is_private);
        self::assertSame($this->membro->id, $sim->member_id);
    }

    public function test_apagar_a_divida_leva_as_simulacoes_junto(): void
    {
        $divida = $this->cartao();
        DebtSimulation::create(['profile_id' => $this->perfil->id, 'debt_id' => $divida->id, 'name' => 'S', 'balance' => '4250.00', 'proposals' => []]);

        app(DebtService::class)->delete($divida);

        self::assertSame(0, DB::table('debt_simulations')->count());
    }
}
