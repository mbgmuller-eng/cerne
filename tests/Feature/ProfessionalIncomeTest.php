<?php

namespace Tests\Feature;

use App\Enums\BillingPeriod;
use App\Enums\ConsultantClientStatus;
use App\Enums\IncomeSource;
use App\Enums\IncomeStatus;
use App\Enums\MemberRole;
use App\Livewire\Consultant\ProfessionalIncome;
use App\Models\ConsultantClient;
use App\Models\FeeAgreement;
use App\Models\FinancialProfile;
use App\Models\IncomeEntry;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\FeeForecastService;
use App\Services\ProfessionalIncomeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Recebimentos do profissional: o livro, os contratos de honorário que geram os previstos e, sobretudo, o sigilo: o que um
 * profissional ganha não aparece para o cliente nem para outro profissional.
 */
class ProfessionalIncomeTest extends TestCase
{
    use RefreshDatabase;

    private User $consultor;

    private FinancialProfile $perfil;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-10 09:00:00'));

        $this->consultor = User::factory()->consultant()->create();
        $this->perfil = $this->clienteVinculado($this->consultor, 'Helen Ferry');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function clienteVinculado(User $profissional, string $nome): FinancialProfile
    {
        $cliente = User::factory()->create(['name' => $nome]);
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $cliente->id]);
        ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $cliente->id, 'role' => MemberRole::Primary, 'name' => $nome]);
        ConsultantClient::factory()->create(['consultant_id' => $profissional->id, 'client_id' => $cliente->id, 'status' => ConsultantClientStatus::Active, 'accepted_at' => now()]);

        return $perfil;
    }

    private function contrato(BillingPeriod $periodo, string $inicio = '2026-11-05', array $extra = []): FeeAgreement
    {
        $contrato = FeeAgreement::factory()->create(array_merge([
            'professional_id' => $this->consultor->id, 'profile_id' => $this->perfil->id, 'period' => $periodo, 'starts_on' => $inicio, 'amount' => '500.00',
        ], $extra));
        app(FeeForecastService::class)->extend($contrato);

        return $contrato;
    }

    // --- Acesso e sigilo -----------------------------------------------------------------------------------------

    public function test_consultor_e_corretor_abrem_a_tela_e_cliente_nao(): void
    {
        $this->actingAs($this->consultor)->get(route('consultant.income'))->assertOk()->assertSee('Recebimentos');
        $this->actingAs(User::factory()->broker()->create())->get(route('consultant.income'))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('consultant.income'))->assertForbidden();
    }

    public function test_corretor_nao_ve_a_aba_de_contratos_de_honorario(): void
    {
        $corretor = User::factory()->broker()->create();
        $this->actingAs($corretor);

        Livewire::test(ProfessionalIncome::class)->assertDontSee('Contratos de honorário')
            ->call('setAba', 'contratos')->assertSet('aba', 'recebimentos');
        Livewire::withQueryParams(['aba' => 'contratos'])->test(ProfessionalIncome::class)->assertSet('aba', 'recebimentos');
    }

    public function test_um_profissional_nunca_ve_nem_mexe_no_recebimento_de_outro(): void
    {
        $outro = User::factory()->broker()->create();
        $dele = IncomeEntry::factory()->create(['professional_id' => $outro->id, 'description' => 'Comissão sigilosa do outro', 'amount' => '777.00', 'due_on' => '2026-11-08']);

        $this->actingAs($this->consultor);

        Livewire::test(ProfessionalIncome::class)->assertDontSee('Comissão sigilosa do outro')->assertDontSee('777,00');

        $service = app(ProfessionalIncomeService::class);
        self::assertSame('0.00', $service->monthSummary($this->consultor, 2026, 11)['previsto']);

        foreach (['markReceived' => [$dele->id, '777', '2026-11-09'], 'reopen' => [$dele->id], 'cancel' => [$dele->id]] as $metodo => $args) {
            try {
                $service->{$metodo}($this->consultor, ...$args);
                self::fail("$metodo mexeu no recebimento de outro profissional");
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                // esperado
            }
        }
        self::assertFalse($service->delete($this->consultor, $dele->id));
        self::assertSame(IncomeStatus::Expected, $dele->fresh()->status);
    }

    public function test_o_cliente_do_contrato_precisa_ser_um_vinculo_ativo_do_profissional(): void
    {
        $outro = User::factory()->consultant()->create();
        $perfilDeOutro = $this->clienteVinculado($outro, 'Cliente alheio');

        $this->actingAs($this->consultor);

        Livewire::test(ProfessionalIncome::class)
            ->call('openAgreementForm')
            ->set('agreementProfileId', $perfilDeOutro->id)->set('agreementAmount', '300')->set('agreementStartsOn', '2026-11-05')
            ->call('saveAgreement')->assertHasErrors('agreementProfileId');

        self::assertSame(0, FeeAgreement::query()->count());
    }

    // --- Contratos geram previstos -----------------------------------------------------------------------------------

    public function test_contrato_mensal_preve_os_proximos_12_meses_a_partir_do_mes_atual(): void
    {
        $contrato = $this->contrato(BillingPeriod::Monthly, '2026-05-05');

        $datas = IncomeEntry::query()->where('fee_agreement_id', $contrato->id)->orderBy('due_on')->pluck('due_on')->map->toDateString()->all();

        // Começou em maio, mas o passado não nasce como atrasado: a previsão começa em novembro.
        self::assertSame('2026-11-05', $datas[0]);
        self::assertSame('2027-11-05', end($datas));
        self::assertCount(13, $datas);
    }

    public function test_periodicidades_trimestral_semestral_e_anual_espacam_os_vencimentos(): void
    {
        $tri = $this->contrato(BillingPeriod::Quarterly, '2026-11-15');
        $sem = $this->contrato(BillingPeriod::Semiannual, '2026-11-15');
        $anual = $this->contrato(BillingPeriod::Annual, '2026-11-15');

        $datas = fn (FeeAgreement $c) => IncomeEntry::query()->where('fee_agreement_id', $c->id)->orderBy('due_on')->pluck('due_on')->map->toDateString()->all();

        self::assertSame(['2026-11-15', '2027-02-15', '2027-05-15', '2027-08-15', '2027-11-15'], $datas($tri));
        self::assertSame(['2026-11-15', '2027-05-15', '2027-11-15'], $datas($sem));
        self::assertSame(['2026-11-15', '2027-11-15'], $datas($anual));
    }

    public function test_dia_31_nao_pula_mes_curto(): void
    {
        $contrato = $this->contrato(BillingPeriod::Monthly, '2026-10-31');

        $datas = IncomeEntry::query()->where('fee_agreement_id', $contrato->id)->orderBy('due_on')->pluck('due_on')->map->toDateString()->all();

        self::assertContains('2026-11-30', $datas);
        self::assertContains('2027-02-28', $datas);
        self::assertContains('2026-12-31', $datas);
    }

    public function test_rodar_a_previsao_de_novo_nao_duplica(): void
    {
        $contrato = $this->contrato(BillingPeriod::Monthly);
        $antes = IncomeEntry::query()->count();

        self::assertSame(0, app(FeeForecastService::class)->extend($contrato));
        app(FeeForecastService::class)->runDailyMaintenance();

        self::assertSame($antes, IncomeEntry::query()->count());
    }

    public function test_rotina_diaria_estende_o_horizonte_conforme_o_tempo_passa(): void
    {
        $contrato = $this->contrato(BillingPeriod::Monthly);
        $antes = IncomeEntry::query()->count();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-02 04:05:00'));
        $resultado = app(FeeForecastService::class)->runDailyMaintenance();

        self::assertSame(1, $resultado['previstos_criados']);
        self::assertSame($antes + 1, IncomeEntry::query()->where('fee_agreement_id', $contrato->id)->count());
    }

    public function test_contrato_com_termino_nao_preve_depois_dele_e_inativo_nao_preve_nada(): void
    {
        $curto = $this->contrato(BillingPeriod::Monthly, '2026-11-05', ['ends_on' => '2027-01-10']);
        self::assertSame(3, IncomeEntry::query()->where('fee_agreement_id', $curto->id)->count());

        $pausado = FeeAgreement::factory()->create(['professional_id' => $this->consultor->id, 'profile_id' => $this->perfil->id, 'is_active' => false]);
        self::assertSame(0, app(FeeForecastService::class)->extend($pausado));
    }

    public function test_editar_o_contrato_refaz_so_o_que_ainda_e_previsao(): void
    {
        $contrato = $this->contrato(BillingPeriod::Monthly);
        $service = app(ProfessionalIncomeService::class);
        $primeiro = IncomeEntry::query()->where('fee_agreement_id', $contrato->id)->orderBy('due_on')->first();
        $service->markReceived($this->consultor, $primeiro->id, '500', '2026-11-06');

        $service->saveAgreement($this->consultor, $contrato->id, [
            'profile_id' => $this->perfil->id, 'amount' => '650', 'period' => 'monthly', 'starts_on' => '2026-11-05',
        ]);

        // O recebido fica com o valor de quando foi pago; os previstos seguintes ganham o novo.
        self::assertSame('500.00', $primeiro->fresh()->amount);
        self::assertSame(IncomeStatus::Received, $primeiro->fresh()->status);
        self::assertSame('650.00', IncomeEntry::query()->where('fee_agreement_id', $contrato->id)->where('status', 'expected')->value('amount'));
    }

    public function test_previsto_cancelado_nao_ressuscita_na_rotina(): void
    {
        $contrato = $this->contrato(BillingPeriod::Monthly);
        $entrada = IncomeEntry::query()->where('fee_agreement_id', $contrato->id)->orderBy('due_on')->first();
        app(ProfessionalIncomeService::class)->cancel($this->consultor, $entrada->id);

        app(FeeForecastService::class)->runDailyMaintenance();

        self::assertSame(IncomeStatus::Cancelled, $entrada->fresh()->status);
        self::assertSame(1, IncomeEntry::query()->where('fee_agreement_id', $contrato->id)->where('due_on', $entrada->due_on)->count());
    }

    public function test_excluir_o_contrato_tira_a_previsao_e_preserva_o_recebido(): void
    {
        $contrato = $this->contrato(BillingPeriod::Monthly);
        $service = app(ProfessionalIncomeService::class);
        $primeiro = IncomeEntry::query()->where('fee_agreement_id', $contrato->id)->orderBy('due_on')->first();
        $service->markReceived($this->consultor, $primeiro->id, '500', '2026-11-06');

        $service->deleteAgreement($this->consultor, $contrato->id);

        self::assertSame(0, FeeAgreement::query()->count());
        self::assertSame(1, IncomeEntry::query()->count());
        self::assertNull($primeiro->fresh()->fee_agreement_id);
    }

    // --- Livro e totais ----------------------------------------------------------------------------------------------

    public function test_resumo_do_mes_soma_previsto_recebido_e_atrasado_em_sql(): void
    {
        $outraOrigem = IncomeSource::InsuranceCommission;
        IncomeEntry::factory()->create(['professional_id' => $this->consultor->id, 'profile_id' => $this->perfil->id, 'source' => IncomeSource::FixedFee, 'due_on' => '2026-11-05', 'amount' => '500.00'])
            ->forceFill(['status' => IncomeStatus::Received, 'received_amount' => '480.00', 'received_on' => '2026-11-06'])->save();
        IncomeEntry::factory()->create(['professional_id' => $this->consultor->id, 'source' => $outraOrigem, 'due_on' => '2026-11-03', 'amount' => '200.10']);
        IncomeEntry::factory()->create(['professional_id' => $this->consultor->id, 'source' => $outraOrigem, 'due_on' => '2026-11-25', 'amount' => '100.05']);
        IncomeEntry::factory()->create(['professional_id' => $this->consultor->id, 'status' => IncomeStatus::Cancelled, 'due_on' => '2026-11-12', 'amount' => '999.00']);
        IncomeEntry::factory()->create(['professional_id' => $this->consultor->id, 'due_on' => '2026-12-01', 'amount' => '50.00']);

        $r = app(ProfessionalIncomeService::class)->monthSummary($this->consultor, 2026, 11);

        self::assertSame('800.15', $r['previsto']);
        self::assertSame('480.00', $r['recebido']);
        self::assertSame('300.15', $r['a_receber']);
        // Só o previsto de 03/11 já passou de "hoje" (10/11).
        self::assertSame('200.10', $r['atrasado']);
        self::assertSame(IncomeSource::FixedFee, $r['por_origem'][0]['origem']);
        self::assertCount(2, $r['por_origem']);
    }

    public function test_evolucao_devolve_12_meses_zerando_os_vazios(): void
    {
        IncomeEntry::factory()->received('300.00', '2026-09-10')->create(['professional_id' => $this->consultor->id, 'due_on' => '2026-09-10', 'amount' => '300.00']);

        $serie = app(ProfessionalIncomeService::class)->evolution($this->consultor, 2026, 11);

        self::assertCount(12, $serie);
        self::assertSame('300.00', $serie[9]['recebido']);
        self::assertSame('0.00', $serie[11]['recebido']);
    }

    public function test_lancamento_manual_dar_baixa_voltar_e_cancelar(): void
    {
        $this->actingAs($this->consultor);

        $c = Livewire::test(ProfessionalIncome::class)
            ->call('openEntryForm')
            ->set('entrySource', 'insurance_commission')->set('entryProfileId', $this->perfil->id)->set('entryDescription', 'Apólice 1234')
            ->set('entryDueOn', '2026-11-20')->set('entryAmount', '123.45')
            ->call('saveEntry')->assertHasNoErrors();

        $entrada = IncomeEntry::query()->sole();
        self::assertSame('123.45', $entrada->amount);
        self::assertSame(2026, $entrada->year);
        self::assertSame(11, $entrada->month);

        $c->call('openReceive', $entrada->id)->assertSet('receiveAmount', '123.45')
            ->set('receiveAmount', '120')->set('receiveOn', '2026-11-22')->call('confirmReceive')->assertHasNoErrors();
        self::assertSame(IncomeStatus::Received, $entrada->fresh()->status);
        self::assertSame('120.00', $entrada->fresh()->received_amount);

        $c->call('reopenEntry', $entrada->id);
        self::assertSame(IncomeStatus::Expected, $entrada->fresh()->status);
        self::assertNull($entrada->fresh()->received_amount);

        $c->call('cancelEntry', $entrada->id);
        self::assertSame(IncomeStatus::Cancelled, $entrada->fresh()->status);

        $c->call('deleteEntry', $entrada->id);
        self::assertSame(0, IncomeEntry::query()->count());
    }

    public function test_lancamento_gerado_por_contrato_nao_se_edita_nem_se_exclui_so_se_cancela(): void
    {
        $contrato = $this->contrato(BillingPeriod::Monthly);
        $entrada = IncomeEntry::query()->where('fee_agreement_id', $contrato->id)->first();
        $this->actingAs($this->consultor);

        self::assertFalse(app(ProfessionalIncomeService::class)->delete($this->consultor, $entrada->id));
        self::assertSame(1, IncomeEntry::query()->whereKey($entrada->id)->count());

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(ProfessionalIncomeService::class)->saveEntry($this->consultor, $entrada->id, ['source' => 'other', 'due_on' => '2026-11-01', 'amount' => '1']);
    }

    public function test_tela_mostra_lancamentos_do_mes_e_navega_entre_meses(): void
    {
        IncomeEntry::factory()->create(['professional_id' => $this->consultor->id, 'profile_id' => $this->perfil->id, 'due_on' => '2026-11-05', 'amount' => '500.00']);
        IncomeEntry::factory()->create(['professional_id' => $this->consultor->id, 'description' => 'Só em dezembro', 'due_on' => '2026-12-05', 'amount' => '321.00']);
        $this->actingAs($this->consultor);

        Livewire::test(ProfessionalIncome::class)
            ->assertSee('Helen Ferry')->assertSee('500,00')->assertDontSee('Só em dezembro')
            ->call('nextMonth')->assertSee('Só em dezembro')->assertSet('mes', 12)
            ->call('previousMonth')->call('previousMonth')->assertSet('mes', 10)
            ->call('thisMonth')->assertSet('mes', 11)->assertSet('ano', 2026);
    }
}
