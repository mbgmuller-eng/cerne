<?php

namespace Tests\Feature;

use App\Enums\GymEquipmentAccess;
use App\Enums\GymGoal;
use App\Enums\GymLevel;
use App\Enums\GymLoadMode;
use App\Enums\GymMeasureType;
use App\Enums\GymMuscleGroup;
use App\Enums\GymRestriction;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Livewire\Health\Gym\GymHome;
use App\Livewire\Health\Gym\GymPlanGenerator;
use App\Models\FinancialProfile;
use App\Models\GymPlan;
use App\Models\GymSession;
use App\Models\GymSetLog;
use App\Models\GymWorkout;
use App\Models\GymWorkoutExercise;
use App\Models\ProfileMember;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\GymPlanReviewDue;
use App\Services\GymPhaseReviewService;
use App\Services\GymPlanService;
use App\Services\GymWorkoutGenerator;
use App\Support\GymExerciseClassifier;
use App\Support\GymGeneratorInput;
use App\Support\NotificationPresenter;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Database\Seeders\GymExerciseCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fase de treino: há quanto tempo ela dura, quando já é hora de trocar (prazo por objetivo, estagnação, adesão), o aviso
 * único por fase e a pergunta "seus objetivos continuam os mesmos?" ao criar o treino novo.
 */
class GymPhaseReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private FinancialProfile $perfil;

    private ProfileMember $membro;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-20 12:00:00'));
        $this->seed(GymExerciseCatalogSeeder::class);

        $this->usuario = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->usuario->id]);
        $this->membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuario->id]);
        $this->actingAs($this->usuario);
        app(ProfileContext::class)->set($this->perfil, $this->membro);
        Subscription::create([
            'user_id' => $this->usuario->id, 'kind' => SubscriptionKind::Direct,
            'bundle' => SubscriptionBundle::Completo, 'status' => SubscriptionStatus::Active, 'started_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** Uma fase com objetivo, iniciada há `$semanas` semanas, com três exercícios num treino só. */
    private function fase(int $semanas, ?GymGoal $objetivo = GymGoal::Hypertrophy, ?GymLevel $nivel = GymLevel::Intermediate, int $dias = 3): GymPlan
    {
        $servico = app(GymPlanService::class);
        $fase = $servico->createPlan('Fase atual');
        $fase->update([
            'started_on' => CarbonImmutable::today()->subWeeks($semanas)->toDateString(),
            'goal' => $objetivo, 'level' => $nivel, 'days_per_week' => $dias,
        ]);

        $treino = $servico->addWorkout($fase, 'Treino A', 'Corpo inteiro');
        $meta = ['target_sets' => 3, 'target_reps_min' => 8, 'target_reps_max' => 12, 'rest_seconds' => 60];

        foreach ([['Supino', GymMuscleGroup::Chest], ['Remada', GymMuscleGroup::Back], ['Agachamento', GymMuscleGroup::Legs]] as [$nome, $grupo]) {
            $servico->addExercise($treino, $servico->findOrCreateExercise($nome, $grupo, GymMeasureType::LoadReps), $meta);
        }

        return $fase->fresh();
    }

    /**
     * @param  list<string>  $cargas  uma carga por sessão; todos os exercícios da fase usam a mesma
     */
    private function treinar(GymPlan $fase, array $cargas, int $primeiroDiaAtras = 1): void
    {
        $treino = $fase->workouts()->with('workoutExercises')->firstOrFail();

        foreach ($cargas as $i => $carga) {
            $dia = CarbonImmutable::today()->subDays($primeiroDiaAtras + count($cargas) - 1 - $i);
            $sessao = GymSession::create([
                'workout_id' => $treino->id, 'performed_on' => $dia->toDateString(),
                'started_at' => $dia->setTime(18, 0), 'finished_at' => $dia->setTime(19, 0),
            ]);

            foreach ($treino->workoutExercises as $item) {
                foreach ([1, 2, 3] as $n) {
                    GymSetLog::create([
                        'session_id' => $sessao->id, 'exercise_id' => $item->exercise_id, 'set_number' => $n, 'reps' => 10,
                        'load_value' => $carga, 'load_mode' => GymLoadMode::Total, 'completed_at' => $dia->setTime(18, $n),
                    ]);
                }
            }
        }
    }

    /** Sessões suficientes (sem platô: a carga sobe) para a adesão não pesar. */
    private function treinarBem(GymPlan $fase, int $semanas, int $dias = 3): void
    {
        $this->treinar($fase, array_map(fn (int $i) => (string) (40 + $i * 2.5), range(0, max(0, $semanas * $dias - 1))));
    }

    private function avaliar(GymPlan $fase): array
    {
        return app(GymPhaseReviewService::class)->review($fase->fresh());
    }

    // --- Prazos ----------------------------------------------------------------------------------------------------------

    public function test_o_prazo_depende_do_objetivo_e_o_iniciante_ganha_duas_semanas(): void
    {
        $servico = app(GymPhaseReviewService::class);

        self::assertSame([8, 12], $servico->thresholds(GymGoal::Hypertrophy, GymLevel::Intermediate));
        self::assertSame([8, 12], $servico->thresholds(GymGoal::GeneralHealth, GymLevel::Advanced));
        self::assertSame([6, 8], $servico->thresholds(GymGoal::Strength, GymLevel::Intermediate));
        self::assertSame([6, 8], $servico->thresholds(GymGoal::WeightLoss, GymLevel::Advanced));
        self::assertSame([6, 8], $servico->thresholds(GymGoal::Endurance, GymLevel::Intermediate));
        self::assertSame([8, 10], $servico->thresholds(GymGoal::Strength, GymLevel::Beginner));
        self::assertSame([10, 14], $servico->thresholds(GymGoal::Hypertrophy, GymLevel::Beginner));
        self::assertSame([8, 12], $servico->thresholds(null, null), 'fase à mão usa a faixa mais larga');
    }

    public function test_fase_nova_esta_em_dia_e_mostra_o_tempo_e_os_treinos(): void
    {
        $fase = $this->fase(2);
        $this->treinarBem($fase, 2);

        $r = $this->avaliar($fase);

        self::assertSame('ok', $r['status']);
        self::assertSame(2, $r['weeks']);
        self::assertSame(6, $r['sessions']);
        self::assertSame('Fase em dia', $r['headline']);
    }

    public function test_entra_na_faixa_de_troca_e_depois_passa_a_ser_hora(): void
    {
        $emDia = $this->fase(7);
        $this->treinarBem($emDia, 7);
        self::assertSame('ok', $this->avaliar($emDia)['status']);

        $emDia->update(['started_on' => CarbonImmutable::today()->subWeeks(9)->toDateString()]);
        self::assertSame('soon', $this->avaliar($emDia)['status']);

        $emDia->update(['started_on' => CarbonImmutable::today()->subWeeks(13)->toDateString()]);
        $hora = $this->avaliar($emDia);
        self::assertSame('due', $hora['status']);
        self::assertStringContainsString('Hora de renovar o treino', $hora['headline']);
    }

    public function test_a_mesma_fase_vence_antes_para_forca_do_que_para_hipertrofia(): void
    {
        $fase = $this->fase(9, GymGoal::Strength);
        $this->treinarBem($fase, 9);

        self::assertSame('due', $this->avaliar($fase)['status'], '9 semanas passa de 8 para força');

        $fase->update(['goal' => GymGoal::Hypertrophy]);
        self::assertSame('soon', $this->avaliar($fase)['status'], 'mas só entra na faixa para hipertrofia');
    }

    public function test_pouco_treino_na_faixa_de_troca_pede_para_retomar_a_rotina_em_vez_de_trocar(): void
    {
        $fase = $this->fase(9);
        $this->treinar($fase, ['40', '40']);   // 2 de ~27 esperados

        $r = $this->avaliar($fase);

        self::assertSame('slow', $r['status']);
        self::assertStringContainsString('retome a rotina', $r['headline']);
    }

    public function test_metade_dos_exercicios_parados_antecipa_a_troca(): void
    {
        $fase = $this->fase(4);
        $this->treinar($fase, ['50', '50', '50', '50']);   // mesma carga: platô nos três exercícios

        $r = $this->avaliar($fase);

        self::assertSame('due', $r['status']);
        self::assertSame(3, $r['plateau']);
        self::assertStringContainsString('3 de 3 exercícios', $r['message']);
    }

    public function test_exercicio_que_evolui_nao_conta_como_estagnado(): void
    {
        $fase = $this->fase(4);
        $this->treinar($fase, ['40', '42.5', '45', '47.5']);

        self::assertSame('ok', $this->avaliar($fase)['status']);
    }

    public function test_sem_objetivo_guardado_usa_a_faixa_larga_e_pede_para_definir(): void
    {
        $fase = $this->fase(9, null, null);
        $this->treinarBem($fase, 9);

        $r = $this->avaliar($fase);

        self::assertSame('soon', $r['status']);
        self::assertStringContainsString('Defina o objetivo', $r['message']);
    }

    public function test_sem_fase_ativa_nao_ha_avaliacao(): void
    {
        self::assertNull(app(GymPhaseReviewService::class)->review());
    }

    public function test_sugere_subir_de_nivel_so_com_tempo_e_volume_e_nunca_sozinho(): void
    {
        $servico = app(GymPhaseReviewService::class);
        self::assertNull($servico->levelSuggestion(GymLevel::Beginner), 'sem histórico não sugere');

        $fase = $this->fase(1);
        $treino = $fase->workouts()->firstOrFail();
        $inicio = CarbonImmutable::today()->subMonths(8);

        foreach (range(0, 44) as $i) {
            GymSession::create([
                'workout_id' => $treino->id, 'performed_on' => $inicio->addDays($i * 5)->toDateString(),
                'started_at' => $inicio->addDays($i * 5)->setTime(18, 0), 'finished_at' => $inicio->addDays($i * 5)->setTime(19, 0),
            ]);
        }

        self::assertSame(GymLevel::Intermediate, $servico->levelSuggestion(GymLevel::Beginner));
        self::assertNull($servico->levelSuggestion(GymLevel::Intermediate), 'intermediário pede 24 meses');
        self::assertNull($servico->levelSuggestion(GymLevel::Advanced));
    }

    // --- Aviso diário ----------------------------------------------------------------------------------------------------

    public function test_a_rotina_avisa_uma_unica_vez_por_fase(): void
    {
        Notification::fake();
        $fase = $this->fase(13);
        $this->treinarBem($fase, 13);

        $servico = app(GymPhaseReviewService::class);

        self::assertSame(1, $servico->notifyDue());
        self::assertSame(0, $servico->notifyDue(), 'rodar de novo não repete');
        Notification::assertSentToTimes($this->usuario, GymPlanReviewDue::class, 1);
        self::assertSame(1, DB::table('engagement_reminders')->where('subject_type', 'gym_plan')->where('kind', 'review_due')->count());
    }

    public function test_a_rotina_nao_avisa_fase_em_dia_nem_sem_o_modulo_saude(): void
    {
        Notification::fake();
        $emDia = $this->fase(3);
        $this->treinarBem($emDia, 3);

        self::assertSame(0, app(GymPhaseReviewService::class)->notifyDue());

        $emDia->update(['started_on' => CarbonImmutable::today()->subWeeks(13)->toDateString()]);
        Subscription::query()->where('user_id', $this->usuario->id)->update(['status' => SubscriptionStatus::Cancelled]);

        self::assertSame(0, app(GymPhaseReviewService::class)->notifyDue(), 'sem a assinatura de Saúde não avisa');
        Notification::assertNothingSent();
    }

    public function test_a_rotina_nao_deixa_o_contexto_de_um_cliente_vazar_para_o_seguinte(): void
    {
        $this->fase(13);

        app(GymPhaseReviewService::class)->notifyDue();

        self::assertNull(app(ProfileContext::class)->profile(), 'o contexto montado para avaliar é solto no fim');
    }

    public function test_o_aviso_aparece_com_o_texto_certo_nas_notificacoes(): void
    {
        $n = new GymPlanReviewDue($this->perfil->id, 'plano-1', 'Hora de renovar o treino', 'Sua fase está há 13 semanas.');
        $dados = $n->toArray($this->usuario);

        self::assertSame('gym_plan_review_due', $dados['kind']);

        $apresentado = NotificationPresenter::present(new DatabaseNotification(['data' => $dados]));
        self::assertSame('Hora de renovar o treino', $apresentado['heading']);
        self::assertSame('Sua fase está há 13 semanas.', $apresentado['message']);
        self::assertSame(route('health.gym.generate'), $apresentado['url']);
    }

    // --- Tela inicial da Academia -------------------------------------------------------------------------------------------

    public function test_a_academia_mostra_o_aviso_quando_e_hora_de_trocar(): void
    {
        $fase = $this->fase(13);
        $this->treinarBem($fase, 13);

        Livewire::test(GymHome::class)
            ->assertSee('Hora de renovar o treino')
            ->assertSee('Criar o treino novo')
            ->assertSee('Ver o relatório da fase');
    }

    public function test_a_academia_nao_mostra_aviso_com_a_fase_em_dia(): void
    {
        $fase = $this->fase(2);
        $this->treinarBem($fase, 2);

        Livewire::test(GymHome::class)->assertDontSee('Hora de renovar o treino')->assertDontSee('Criar o treino novo');
    }

    public function test_fase_a_mao_pode_ganhar_objetivo_e_nivel(): void
    {
        $fase = $this->fase(2, null, null);

        Livewire::test(GymHome::class)
            ->assertSee('Definir o objetivo desta fase')
            ->call('openGoal')->assertSet('showGoal', true)
            ->call('saveGoal')->assertHasErrors(['goal', 'level'])
            ->set('goal', 'strength')->set('level', 'beginner')->call('saveGoal')->assertHasNoErrors()->assertSet('showGoal', false)
            ->assertDontSee('Definir o objetivo desta fase');

        $fase->refresh();
        self::assertSame(GymGoal::Strength, $fase->goal);
        self::assertSame(GymLevel::Beginner, $fase->level);
    }

    // --- Treino novo: "seus objetivos continuam os mesmos?" ------------------------------------------------------------------

    /** Cria uma fase pelo gerador (guarda as respostas) e a deixa com tempo e treinos. */
    private function faseGerada(array $campos = []): GymPlan
    {
        $c = array_merge(['goal' => GymGoal::Strength, 'level' => GymLevel::Beginner, 'days' => 3, 'minutes' => 60, 'access' => GymEquipmentAccess::FullGym, 'restrictions' => [GymRestriction::Knee]], $campos);
        $rascunho = app(GymWorkoutGenerator::class)->generate(new GymGeneratorInput($c['goal'], $c['level'], $c['days'], $c['minutes'], $c['access'], $c['restrictions']));
        $fase = app(GymPlanService::class)->createPlanFromDraft($rascunho);
        $fase->update(['started_on' => CarbonImmutable::today()->subWeeks(9)->toDateString()]);

        return $fase->fresh();
    }

    public function test_a_fase_guarda_as_respostas_do_gerador(): void
    {
        $fase = $this->faseGerada();

        self::assertSame(GymGoal::Strength, $fase->goal);
        self::assertSame(GymLevel::Beginner, $fase->level);
        self::assertSame(3, $fase->days_per_week);
        self::assertSame(60, $fase->session_minutes);
        self::assertSame(GymEquipmentAccess::FullGym, $fase->equipment_access);
        self::assertSame(['knee'], $fase->restrictions);
    }

    public function test_quem_ja_tem_fase_cai_na_pergunta_dos_objetivos_com_as_respostas_anteriores(): void
    {
        $fase = $this->faseGerada();
        $this->treinarBemTodosOsTreinos($fase);

        Livewire::test(GymPlanGenerator::class)
            ->assertSet('step', 'review')
            ->assertSee('Seus objetivos continuam os mesmos?')
            ->assertSee('Ganhar força')
            ->assertSee('Sim, manter e montar o treino novo')
            ->assertSee('Mudou, quero ajustar')
            ->assertSet('goal', 'strength')->assertSet('level', 'beginner')->assertSet('days', 3);
    }

    public function test_quem_nao_tem_fase_vai_direto_ao_pedido(): void
    {
        Livewire::test(GymPlanGenerator::class)->assertSet('step', 'form')->assertDontSee('Seus objetivos continuam os mesmos?');
    }

    public function test_manter_os_objetivos_gera_o_rascunho_com_as_mesmas_respostas(): void
    {
        $fase = $this->faseGerada();

        $tela = Livewire::test(GymPlanGenerator::class)->call('keepGoals')
            ->assertSet('step', 'draft')->assertSet('goal', 'strength')->assertSet('level', 'beginner');

        self::assertSame(3, count($tela->get('draft.workouts')));
        self::assertSame(1, GymPlan::query()->count(), 'nada é gravado antes do Salvar');
        self::assertTrue($fase->fresh()->is_active);
    }

    public function test_por_padrao_o_treino_novo_varia_os_exercicios_da_fase_anterior(): void
    {
        $fase = $this->faseGerada(['goal' => GymGoal::Hypertrophy, 'level' => GymLevel::Intermediate, 'restrictions' => []]);
        $anteriores = app(GymPlanService::class)->exerciseNamesOf($fase);

        $tela = Livewire::test(GymPlanGenerator::class)->assertSet('variety', 'vary')->call('keepGoals');

        $novos = collect($tela->get('draft.workouts'))->flatMap(fn ($t) => $t['exercises'])
            ->where('kind', '!=', 'cardio')->pluck('name')->map(fn ($n) => GymExerciseClassifier::normalize($n))->all();

        self::assertNotEmpty($novos);
        self::assertSame([], array_values(array_intersect($novos, $anteriores)), 'o treino novo não repete a fase anterior');
    }

    public function test_tanto_faz_nao_olha_a_fase_anterior(): void
    {
        $fase = $this->faseGerada(['goal' => GymGoal::Hypertrophy, 'level' => GymLevel::Intermediate, 'restrictions' => []]);
        $anteriores = app(GymPlanService::class)->exerciseNamesOf($fase);

        $tela = Livewire::test(GymPlanGenerator::class)->set('variety', 'free')->call('keepGoals');

        $novos = collect($tela->get('draft.workouts'))->flatMap(fn ($t) => $t['exercises'])
            ->pluck('name')->map(fn ($n) => GymExerciseClassifier::normalize($n))->all();

        self::assertNotEmpty(array_intersect($novos, $anteriores), 'sem a regra de variar, repete o que o gerador já escolheria');
    }

    public function test_mudou_abre_o_pedido_ja_preenchido_para_ajustar_so_o_que_mudou(): void
    {
        $this->faseGerada();

        Livewire::test(GymPlanGenerator::class)->call('changeGoals')
            ->assertSet('step', 'form')->assertSet('goal', 'strength')->assertSet('days', 3)
            ->set('goal', 'weight_loss')->call('generate')->assertSet('step', 'draft');
    }

    public function test_fase_montada_a_mao_nao_tem_o_que_manter_e_cai_no_pedido(): void
    {
        $this->fase(9, null, null);

        Livewire::test(GymPlanGenerator::class)
            ->assertSet('step', 'review')->assertSee('Qual é o seu objetivo agora?')->assertDontSee('Sim, manter e montar o treino novo')
            ->call('keepGoals')->assertSet('step', 'form');
    }

    public function test_aceitar_a_sugestao_de_nivel_sobe_o_nivel_do_treino_novo(): void
    {
        $fase = $this->faseGerada();
        $treino = $fase->workouts()->firstOrFail();
        $inicio = CarbonImmutable::today()->subMonths(8);

        foreach (range(0, 44) as $i) {
            GymSession::create([
                'workout_id' => $treino->id, 'performed_on' => $inicio->addDays($i * 5)->toDateString(),
                'started_at' => $inicio->addDays($i * 5)->setTime(18, 0), 'finished_at' => $inicio->addDays($i * 5)->setTime(19, 0),
            ]);
        }

        Livewire::test(GymPlanGenerator::class)
            ->assertSee('Quer passar para')->assertSee('Intermediário')
            ->call('keepGoals')->assertSet('level', 'beginner')
            ->call('backToForm')->set('upgradeLevel', true)->call('keepGoals')->assertSet('level', 'intermediate');
    }

    public function test_salvar_o_treino_novo_encerra_a_fase_e_guarda_as_respostas_da_nova(): void
    {
        $antiga = $this->faseGerada();

        Livewire::test(GymPlanGenerator::class)->call('keepGoals')->set('draft.name', 'Fase 2')->call('save')
            ->assertRedirect(route('health.gym.index'));

        $nova = app(GymPlanService::class)->activePlan();
        self::assertSame('Fase 2', $nova->name);
        self::assertFalse($antiga->fresh()->is_active);
        self::assertSame(GymGoal::Strength, $nova->goal, 'a fase nova também guarda o objetivo, para o próximo aviso');
    }

    private function treinarBemTodosOsTreinos(GymPlan $fase): void
    {
        $treino = $fase->workouts()->with('workoutExercises')->orderBy('position')->firstOrFail();

        foreach (range(0, 5) as $i) {
            $dia = CarbonImmutable::today()->subDays(20 - $i * 3);
            $sessao = GymSession::create([
                'workout_id' => $treino->id, 'performed_on' => $dia->toDateString(),
                'started_at' => $dia->setTime(18, 0), 'finished_at' => $dia->setTime(19, 0),
            ]);

            foreach ($treino->workoutExercises as $item) {
                GymSetLog::create([
                    'session_id' => $sessao->id, 'exercise_id' => $item->exercise_id, 'set_number' => 1, 'reps' => 8,
                    'load_value' => (string) (30 + $i * 2.5), 'load_mode' => GymLoadMode::Total, 'completed_at' => $dia->setTime(18, 5),
                ]);
            }
        }
    }
}
