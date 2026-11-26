<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Enums\ConsultantClientStatus;
use App\Enums\GymGoal;
use App\Enums\GymMeasureType;
use App\Enums\GymMuscleGroup;
use App\Enums\MemberRole;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Livewire\Activities\ActivityPlanPage;
use App\Livewire\Activities\ActivityReadinessPage;
use App\Livewire\Activities\ActivityRunning;
use App\Livewire\Admin\AdminRules;
use App\Livewire\Health\HealthCardIndex;
use App\Livewire\Health\LimitationPanel;
use App\Livewire\Health\MeasurementsPanel;
use App\Livewire\Rules\RuleReviewPage;
use App\Models\ActivityPlan;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\HealthAppointment;
use App\Models\HealthExamReport;
use App\Models\GymPlanAdjustment;
use App\Models\GymWorkout;
use App\Models\GymWorkoutExercise;
use App\Models\HealthCondition;
use App\Models\HealthFollowup;
use App\Models\HealthMeasurement;
use App\Models\PhysicalLimitation;
use App\Models\ProfileMember;
use App\Models\RuleReview;
use App\Models\RuleReviewer;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Activities\ActivityService;
use App\Services\Activities\ActivityWeekService;
use App\Services\Activities\LimitationService;
use App\Services\Activities\ProgramUpdateService;
use App\Services\Activities\ReadinessService;
use App\Services\Activities\RunningService;
use App\Services\Activities\WeekPlannerService;
use App\Services\GymPlanService;
use App\Services\HealthCardService;
use App\Services\HealthFollowupService;
use App\Services\HealthMeasurementService;
use App\Services\RuleReviewService;
use App\Support\Activities\ReadinessQuestions;
use App\Support\Health\BodyMass;
use App\Support\ProfileContext;
use App\Support\Rules\RuleBook;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ficha de Saúde ampliada (medidas, meta, problemas com situação e origem, limitação do passado), a revisão das regras por um profissional, a
 * prontidão, o planejador da semana e o "Atualizar meu programa".
 */
class HealthRecordPlannerTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private User $joao;

    private FinancialProfile $perfil;

    private ProfileMember $membroAna;

    private ProfileMember $membroJoao;

    protected function setUp(): void
    {
        parent::setUp();

        // Quinta-feira: a semana vai de 23/11 (segunda) a 29/11.
        $this->travelTo(CarbonImmutable::parse('2026-11-26 09:00'));

        $this->ana = User::factory()->create(['name' => 'Ana Teste']);
        $this->joao = User::factory()->create(['name' => 'João Teste']);
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->ana->id]);
        $this->membroAna = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->ana->id, 'name' => 'Ana Teste', 'role' => MemberRole::Primary, 'birthdate' => '1990-03-01']);
        $this->membroJoao = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->joao->id, 'name' => 'João Teste', 'role' => MemberRole::Secondary]);

        Subscription::create([
            'user_id' => $this->ana->id, 'kind' => SubscriptionKind::Direct, 'bundle' => SubscriptionBundle::Completo, 'status' => SubscriptionStatus::Active,
            'current_period_ends_at' => now()->addMonth(), 'started_at' => now(),
        ]);

        $this->entrarComoAna();
    }

    // --- Medidas --------------------------------------------------------------------------------

    public function test_medida_calcula_imc_e_a_faixa_pela_altura_e_fica_cifrada(): void
    {
        $this->medir(88.5, 175);

        $atual = app(HealthMeasurementService::class)->current(app(HealthMeasurementService::class)->history($this->membroAna->id), $this->membroAna);
        $this->assertSame(28.9, $atual['bmi']);
        $this->assertSame('sobrepeso', $atual['category']);
        $this->assertSame([56.7, 76.3], $atual['range']);

        $crua = DB::table('health_measurements')->first();
        $this->assertNotSame('88.5', $crua->weight_kg);
        $this->assertStringNotContainsString('88.5', $crua->weight_kg);

        Livewire::test(MeasurementsPanel::class, ['memberId' => $this->membroAna->id])
            ->assertSee('Pela tabela do IMC, o peso na faixa considerada saudável para a sua altura fica entre 56,7 kg e 76,3 kg.')
            ->assertSee('Regra ainda não revisada');
    }

    public function test_faixa_muda_aos_60_e_nao_classifica_antes_dos_18(): void
    {
        $this->assertSame('dentro da faixa', BodyMass::category(26.0, 65));
        $this->assertSame('sobrepeso', BodyMass::category(26.0, 40));
        $this->assertFalse(BodyMass::applies(16));
    }

    public function test_medidas_so_aparecem_para_o_conjuge_quando_a_pessoa_compartilha(): void
    {
        $this->medir(80, 170);

        $this->entrarComoJoao();
        $this->assertSame(0, HealthMeasurement::query()->count());
        Livewire::test(MeasurementsPanel::class, ['memberId' => $this->membroAna->id])->assertSee('As medidas desta pessoa não são compartilhadas.')->assertDontSee('80,0');

        // O cônjuge não liga o compartilhamento pela outra pessoa.
        Livewire::test(MeasurementsPanel::class, ['memberId' => $this->membroAna->id])->set('share', true)->assertForbidden();

        $this->entrarComoAna();
        app(HealthMeasurementService::class)->setSharing($this->membroAna, true, false, false);

        $this->entrarComoJoao();
        $this->assertSame(1, HealthMeasurement::query()->count());

        // Ver não é escrever.
        $this->expectException(AuthorizationException::class);
        HealthMeasurement::query()->first()->delete();
    }

    public function test_consultor_nunca_ve_as_medidas(): void
    {
        $this->medir(80, 170);
        app(HealthMeasurementService::class)->setSharing($this->membroAna, true, false, false);
        $consultor = User::factory()->consultant()->create();
        ConsultantClient::factory()->create(['consultant_id' => $consultor->id, 'client_id' => $this->ana->id, 'status' => ConsultantClientStatus::Active]);

        $this->actingAs($consultor);
        app(ProfileContext::class)->set($this->perfil, null, asConsultant: true);

        $this->assertSame(0, HealthMeasurement::query()->count());
    }

    public function test_qr_so_leva_peso_e_limitacoes_quando_ligado_e_nao_mostra_problema_resolvido(): void
    {
        $this->medir(81.4, 172);
        $fichas = app(HealthCardService::class);
        $fichas->addCondition($this->membroAna, 'Asma', $this->membroAna);
        $fichas->addCondition($this->membroAna, 'Anemia antiga', $this->membroAna, ['status' => 'resolved']);
        app(LimitationService::class)->save($this->membroAna, ['region' => 'knee', 'status' => 'guided', 'avoid' => ['impact']], $this->membroAna);
        $ficha = $fichas->ensureEmergencyToken($this->membroAna);

        $dados = $fichas->emergencyPayload($ficha->emergency_token);
        $this->assertNull($dados['weight']);
        $this->assertEmpty($dados['limitations']);

        app(HealthMeasurementService::class)->setSharing($this->membroAna, false, true, true);

        $this->sair();
        $this->get(route('health.emergency.show', $ficha->emergency_token))->assertOk()
            ->assertSee('81,4')->assertSee('Peso informado em 26/11/2026')->assertSee('Joelho')->assertSee('Asma')->assertDontSee('Anemia antiga');
    }

    public function test_meta_de_peso_e_so_da_pessoa_e_nunca_abaixo_da_faixa(): void
    {
        $this->medir(88, 175);
        $servico = app(HealthMeasurementService::class);

        foreach ([50.0 => 'abaixo da faixa de referência', 95.0 => 'menor que o atual'] as $kg => $motivo) {
            try {
                $servico->setGoal($this->membroAna, (float) $kg);
                $this->fail('Aceitou a meta '.$kg);
            } catch (DomainException $e) {
                $this->assertStringContainsString($motivo, $e->getMessage());
            }
        }

        $servico->setGoal($this->membroAna, 79.0);
        $this->assertSame(79.0, $servico->goal($this->membroAna->id)['kg']);

        app(HealthMeasurementService::class)->setSharing($this->membroAna, true, false, false);
        $this->entrarComoJoao();
        $this->assertNull($servico->goal($this->membroAna->id));
        $this->expectException(DomainException::class);
        $servico->setGoal($this->membroAna, 80.0);
    }

    // --- Problemas e limitações -----------------------------------------------------------------

    public function test_adicionar_a_ficha_abre_o_formulario_com_a_origem_e_so_grava_ao_salvar(): void
    {
        $acompanhamento = $this->acompanhamento();

        Livewire::withQueryParams(['novo' => 'problema', 'pessoa' => $this->membroAna->id, 'origem' => 'followup:'.$acompanhamento->id])
            ->test(HealthCardIndex::class)
            ->assertSet('conditionMemberId', $this->membroAna->id)->assertSet('condOrigin', 'followup:'.$acompanhamento->id)
            ->set('condText', 'Hérnia de disco')->set('condStatus', 'controlled')->set('condCare', true)->set('condSince', '2024')
            ->call('saveCondition')->assertHasNoErrors()->assertSee('Hérnia de disco');

        $problema = HealthCondition::query()->sole();
        $this->assertSame($acompanhamento->id, $problema->followup_id);
        $this->assertTrue($problema->exercise_care);
        $this->assertSame(2024, $problema->since_year);
    }

    public function test_origem_de_outra_pessoa_e_recusada(): void
    {
        $doJoao = app(HealthFollowupService::class)->create($this->membroJoao, ['title' => 'Do João', 'status' => 'investigating', 'started_on' => '2026-11-20'], $this->membroAna);

        Livewire::test(HealthCardIndex::class)->call('newCondition', $this->membroAna->id)
            ->set('condText', 'Algo')->set('condOrigin', 'followup:'.$doJoao->id)->call('saveCondition')->assertNotFound();

        $this->assertSame(0, HealthCondition::query()->count());
    }

    public function test_limitacao_do_passado_entra_pela_ficha_e_sobrevive_ao_acompanhamento_excluido(): void
    {
        Livewire::test(LimitationPanel::class, ['memberId' => $this->membroAna->id])->assertSee('Limitações físicas');

        $doPassado = app(LimitationService::class)->save($this->membroAna, ['region' => 'knee', 'status' => 'guided', 'avoid' => ['impact'], 'since_year' => 2019], $this->membroAna);
        $this->assertNull($doPassado->followup_id);

        $acompanhamento = $this->acompanhamento();
        $ligada = app(LimitationService::class)->save($acompanhamento, ['region' => 'lower_back', 'status' => 'investigating', 'avoid' => []], $this->membroAna);
        app(HealthFollowupService::class)->delete($acompanhamento->fresh());

        $this->assertNull($ligada->fresh()->followup_id);
        $this->assertSame(2, PhysicalLimitation::query()->count());
    }

    public function test_limitacao_guarda_desde_quando_e_a_origem_de_consulta_ou_exame(): void
    {
        $consulta = HealthAppointment::create(['member_id' => $this->membroAna->id, 'kind' => 'consultation', 'title' => 'Ortopedista', 'scheduled_at' => '2026-11-10 10:00', 'status' => 'done']);
        $laudo = HealthExamReport::create(['member_id' => $this->membroAna->id, 'performed_on' => '2026-11-12', 'kind' => 'conclusion', 'exam_name' => 'Ressonância', 'conclusion' => 'Texto do laudo']);

        foreach (['appointment:'.$consulta->id => ['appointment_id', $consulta->id], 'exam:'.$laudo->id => ['exam_report_id', $laudo->id]] as $origem => [$coluna, $id]) {
            Livewire::test(LimitationPanel::class, ['memberId' => $this->membroAna->id, 'newFrom' => $origem])
                ->set('region', 'knee')->set('status', 'guided')->set('sinceYear', '2022')
                ->call('save')->assertHasNoErrors();

            $limitacao = PhysicalLimitation::query()->whereNotNull($coluna)->sole();
            $this->assertSame($id, $limitacao->{$coluna});
            $this->assertSame(2022, (int) $limitacao->since_year);
        }
    }

    // --- Prontidão ------------------------------------------------------------------------------

    public function test_prontidao_exige_tudo_e_sem_liberacao_o_plano_e_leve_e_a_corrida_nao_comeca(): void
    {
        $servico = app(ReadinessService::class);
        $this->assertSame(ReadinessService::MISSING, $servico->status());

        try {
            $servico->save(['heart' => 'no'], null, null, null);
            $this->fail('Aceitou respostas pela metade.');
        } catch (DomainException) {
        }

        try {
            $this->prontidao(sim: 'joints');
            $this->fail('Aceitou um sim sem dizer da liberação.');
        } catch (DomainException) {
        }

        $this->prontidao(sim: 'joints', liberacao: 'no');
        $this->assertSame(ReadinessService::LIGHT, $servico->status());

        foreach ([false, true] as $mesmoAssim) {
            try {
                app(RunningService::class)->start(1, 2, [4, 6], 0, $mesmoAssim);
                $this->fail('A corrida começou sem liberação.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('Sem liberação médica', $e->getMessage());
            }
        }

        Livewire::test(ActivityRunning::class)->assertSee('Abrir as perguntas de prontidão')->assertDontSee('Montar programa');

        $this->prontidao(sim: 'joints', liberacao: 'yes');
        $this->assertSame(ReadinessService::CLEARED, $servico->status());
        $this->assertNotNull(app(RunningService::class)->start(1, 2, [4, 6], 0));

        $this->assertStringNotContainsString('Dra', (string) DB::table('activity_readiness')->value('cleared_by'));
    }

    public function test_respostas_vencem_em_um_ano_ou_quando_a_saude_muda(): void
    {
        $this->prontidao();
        $servico = app(ReadinessService::class);

        $this->travel(2)->hours();
        $problema = app(HealthCardService::class)->addCondition($this->membroAna, 'Rinite', $this->membroAna);
        $this->assertSame(ReadinessService::OK, $servico->status());

        $problema->update(['exercise_care' => true]);
        $this->assertSame(ReadinessService::EXPIRED, $servico->status());

        $this->travel(1)->minutes();
        $this->prontidao();
        $this->assertSame(ReadinessService::OK, $servico->status());

        $this->travelTo(CarbonImmutable::parse('2027-11-28 09:00'));
        $this->assertSame(ReadinessService::EXPIRED, $servico->status());
    }

    public function test_a_tela_de_prontidao_diz_o_que_vai_acontecer_e_guarda(): void
    {
        $tela = Livewire::test(ActivityReadinessPage::class);

        foreach (array_keys(ReadinessQuestions::ALL) as $chave) {
            $tela->set('answers.'.$chave, $chave === 'medication' ? 'yes' : 'no');
        }

        $tela->assertSee('Como há um "sim", informe se você já tem liberação médica.')
            ->set('clearance', 'no')->assertSee('o programa de corrida não começa nem avança')
            ->call('save')->assertHasNoErrors()->assertSee('Respostas guardadas.');

        $this->assertSame(ReadinessService::LIGHT, app(ReadinessService::class)->status());
    }

    // --- Planejador -----------------------------------------------------------------------------

    public function test_ponto_de_partida_vem_do_diario_e_sem_diario_do_perfil(): void
    {
        $planejador = app(WeekPlannerService::class);
        $atividades = app(ActivityService::class);
        $atividades->saveCommitment(['activity' => 'tennis', 'weekday' => 1, 'minutes' => 60, 'effort' => 6]);

        $this->assertSame(['sport' => 60, 'other' => 0, 'total' => 60, 'source' => 'profile', 'weeks' => 0], $planejador->baseline());

        foreach (['2026-11-10', '2026-11-17'] as $dia) {
            $atividades->record(['activity' => 'tennis', 'done_on' => $dia, 'minutes' => 90, 'effort' => 7]);
        }
        $atividades->record(['activity' => 'walking', 'done_on' => '2026-11-18', 'minutes' => 40, 'effort' => 3]);

        // Duas semanas com registro: a média é por duas, não por quatro.
        $this->assertSame(['sport' => 90, 'other' => 20, 'total' => 110, 'source' => 'diary', 'weeks' => 2], $planejador->baseline());
    }

    public function test_a_progressao_sobe_15_alivia_a_cada_quatro_e_respeita_o_teto(): void
    {
        $planejador = app(WeekPlannerService::class);

        $this->assertSame(150, $planejador->target('health', 60, false));
        $this->assertSame(180, $planejador->target('weight_loss', 90, false));
        $this->assertSame(300, $planejador->target('weight_loss', 280, false));
        $this->assertSame(330, $planejador->target('weight_loss', 330, false));
        $this->assertSame(40, $planejador->target('weight_loss', 0, true));
        $this->assertSame([105, 120, 135, 110, 150, 165, 180, 145], array_slice(array_column($planejador->progression(90, 180, false), 'minutes'), 0, 8));
        $this->assertSame([60, 60, 60, 60], array_slice(array_column($planejador->progression(60, 60, true), 'minutes'), 0, 4));
    }

    public function test_a_proposta_deixa_descanso_e_nao_poe_aerobico_no_dia_do_esporte(): void
    {
        $this->rotinaDaAna();

        $proposta = app(WeekPlannerService::class)->proposal('weight_loss', [0, 1, 2, 3, 4, 5, 6], ['walking', 'cycling'], false);

        $this->assertCount(3, $proposta['layout']['strength']);
        $this->assertNotContains(1, $proposta['layout']['aerobic']);
        $ocupados = array_unique([...$proposta['layout']['strength'], ...$proposta['layout']['aerobic'], ...array_keys($proposta['sport_days'])]);
        $this->assertLessThanOrEqual(6, count($ocupados));
        $this->assertSame(0, $proposta['layout']['leg_eve']);
        // O que o plano distribui fecha com a primeira semana da progressão.
        $this->assertSame($proposta['progression'][0]['minutes'], $proposta['baseline']['sport'] + array_sum(array_column($proposta['week'], 'minutes')) + $proposta['missing_minutes']);
    }

    public function test_aplicar_mantem_os_exercicios_muda_as_series_e_desfazer_volta_tudo(): void
    {
        $this->rotinaDaAna();
        $this->prontidao();
        $antes = $this->fotoDoTreino();
        $update = app(ProgramUpdateService::class);
        $mudancas = $update->prescriptionChanges('weight_loss', false);
        $this->assertNotEmpty($mudancas);

        $plano = $update->apply('weight_loss', [0, 1, 2, 3, 4, 5], ['walking'], array_keys($mudancas), []);

        $depois = $this->fotoDoTreino();
        $this->assertSame(array_column($antes, 0), array_column($depois, 0));
        $this->assertNotSame($antes, $depois);
        $this->assertSame(GymGoal::WeightLoss, app(GymPlanService::class)->activePlan()->goal);
        $this->assertSame($plano->strength_days, app(ActivityService::class)->profile()->gym_days);

        // Atualizar de novo com o mesmo objetivo não devolve as séries antigas.
        $segundo = $update->apply('weight_loss', [0, 2, 3, 4, 5], ['walking'], [], []);
        $this->assertSame($depois, $this->fotoDoTreino());
        $this->assertSame(1, ActivityPlan::query()->where('is_active', true)->count());

        $update->undo($segundo);

        $this->assertSame($antes, $this->fotoDoTreino());
        $this->assertNull(app(GymPlanService::class)->activePlan()->goal);
        $this->assertSame([0, 2, 4], app(ActivityService::class)->profile()->gym_days);
        $this->assertNull(app(WeekPlannerService::class)->active());
    }

    public function test_pausa_pela_limitacao_continua_ao_refazer_o_plano_e_volta_ao_desfazer(): void
    {
        $this->rotinaDaAna();
        $this->prontidao();
        app(LimitationService::class)->save($this->acompanhamento(), ['region' => 'lower_back', 'status' => 'investigating', 'avoid' => ['spinal_load']], $this->membroAna);
        $update = app(ProgramUpdateService::class);
        $pausas = array_keys($update->pauses());
        $this->assertNotEmpty($pausas);
        $antes = $this->fotoDoTreino();

        $update->apply('health', [0, 2, 3, 4, 5], ['walking'], [], $pausas);
        $pausado = $this->fotoDoTreino();
        $this->assertCount(count($antes) - count($pausas), $pausado);

        $segundo = $update->apply('health', [0, 3, 4, 5], ['walking'], [], []);
        $this->assertSame($pausado, $this->fotoDoTreino());
        $this->assertSame(count($pausas), GymPlanAdjustment::query()->where('activity_plan_id', $segundo->id)->whereNull('reverted_at')->count());

        $update->undo($segundo);
        $this->assertSame($antes, $this->fotoDoTreino());
    }

    public function test_plano_leve_nao_muda_series_nem_aumenta(): void
    {
        $this->rotinaDaAna();
        $this->prontidao(sim: 'heart', liberacao: 'no');
        $antes = $this->fotoDoTreino();
        $update = app(ProgramUpdateService::class);

        $this->assertSame([], $update->prescriptionChanges('weight_loss', true));
        $plano = $update->apply('weight_loss', [0, 2, 3, 4, 5], ['walking'], array_map(fn (GymWorkout $t) => $t->id, GymWorkout::query()->get()->all()), []);

        $this->assertTrue($plano->is_light);
        $this->assertSame($plano->base_minutes, $plano->target_minutes);
        $this->assertSame($antes, $this->fotoDoTreino());
    }

    public function test_a_semana_mostra_o_aerobico_do_plano_e_so_avanca_com_ele_cumprido(): void
    {
        $this->prontidao();
        $plano = app(ProgramUpdateService::class)->apply('health', [0, 2, 4, 5], ['walking'], [], []);
        $semanas = app(ActivityWeekService::class);
        $planejador = app(WeekPlannerService::class);

        $aerobico = $semanas->week(CarbonImmutable::parse('2026-11-23'))['items']->filter(fn (array $i) => str_starts_with((string) $i['key'], 'p:'));
        $this->assertNotEmpty($aerobico);
        $this->assertSame($planejador->minutesAt(1, $plano->base_minutes, $plano->target_minutes, false), $aerobico->sum('minutes'));

        // A semana passou sem nada: a progressão não anda.
        $this->travelTo(CarbonImmutable::parse('2026-11-30 09:00'));
        $this->assertSame(1, $planejador->progress($plano)['step']);

        foreach ($planejador->sessionsFor($plano, CarbonImmutable::parse('2026-11-30')) as $s) {
            app(ActivityService::class)->record(['activity' => $s['type']->value, 'done_on' => CarbonImmutable::parse('2026-11-30')->addDays($s['weekday'])->toDateString(), 'minutes' => $s['minutes'], 'effort' => 5]);
        }

        $this->travelTo(CarbonImmutable::parse('2026-12-07 09:00'));
        $this->assertSame(2, $planejador->progress($plano)['step']);
    }

    public function test_a_tela_do_plano_pede_a_prontidao_mostra_a_proposta_e_aplica(): void
    {
        $this->rotinaDaAna();

        Livewire::test(ActivityPlanPage::class)->assertSee('Responda antes às perguntas de prontidão.')->assertDontSee('Atualizar meu programa</button>', false);

        $this->prontidao();

        Livewire::test(ActivityPlanPage::class)
            ->set('goal', 'weight_loss')
            ->assertSee('A semana proposta')->assertSee('O que muda no treino da academia')->assertSee('Os exercícios e o histórico ficam.')
            ->assertSee('Você pode desfazer depois.')
            ->call('apply')->assertHasNoErrors()->assertSee('Plano em vigor')->assertSee('Desfazer atualização')
            ->call('undo')->assertHasNoErrors()->assertSee('Atualização desfeita');

        $this->assertNull(app(WeekPlannerService::class)->active());
    }

    public function test_o_plano_e_a_prontidao_sao_so_da_pessoa(): void
    {
        $this->prontidao();
        app(ProgramUpdateService::class)->apply('health', [0, 2, 4], ['walking']);

        $this->entrarComoJoao();

        $this->assertNull(app(WeekPlannerService::class)->active());
        $this->assertNull(app(ReadinessService::class)->record());
    }

    // --- Revisão das regras ---------------------------------------------------------------------

    public function test_o_convite_guarda_so_o_hash_e_a_aprovacao_vale_para_a_versao_revisada(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        [$revisor, $link] = app(RuleReviewService::class)->invite('Paula Prado', '000000-G/SP', 'paula@example.com', $admin);
        $token = basename($link);
        $this->assertSame(hash('sha256', $token), $revisor->token_hash);

        $this->sair();
        $this->get(route('rules.review', 'um-token-qualquer'))->assertNotFound();
        $this->get($link)->assertOk()->assertSee('Programa de caminhada e corrida')->assertDontSee('Ana Teste');

        Livewire::test(RuleReviewPage::class, ['token' => $token])->call('open', 'running')->call('decide', RuleReview::APPROVED)->call('submit')->assertHasNoErrors();

        $this->assertSame('Paula Prado', app(RuleReviewService::class)->approval('running')['name']);

        // A regra mudou no código (outra impressão digital): a aprovação antiga deixa de valer.
        RuleReview::query()->update(['rule_version' => sha1('versão antiga')]);
        app()->forgetInstance(RuleReviewService::class);
        $this->assertNull(app(RuleReviewService::class)->approval('running'));
    }

    public function test_acesso_revogado_corta_na_hora(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        [$revisor, $link] = app(RuleReviewService::class)->invite('Paula Prado', '000000-G/SP', null, $admin);
        $this->sair();

        $tela = Livewire::test(RuleReviewPage::class, ['token' => basename($link)]);
        app(RuleReviewService::class)->revoke($revisor);

        $tela->call('open', 'running')->assertForbidden();
        $this->get($link)->assertNotFound();
    }

    public function test_tela_de_regras_e_so_do_administrador_sem_estar_como_outra_pessoa(): void
    {
        $this->sair();
        $this->actingAs($this->ana);
        Livewire::test(AdminRules::class)->assertForbidden();

        $admin = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($admin);
        Livewire::test(AdminRules::class)->assertOk()->set('name', 'Paula Prado')->set('registry', '000000-G/SP')->call('invite')->assertHasNoErrors()->assertSee('revisao-das-regras/');
        $this->assertSame(1, RuleReviewer::query()->count());

        session()->put('impersonator_id', $admin->id);
        Livewire::test(AdminRules::class)->assertForbidden();
    }

    public function test_cada_regra_tem_chave_unica_e_versao_pelo_conteudo(): void
    {
        $regras = RuleBook::all();

        $this->assertSame(count($regras), count(array_unique(array_column($regras, 'key'))));

        foreach (['running', 'readiness', 'week_plan', 'weight_loss_strength', 'bmi', 'week_warnings'] as $chave) {
            $this->assertNotNull(RuleBook::find($chave), $chave);
            $this->assertSame(40, strlen(RuleBook::find($chave)['version']));
        }
    }

    // --- Conta ----------------------------------------------------------------------------------

    public function test_excluir_a_conta_leva_as_tabelas_novas(): void
    {
        $this->rotinaDaAna();
        $this->prontidao();
        $this->medir(80, 170);
        app(ProgramUpdateService::class)->apply('health', [0, 2, 4, 5], ['walking']);

        $this->sair();
        app(\App\Services\Privacy\AccountDeletionService::class)->delete($this->ana->fresh());

        foreach (['health_measurements', 'activity_readiness', 'activity_plans'] as $tabela) {
            $this->assertSame(0, DB::table($tabela)->count(), $tabela);
        }
    }

    // --- Apoio ----------------------------------------------------------------------------------

    private function entrarComoAna(): void
    {
        $this->actingAs($this->ana);
        app(ProfileContext::class)->set($this->perfil, $this->membroAna);
    }

    private function entrarComoJoao(): void
    {
        $this->actingAs($this->joao);
        app(ProfileContext::class)->set($this->perfil, $this->membroJoao);
    }

    private function sair(): void
    {
        auth()->logout();
        app(ProfileContext::class)->clear();
    }

    private function medir(float $peso, int $altura): HealthMeasurement
    {
        return app(HealthMeasurementService::class)->add($this->membroAna, ['measured_on' => '2026-11-26', 'weight' => $peso, 'height' => $altura], $this->membroAna);
    }

    private function prontidao(?string $sim = null, ?string $liberacao = null): void
    {
        $respostas = array_fill_keys(array_keys(ReadinessQuestions::ALL), 'no');

        if ($sim !== null) {
            $respostas[$sim] = 'yes';
        }

        app(ReadinessService::class)->save($respostas, $liberacao, $liberacao === 'yes' ? 'Dra. Teste' : null, $liberacao === 'yes' ? '2026-11-20' : null);
    }

    private function acompanhamento(): HealthFollowup
    {
        return app(HealthFollowupService::class)->create($this->membroAna, ['title' => 'Dor nas costas', 'status' => 'investigating', 'started_on' => '2026-11-20'], $this->membroAna);
    }

    /** Tênis fixo na terça, academia segunda, quarta e sexta, com A (superior), B (pernas) e C. */
    private function rotinaDaAna(): void
    {
        $atividades = app(ActivityService::class);
        $atividades->saveCommitment(['activity' => ActivityType::Tennis->value, 'weekday' => 1, 'minutes' => 60, 'effort' => 6]);
        $atividades->saveProfile(['Jogar sem dor'], [0, 2, 4], 60, true);

        $planos = app(GymPlanService::class);
        $plano = $planos->createPlan('Fase tênis');

        foreach ([
            'Treino A' => [['Puxada frontal', GymMuscleGroup::Back], ['Remada curvada', GymMuscleGroup::Back]],
            'Treino B' => [['Agachamento livre', GymMuscleGroup::Legs], ['Stiff', GymMuscleGroup::Legs], ['Afundo', GymMuscleGroup::Legs]],
            'Treino C' => [['Supino reto', GymMuscleGroup::Chest], ['Remada baixa', GymMuscleGroup::Back]],
        ] as $nome => $exercicios) {
            $treino = $planos->addWorkout($plano, $nome, null);

            foreach ($exercicios as [$exercicio, $grupo]) {
                $planos->addExercise($treino, $planos->findOrCreateExercise($exercicio, $grupo, GymMeasureType::LoadReps), ['target_sets' => 4, 'target_reps_min' => 6, 'target_reps_max' => 8, 'rest_seconds' => 150]);
            }
        }
    }

    /** @return list<array{0: string, 1: int, 2: ?int, 3: ?int, 4: ?int}> os itens de todos os treinos, na ordem */
    private function fotoDoTreino(): array
    {
        return GymWorkoutExercise::query()->with(['exercise', 'workout'])->get()
            ->sortBy(fn (GymWorkoutExercise $i) => [$i->workout->name, $i->position])
            ->map(fn (GymWorkoutExercise $i) => [$i->exercise->name, $i->target_sets, $i->target_reps_min, $i->target_reps_max, $i->rest_seconds])->values()->all();
    }
}
