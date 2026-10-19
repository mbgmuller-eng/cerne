<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\ConsortiumType;
use App\Enums\DebtInstallmentStatus;
use App\Enums\DebtKind;
use App\Enums\DebtStatus;
use App\Enums\IncomeSource;
use App\Enums\IncomeStatus;
use App\Enums\MemberRole;
use App\Livewire\Consultant\ConsortiumCommissions;
use App\Models\CommissionRule;
use App\Models\ConsultantClient;
use App\Models\Debt;
use App\Models\DebtCommission;
use App\Models\DebtInstallment;
use App\Models\FinancialProfile;
use App\Models\IncomeEntry;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\ConsortiumCommissionService;
use App\Services\ProfessionalIncomeService;
use App\Support\ConsortiumSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Comissão de consórcio: o texto das parcelas, a divisão exata dos centavos, as regras por administradora, quem vendeu e a
 * previsão no livro de recebimentos.
 */
class ConsortiumCommissionTest extends TestCase
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

    /** Consórcio de R$ 50.000 com parcelas mensais de 06/2026 em diante (a de número 6 vence em 11/2026, a 8 em 01/2027). */
    private function consorcio(array $campos = [], ?FinancialProfile $perfil = null): Debt
    {
        $debt = Debt::withoutGlobalScopes()->create(array_merge([
            'profile_id' => ($perfil ?? $this->perfil)->id, 'kind' => DebtKind::Consortium, 'status' => DebtStatus::Active, 'name' => 'Consórcio imóvel',
            'creditor' => 'Porto Seguro Consórcio', 'consortium_type' => ConsortiumType::Property, 'credit_amount' => '50000.00', 'term' => 60,
            'first_due_date' => '2026-06-15', 'installment_amount' => '900.00',
        ], $campos));

        foreach (range(1, 20) as $n) {
            DebtInstallment::withoutGlobalScopes()->create([
                'profile_id' => $debt->profile_id, 'debt_id' => $debt->id, 'number' => $n,
                'due_date' => CarbonImmutable::parse('2026-06-15')->addMonthsNoOverflow($n - 1)->toDateString(), 'amount' => '900.00', 'status' => DebtInstallmentStatus::Open,
            ]);
        }

        return $debt;
    }

    private function regra(string $percentual = '3.5', string $parcelas = '2, 4, 6', array $extra = []): CommissionRule
    {
        return $this->service()->saveRule($this->consultor, null, array_merge([
            'counterparty' => 'Porto Seguro Consórcio', 'credit_percent' => $percentual, 'installment_schedule' => $parcelas, 'payout_delay_days' => 0,
        ], $extra));
    }

    private function service(): ConsortiumCommissionService
    {
        return app(ConsortiumCommissionService::class);
    }

    // --- Texto das parcelas -------------------------------------------------------------------------------------------

    public function test_cronograma_em_partes_iguais_ou_em_percentuais(): void
    {
        self::assertSame(['numbers' => [2, 4, 6], 'shares' => null], ConsortiumSchedule::parse('6, 2; 4'));
        self::assertSame(['numbers' => [2, 4, 6], 'shares' => ['40', '30', '30']], ConsortiumSchedule::parse('2:40, 4:30, 6:30'));
        self::assertSame('2, 4, 6', ConsortiumSchedule::normalize('6,2 4'));
        self::assertSame('2:40, 4:30, 6:30', ConsortiumSchedule::normalize('6:30 2:40 4:30'));
    }

    public function test_cronograma_invalido_e_recusado(): void
    {
        foreach (['', 'abc', '0', '2, 2', '2:40, 4', '2:50, 4:30', '2:60, 4:30', '2:0, 4:100', '1000', '2-4'] as $texto) {
            self::assertNull(ConsortiumSchedule::parse($texto), "\"{$texto}\" deveria ser inválido");
        }
    }

    public function test_a_divisao_soma_exatamente_o_total_e_os_centavos_ficam_na_ultima_parcela(): void
    {
        $partes = ConsortiumSchedule::split('1750.00', '2, 4, 6');

        self::assertSame(['2' => '583.33', '4' => '583.33', '6' => '583.34'], array_map('strval', $partes));
        self::assertSame('1750.00', array_reduce($partes, fn ($c, $v) => bcadd($c, $v, 2), '0.00'));

        $custom = ConsortiumSchedule::split('1000.01', '1:33.333, 2:33.333, 3:33.334');
        self::assertSame('1000.01', array_reduce($custom, fn ($c, $v) => bcadd($c, $v, 2), '0.00'));
    }

    // --- Previsão -----------------------------------------------------------------------------------------------------

    public function test_vendeu_comissao_da_regra_dividida_nas_parcelas_combinadas(): void
    {
        $this->regra('3.5', '2, 4, 6');
        $debt = $this->consorcio();
        $this->service()->includeDebt($this->consultor, $debt->id, 'sold');

        $entradas = IncomeEntry::query()->orderBy('due_on')->get();

        // 3,5% de 50.000 = 1.750; parcelas 2 (07/2026) e 4 (09/2026) já passaram do mês atual; só a 6 (11/2026) entra.
        self::assertCount(1, $entradas);
        self::assertSame('2026-11-15', $entradas[0]->due_on->toDateString());
        self::assertSame('583.34', $entradas[0]->amount);
        self::assertSame(IncomeSource::ConsortiumCommission, $entradas[0]->source);
        self::assertSame($this->perfil->id, $entradas[0]->profile_id);
        self::assertSame("consortium:{$debt->id}:6", $entradas[0]->origin_key);
        self::assertTrue($entradas[0]->isGenerated());
    }

    public function test_cronograma_no_futuro_gera_todas_as_partes(): void
    {
        $this->regra('3.5', '8, 10, 12');
        $this->service()->includeDebt($this->consultor, $this->consorcio()->id, 'sold');

        $r = IncomeEntry::query()->orderBy('due_on')->get();

        self::assertSame(['2027-01-15', '2027-03-15', '2027-05-15'], $r->map(fn ($e) => $e->due_on->toDateString())->all());
        self::assertSame('1750.00', $r->reduce(fn ($c, $e) => bcadd($c, $e->amount, 2), '0.00'));
    }

    public function test_prazo_de_pagamento_desloca_a_data(): void
    {
        $this->regra('2', '6', ['payout_delay_days' => 20]);
        $this->service()->includeDebt($this->consultor, $this->consorcio()->id, 'sold');

        self::assertSame('2026-12-05', IncomeEntry::query()->sole()->due_on->toDateString());
        self::assertSame('1000.00', IncomeEntry::query()->sole()->amount);
    }

    public function test_terceiro_vendeu_so_vale_o_percentual_informado_e_sem_ele_nao_gera_nada(): void
    {
        $this->regra('3.5', '8, 10');
        $debt = $this->consorcio();

        $vinculo = $this->service()->includeDebt($this->consultor, $debt->id, 'third_party');
        self::assertSame(0, IncomeEntry::query()->count(), 'sem percentual combinado, a regra da administradora não vale para terceiro');

        $this->service()->updateLink($this->consultor, $vinculo->id, 'third_party', '1', null);

        // 1% de 50.000 = 500, em 8 e 10 (partes iguais: 250 e 250), usando o cronograma da regra.
        self::assertSame(['250.00', '250.00'], IncomeEntry::query()->orderBy('due_on')->pluck('amount')->all());
    }

    public function test_vinculo_pode_ter_percentual_e_parcelas_proprios(): void
    {
        $this->regra('3.5', '8, 10');
        $vinculo = $this->service()->includeDebt($this->consultor, $this->consorcio()->id, 'sold', '2', '9:50, 11:50');

        self::assertSame(['500.00', '500.00'], IncomeEntry::query()->orderBy('due_on')->pluck('amount')->all());
        self::assertSame('9:50, 11:50', $vinculo->fresh()->schedule_override);
    }

    public function test_sem_regra_para_a_administradora_nao_gera_previsao(): void
    {
        $this->service()->includeDebt($this->consultor, $this->consorcio(['creditor' => 'Outra Administradora'])->id, 'sold');

        self::assertSame(0, IncomeEntry::query()->count());
        self::assertNull($this->service()->linkRows($this->consultor)->first()['percentual']);
    }

    public function test_nome_da_administradora_nao_diferencia_acento_nem_caixa(): void
    {
        $this->regra('3.5', '8');
        $this->service()->includeDebt($this->consultor, $this->consorcio(['creditor' => 'PORTO SEGURO CONSÓRCIO'])->id, 'sold');

        self::assertSame(1, IncomeEntry::query()->count());
    }

    public function test_reconciliar_nao_duplica_e_a_carta_reajustada_corrige_o_previsto(): void
    {
        $this->regra('3.5', '8');
        $debt = $this->consorcio();
        $this->service()->includeDebt($this->consultor, $debt->id, 'sold');
        self::assertSame(['criados' => 0, 'atualizados' => 0, 'removidos' => 0], $this->service()->reconcile($this->consultor));

        Debt::withoutGlobalScopes()->whereKey($debt->id)->update(['credit_amount' => '60000.00']);
        $r = $this->service()->reconcile($this->consultor);

        self::assertSame(1, $r['atualizados']);
        self::assertSame('2100.00', IncomeEntry::query()->sole()->amount);
    }

    public function test_recebido_e_cancelado_ficam_e_o_cancelado_nao_ressuscita(): void
    {
        $this->regra('3.5', '8, 10');
        $this->service()->includeDebt($this->consultor, $this->consorcio()->id, 'sold');
        [$a, $b] = IncomeEntry::query()->orderBy('due_on')->get()->all();
        app(ProfessionalIncomeService::class)->markReceived($this->consultor, $a->id, '870', '2027-01-20');
        app(ProfessionalIncomeService::class)->cancel($this->consultor, $b->id);

        $this->service()->deleteRule($this->consultor, CommissionRule::query()->where('kind', 'consortium')->value('id'));

        self::assertSame(IncomeStatus::Received, $a->fresh()->status);
        self::assertSame('870.00', $a->fresh()->received_amount);
        self::assertSame(IncomeStatus::Cancelled, $b->fresh()->status);

        $this->regra('3.5', '8, 10');
        self::assertSame(2, IncomeEntry::query()->count(), 'o recebido e o cancelado continuam, sem uma terceira linha');
    }

    public function test_pausar_regra_ou_vinculo_tira_a_previsao(): void
    {
        $regra = $this->regra('3.5', '8, 10');
        $vinculo = $this->service()->includeDebt($this->consultor, $this->consorcio()->id, 'sold');
        self::assertSame(2, IncomeEntry::query()->count());

        $this->service()->setRuleActive($this->consultor, $regra->id, false);
        self::assertSame(0, IncomeEntry::query()->count());

        $this->service()->setRuleActive($this->consultor, $regra->id, true);
        self::assertSame(2, IncomeEntry::query()->count());

        $this->service()->setLinkActive($this->consultor, $vinculo->id, false);
        self::assertSame(0, IncomeEntry::query()->count());
    }

    public function test_consorcio_encerrado_ou_de_cliente_sem_vinculo_ativo_nao_gera(): void
    {
        $this->regra('3.5', '8');
        $debt = $this->consorcio();
        $this->service()->includeDebt($this->consultor, $debt->id, 'sold');
        self::assertSame(1, IncomeEntry::query()->count());

        Debt::withoutGlobalScopes()->whereKey($debt->id)->update(['status' => DebtStatus::PaidOff]);
        $this->service()->reconcile($this->consultor);
        self::assertSame(0, IncomeEntry::query()->count());

        Debt::withoutGlobalScopes()->whereKey($debt->id)->update(['status' => DebtStatus::Active]);
        ConsultantClient::query()->where('consultant_id', $this->consultor->id)->update(['status' => ConsultantClientStatus::Inactive]);
        $this->service()->reconcile($this->consultor);
        self::assertSame(0, IncomeEntry::query()->count(), 'vínculo encerrado: o Cerne não lê mais a carta do cliente');
    }

    public function test_rotina_diaria_refaz_a_previsao_de_cada_consultor(): void
    {
        $this->regra('3.5', '8');
        $this->service()->includeDebt($this->consultor, $this->consorcio()->id, 'sold');
        IncomeEntry::query()->delete();

        $r = $this->service()->runDailyMaintenance();

        self::assertSame(1, $r['profissionais']);
        self::assertSame(1, $r['criados']);
    }

    // --- Sigilo e escopo ----------------------------------------------------------------------------------------------

    public function test_so_inclui_consorcio_de_cliente_ativo_dele_e_so_em_andamento(): void
    {
        $perfilAlheio = $this->clienteVinculado(User::factory()->consultant()->create(), 'Cliente alheio');
        $meu = $this->consorcio();
        $alheio = $this->consorcio([], $perfilAlheio);
        $quitado = $this->consorcio(['status' => DebtStatus::PaidOff, 'creditor' => 'Quitada']);

        self::assertSame([$meu->id], array_keys($this->service()->includableDebts($this->consultor)));

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->service()->includeDebt($this->consultor, $alheio->id, 'sold');
    }

    public function test_regra_e_vinculo_de_um_consultor_nunca_aparecem_nem_se_mexem_por_outro(): void
    {
        $regra = $this->regra();
        $vinculo = $this->service()->includeDebt($this->consultor, $this->consorcio()->id, 'sold');
        $outro = User::factory()->consultant()->create();

        self::assertCount(0, $this->service()->rules($outro));
        self::assertCount(0, $this->service()->linkRows($outro));

        foreach ([fn () => $this->service()->deleteRule($outro, $regra->id), fn () => $this->service()->setRuleActive($outro, $regra->id, false),
            fn () => $this->service()->deleteLink($outro, $vinculo->id), fn () => $this->service()->setLinkActive($outro, $vinculo->id, false),
            fn () => $this->service()->updateLink($outro, $vinculo->id, 'sold', '9', null)] as $acao) {
            try {
                $acao();
                self::fail('mexeu no dado de outro consultor');
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                // esperado
            }
        }

        self::assertSame(1, DebtCommission::query()->count());
    }

    // --- Tela --------------------------------------------------------------------------------------------------------------

    public function test_corretor_e_cliente_nao_abrem_a_comissao_de_consorcio(): void
    {
        foreach ([User::factory()->broker()->create(), User::factory()->create()] as $usuario) {
            $this->actingAs($usuario);
            Livewire::test(ConsortiumCommissions::class)->assertForbidden();
        }
    }

    public function test_criar_regra_pela_tela_valida_o_cronograma_e_nao_duplica(): void
    {
        $this->actingAs($this->consultor);

        Livewire::test(ConsortiumCommissions::class)->call('openRuleForm')
            ->set('ruleCounterparty', 'Porto Seguro Consórcio')->set('ruleCreditPercent', '3.5')->set('ruleSchedule', '2:50, 4')->call('saveRule')
            ->assertHasErrors('ruleSchedule');

        Livewire::test(ConsortiumCommissions::class)->call('openRuleForm')
            ->set('ruleCounterparty', 'Porto Seguro Consórcio')->set('ruleCreditPercent', '3.5')->set('ruleSchedule', '6,4,2')->call('saveRule')
            ->assertHasNoErrors()->assertSee('Porto Seguro Consórcio')->assertSee('parcelas 2, 4, 6');

        Livewire::test(ConsortiumCommissions::class)->call('openRuleForm')
            ->set('ruleCounterparty', 'porto seguro consorcio')->set('ruleCreditPercent', '1')->set('ruleSchedule', '2')->call('saveRule')
            ->assertHasErrors('ruleCounterparty');

        self::assertSame(1, CommissionRule::query()->where('kind', 'consortium')->count());
    }

    public function test_incluir_consorcio_pela_tela_exige_percentual_para_terceiro(): void
    {
        $this->regra('3.5', '8');
        $debt = $this->consorcio();
        $this->actingAs($this->consultor);

        Livewire::test(ConsortiumCommissions::class)->call('openLinkForm')
            ->set('linkDebtId', $debt->id)->set('linkRole', 'third_party')->set('linkPercent', '')->call('saveLink')->assertHasErrors('linkPercent');

        Livewire::test(ConsortiumCommissions::class)->call('openLinkForm')
            ->set('linkDebtId', $debt->id)->set('linkRole', 'sold')->call('saveLink')->assertHasNoErrors()->assertSee('Helen Ferry');

        self::assertSame(1, IncomeEntry::query()->count());
    }
}
