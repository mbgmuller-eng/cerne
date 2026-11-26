<?php

namespace Tests\Feature;

use App\Enums\ActivityRecordStatus;
use App\Enums\ConsultantClientStatus;
use App\Enums\GymEquipmentAccess;
use App\Enums\GymGoal;
use App\Enums\GymLevel;
use App\Enums\GymMeasureType;
use App\Enums\GymMuscleGroup;
use App\Enums\GymSport;
use App\Enums\HealthActivityKind;
use App\Enums\LimitationStatus;
use App\Enums\MemberRole;
use App\Enums\MovementLoad;
use App\Enums\ProfileType;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Livewire\Activities\ActivityConsultation;
use App\Livewire\Activities\ActivityLog;
use App\Livewire\Activities\ActivityProfilePage;
use App\Livewire\Activities\ActivityReview;
use App\Livewire\Activities\ActivityRunning;
use App\Livewire\Activities\ActivityWeek;
use App\Livewire\Health\Gym\GymPlanGenerator;
use App\Livewire\Health\LimitationPanel;
use App\Models\ActivityRecord;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\GymExerciseCaution;
use App\Models\GymPlan;
use App\Models\GymPlanAdjustment;
use App\Models\GymSession;
use App\Models\GymSetLog;
use App\Models\GymWorkout;
use App\Models\GymWorkoutExercise;
use App\Models\HealthActivity;
use App\Models\HealthActivityExercise;
use App\Models\HealthFollowup;
use App\Models\PhysicalLimitation;
use App\Models\ProfileMember;
use App\Models\RunningProgram;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Activities\ActivityProgressService;
use App\Services\Activities\ActivityService;
use App\Services\Activities\ActivityWeekService;
use App\Services\Activities\LimitationService;
use App\Services\Activities\PlanReviewService;
use App\Services\Activities\RunningService;
use App\Services\GymPlanService;
use App\Services\GymWorkoutGenerator;
use App\Services\HealthActivityService;
use App\Services\HealthFollowupService;
use App\Support\GymGeneratorInput;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Database\Seeders\GymExerciseCatalogSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cerne Saúde › Atividades: a semana, o diário, as limitações vindas da Saúde, a revisão do treino e a corrida. Atividades e programas são só
 * do dono; a limitação tem a visibilidade da Agenda. O Cerne tira ou adapta por precaução e só acrescenta o que um profissional indicou.
 */
class ActivitiesTest extends TestCase
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
        $this->membroAna = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->ana->id, 'name' => 'Ana Teste', 'role' => MemberRole::Primary]);
        $this->membroJoao = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->joao->id, 'name' => 'João Teste', 'role' => MemberRole::Secondary]);

        Subscription::create([
            'user_id' => $this->ana->id, 'kind' => SubscriptionKind::Direct, 'bundle' => SubscriptionBundle::Completo, 'status' => SubscriptionStatus::Active,
            'current_period_ends_at' => now()->addMonth(), 'started_at' => now(),
        ]);

        $this->entrarComoAna();
    }

    // --- Semana ---------------------------------------------------------------------------------

    public function test_a_semana_junta_compromisso_fixo_e_treino_nos_dias_preferidos(): void
    {
        $this->rotinaDaAna();

        $semana = $this->semana();

        $this->assertSame(
            [[0, 'Academia: Treino A'], [1, 'Tênis'], [2, 'Academia: Treino B'], [4, 'Academia: Treino C']],
            $semana['items']->map(fn (array $i) => [$i['weekday'], $i['name']])->all(),
        );
        $this->assertSame([['name' => 'Tênis', 'expected' => 2, 'count' => 0]], $semana['flexible']);
        $this->assertSame([], $semana['warnings']);
        // Nada é gravado só por olhar a semana.
        $this->assertSame(0, ActivityRecord::query()->count());
    }

    public function test_fiz_nao_fiz_e_desfazer_nao_duplicam(): void
    {
        $this->rotinaDaAna();
        $semanas = app(ActivityWeekService::class);
        $inicio = CarbonImmutable::parse('2026-11-23');

        $tenis = $this->item('Tênis');
        $semanas->confirm($inicio, $tenis['ref'], 70, 7, 2);

        $this->assertSame(1, $this->semana()['items']->where('name', 'Tênis')->count());
        $this->assertSame(490, $this->semana()['load_done']);

        // Sexta ainda não chegou: não dá para dizer que fez.
        try {
            $semanas->confirm($inicio, $this->item('Academia: Treino C')['ref'], 50, 6, 1);
            $this->fail('Aceitou um dia que não chegou.');
        } catch (DomainException) {
        }

        $semanas->skip($inicio, $this->item('Academia: Treino C')['ref']);
        $pulado = $this->semana()['items']->firstWhere('status', 'skipped');
        $this->assertNotNull($pulado);
        // O treino pulado ocupa a vaga: não aparece outro previsto no lugar.
        $this->assertSame(0, $this->semana()['items']->where('weekday', 4)->where('status', 'planned')->count());

        $semanas->undoSkip($pulado['record_id']);
        $this->assertSame(1, $this->semana()['items']->where('name', 'Academia: Treino C')->where('status', 'planned')->count());
    }

    public function test_mudar_o_dia_vale_para_a_semana_e_avisa_pernas_na_vespera_do_esporte(): void
    {
        $this->rotinaDaAna();
        $semanas = app(ActivityWeekService::class);
        $inicio = CarbonImmutable::parse('2026-11-23');

        $semanas->move($inicio, $this->item('Academia: Treino B')['ref'], 0);

        $this->assertSame(0, $this->item('Academia: Treino B')['weekday']);
        $this->assertStringContainsString('véspera de um esporte', implode(' ', $this->semana()['warnings']));
        // A semana seguinte continua como o perfil pede.
        $this->assertSame(2, $semanas->week($inicio->addWeek())['items']->first(fn (array $i) => $i['legs'])['weekday']);
    }

    public function test_treino_terminado_na_academia_entra_uma_vez_so_no_diario(): void
    {
        $this->rotinaDaAna();
        $treinoA = GymWorkout::query()->where('name', 'Treino A')->firstOrFail();
        GymSession::create(['workout_id' => $treinoA->id, 'performed_on' => '2026-11-23', 'started_at' => '2026-11-23 18:00', 'finished_at' => '2026-11-23 18:50']);

        $this->semana();
        $this->semana();

        $registro = ActivityRecord::query()->sole();
        $this->assertSame(50, $registro->minutes);
        $this->assertNull($registro->effort);
        $this->assertSame(1, $this->semana()['missing_effort']);
        // O rodízio segue: o que falta na semana é B e C.
        $this->assertSame(['Academia: Treino B', 'Academia: Treino C'], $this->semana()['items']->where('status', 'planned')->filter(fn (array $i) => $i['workout_id'] !== null)->pluck('name')->values()->all());

        // O registro da academia não se apaga pelo diário.
        $this->expectException(DomainException::class);
        app(ActivityService::class)->delete($registro);
    }

    public function test_quem_ja_disse_fiz_nao_ganha_segunda_linha_quando_a_sessao_chega(): void
    {
        $this->rotinaDaAna();
        app(ActivityWeekService::class)->confirm(CarbonImmutable::parse('2026-11-23'), $this->item('Academia: Treino A')['ref'], 55, 6, null);

        $treinoA = GymWorkout::query()->where('name', 'Treino A')->firstOrFail();
        $sessao = GymSession::create(['workout_id' => $treinoA->id, 'performed_on' => '2026-11-23', 'started_at' => '2026-11-23 18:00', 'finished_at' => '2026-11-23 18:50']);
        $this->semana();

        $registro = ActivityRecord::query()->sole();
        $this->assertSame($sessao->id, $registro->gym_session_id);
        $this->assertSame(55, $registro->minutes);
    }

    public function test_alerta_de_carga_so_com_duas_semanas_de_base(): void
    {
        $this->registrar('2026-11-17', 60, 5); // 300
        $this->registrar('2026-11-25', 120, 8); // 960 na semana atual

        $this->assertNull($this->semana()['load_average']);

        $this->registrar('2026-11-10', 60, 5);
        $semana = $this->semana();

        $this->assertSame(300, $semana['load_average']);
        $this->assertSame(220, $semana['load_above']);

        $evolucao = app(ActivityProgressService::class)->weekly();
        $this->assertSame(960, $evolucao['weeks'][7]['load']['sport']);
    }

    // --- Diário ---------------------------------------------------------------------------------

    public function test_registro_repetido_pede_confirmacao(): void
    {
        $this->registrar('2026-11-26', 60, 6);

        $tela = Livewire::test(ActivityLog::class)->set('type', 'tennis')->set('date', '2026-11-26')->set('minutes', '90')->call('save')
            ->assertSet('confirmDuplicate', true);
        $this->assertSame(1, ActivityRecord::query()->count());

        $tela->call('save');
        $this->assertSame(2, ActivityRecord::query()->count());
    }

    public function test_varios_dias_de_uma_vez_deixam_de_fora_o_que_ja_estava_no_diario(): void
    {
        $this->registrar('2026-11-17', 60, 6);

        $tela = Livewire::test(ActivityLog::class)->call('setMode', 'many')->set('type', 'tennis')->set('minutes', '60')
            ->set('from', '2026-11-01')->set('until', '2026-11-26')->set('weekdays', [1])->call('buildDays');

        $this->assertSame(['2026-11-03' => true, '2026-11-10' => true, '2026-11-17' => false, '2026-11-24' => true], $tela->get('days'));

        // Uma data forjada pelo navegador não entra.
        $tela->call('toggleDay', '2026-11-03')->set('days', ['2026-11-10' => true, '2026-11-24' => true, '2030-01-01' => true])->call('saveDays');

        $this->assertSame(['2026-11-10', '2026-11-17', '2026-11-24'], ActivityRecord::query()->orderBy('done_on')->get()->map(fn ($r) => $r->done_on->toDateString())->all());
    }

    public function test_perfil_guarda_objetivos_em_ordem_e_cifra_o_texto(): void
    {
        Livewire::test(ActivityProfilePage::class)
            ->set('newGoal', 'Voltar a correr')->call('addGoal')->set('newGoal', 'Jogar sem dor')->call('addGoal')->call('moveGoal', 1)
            ->set('gymDays', ['4', '0'])->call('save')->assertHasNoErrors();

        $perfil = app(ActivityService::class)->profile();
        $this->assertSame(['Jogar sem dor', 'Voltar a correr'], $perfil->goals);
        $this->assertSame([0, 4], $perfil->gym_days);
        $this->assertStringNotContainsString('correr', (string) DB::table('activity_profiles')->value('goals'));
    }

    // --- Privacidade ----------------------------------------------------------------------------

    public function test_conjuge_e_consultor_nao_veem_atividades_e_a_limitacao_segue_a_agenda(): void
    {
        $this->rotinaDaAna();
        $this->registrar('2026-11-24', 60, 6);
        $this->limitacao();

        app(ProfileContext::class)->set($this->perfil, $this->membroJoao);
        $this->actingAs($this->joao);

        $this->assertSame(0, ActivityRecord::query()->count());
        $this->assertSame(0, GymPlan::query()->count());
        // Vê a limitação (é a visibilidade da Agenda), mas ela não mexe nos programas dele.
        $this->assertSame(1, PhysicalLimitation::query()->count());
        $this->assertCount(0, app(PlanReviewService::class)->considered());

        $this->actingAs($this->consultorDaAna());
        app(ProfileContext::class)->set($this->perfil, null, asConsultant: true);

        $this->assertSame(0, PhysicalLimitation::query()->count());
        Livewire::test(ActivityWeek::class)->assertForbidden();
    }

    public function test_as_telas_abrem_e_o_menu_marca_atividades_tambem_na_academia(): void
    {
        $this->rotinaDaAna();
        $this->limitacao();
        $this->sair();
        $this->actingAs($this->ana);

        foreach (['index', 'log', 'profile', 'review', 'consultation', 'running', 'progress'] as $tela) {
            $this->get(route('health.activities.'.$tela))->assertOk()->assertSee('data-abas-atividades', false);
        }

        $this->assertMatchesRegularExpression('/data-secao="perfil-saude"\s+data-ativa/', $this->get(route('health.gym.index'))->assertOk()->getContent());
    }

    public function test_a_folha_em_pdf_e_so_da_propria_pessoa(): void
    {
        $this->rotinaDaAna();
        $this->sair();

        $this->actingAs($this->ana)->get(route('health.activities.sheet'))->assertOk()->assertHeader('content-type', 'application/pdf');

        $consultor = $this->consultorDaAna();
        $this->sair();
        $this->actingAs($consultor)->withSession(['cerne.active_profile_id' => $this->perfil->id])->get(route('health.activities.sheet'))->assertForbidden();
    }

    // --- Limitações e revisão do treino ---------------------------------------------------------

    public function test_em_investigacao_so_sugere_pausar_e_nada_e_acrescentado(): void
    {
        $this->rotinaDaAna();
        $limitacao = $this->limitacao();
        // Mesmo com exercícios já digitados no programa, em investigação nada entra.
        $this->programa($limitacao, 'Ponte curta', '3', '12');

        $s = app(PlanReviewService::class)->suggestions();
        $nomes = collect($s['items'])->pluck('name')->sort()->values()->all();

        $this->assertSame(['Agachamento livre', 'Remada curvada', 'Rotação do tronco no cabo', 'Stiff'], $nomes);
        foreach ($s['items'] as $item) {
            $this->assertSame(['pause', 'keep'], $item['actions']);
            $this->assertSame('pause', $item['default']);
        }
        $this->assertSame([], $s['additions']);
    }

    public function test_pausar_guarda_o_item_e_a_liberacao_o_devolve_no_lugar_sem_mexer_no_historico(): void
    {
        $this->rotinaDaAna();
        $limitacao = $this->limitacao();
        $treinoB = GymWorkout::query()->where('name', 'Treino B')->firstOrFail();
        $antes = $this->itensDe($treinoB);

        $sessao = GymSession::create(['workout_id' => $treinoB->id, 'performed_on' => '2026-11-18', 'started_at' => '2026-11-18 18:00', 'finished_at' => '2026-11-18 19:00']);
        $stiff = GymWorkoutExercise::query()->where('workout_id', $treinoB->id)->with('exercise')->get()->first(fn ($i) => $i->exercise->name === 'Stiff');
        GymSetLog::create(['session_id' => $sessao->id, 'exercise_id' => $stiff->exercise_id, 'set_number' => 1, 'reps' => 10, 'completed_at' => '2026-11-18 18:30']);

        $feito = app(PlanReviewService::class)->apply([]);

        $this->assertSame(4, $feito['paused']);
        $this->assertSame(['Afundo', 'Elevação pélvica'], array_column($this->itensDe($treinoB), 0));
        $this->assertSame(1, GymSetLog::query()->count());
        $this->assertSame(0, app(PlanReviewService::class)->pendingCount());

        $limitacao->update(['status' => LimitationStatus::Released]);
        $s = app(PlanReviewService::class)->suggestions();
        $this->assertCount(4, $s['restores']);

        app(PlanReviewService::class)->apply([]);

        $this->assertSame($antes, $this->itensDe($treinoB));
        $this->assertSame(0, GymPlanAdjustment::query()->whereNull('reverted_at')->count());
    }

    public function test_manter_por_escolha_nao_volta_a_sugerir(): void
    {
        $this->rotinaDaAna();
        $this->limitacao();
        $servico = app(PlanReviewService::class);
        $decisoes = array_map(fn () => ['action' => 'keep'], $servico->suggestions()['items']);

        $servico->apply($decisoes);

        $this->assertSame(0, $servico->pendingCount());
        $this->assertSame(4, GymExerciseCaution::query()->where('mark', 'kept')->count());
        $this->assertSame(10, GymWorkoutExercise::query()->count());
    }

    public function test_troca_em_investigacao_e_ignorada_e_vira_o_sugerido(): void
    {
        $this->rotinaDaAna();
        $this->limitacao();
        $servico = app(PlanReviewService::class);
        $chave = array_key_first($servico->suggestions()['items']);

        $feito = $servico->apply([$chave => ['action' => 'swap', 'swap' => 'catalog:qualquer']]);

        $this->assertSame(0, $feito['swapped']);
        $this->assertSame(4, $feito['paused']);
    }

    public function test_com_orientacao_aceita_troca_so_da_lista_e_acrescenta_o_indicado_no_comeco(): void
    {
        $this->rotinaDaAna();
        $limitacao = $this->limitacao();
        $remada = $this->exercicioDoPlano('Remada curvada');

        app(LimitationService::class)->registerConsultation($limitacao, [
            'marks' => [$remada->id => 'adapt'], 'running' => 'not_yet', 'sports' => ['tennis' => 'care'],
            'exercises' => [['name' => 'Exercício indicado 1', 'sets' => '3', 'reps' => '30 s', 'per_week' => 2]],
        ], $this->membroAna);

        $limitacao->refresh();
        $this->assertSame(LimitationStatus::Guided, $limitacao->status);
        $this->assertSame('not_yet', $limitacao->runningAnswer());
        $this->assertSame(['tennis' => 'care'], $limitacao->sportAnswers());
        $this->assertSame(LimitationService::ACTIVITY_NAME, HealthActivity::query()->sole()->name);

        $servico = app(PlanReviewService::class);
        $s = $servico->suggestions();
        $daRemada = collect($s['items'])->first(fn (array $i) => $i['name'] === 'Remada curvada');
        $this->assertSame('keep', $daRemada['default']);
        $this->assertSame(['pause', 'swap', 'keep'], $daRemada['actions']);
        $this->assertCount(1, $s['additions']);

        $item = GymWorkoutExercise::query()->findOrFail($daRemada['item_id']);
        try {
            $servico->apply(['i:'.$item->id => ['action' => 'swap', 'swap' => 'catalog:00000000-0000-0000-0000-000000000000']]);
            $this->fail('Aceitou uma troca fora da lista.');
        } catch (DomainException) {
        }
        // A recusa desfaz tudo: nada foi pausado.
        $this->assertSame(10, GymWorkoutExercise::query()->count());

        $opcoes = $servico->replacements($item);
        $this->assertNotEmpty($opcoes);
        foreach ($opcoes as $o) {
            $this->assertSame([], MovementLoad::hits($o['name'], $limitacao->avoidLoads()), $o['name']);
        }

        $feito = $servico->apply(['i:'.$item->id => ['action' => 'swap', 'swap' => $opcoes[0]['key']]]);

        $this->assertSame(1, $feito['swapped']);
        $this->assertSame(2, $feito['added']);
        $treinoA = GymWorkout::query()->where('name', 'Treino A')->firstOrFail();
        $primeiro = GymWorkoutExercise::query()->where('workout_id', $treinoA->id)->orderBy('position')->with('exercise')->first();
        $this->assertSame('Exercício indicado 1', $primeiro->exercise->name);
        $this->assertSame(30, $primeiro->target_duration_seconds);
        $this->assertSame(3, $primeiro->target_sets);
        $this->assertStringContainsString('Indicado pelo profissional', $primeiro->notes);
        // Já entrou: não é oferecido de novo.
        $this->assertSame([], $servico->suggestions()['additions']);
    }

    public function test_quando_passa_do_tempo_tira_uma_serie_fora_do_foco_do_esporte(): void
    {
        $this->rotinaDaAna();
        GymPlan::query()->update(['sport' => GymSport::Racket->value]);
        app(ActivityService::class)->saveProfile([], [0, 2, 4], 20, true);
        $limitacao = $this->limitacao(['status' => 'guided', 'avoid' => [], 'region' => 'hip']);
        $this->programa($limitacao, 'Exercício indicado 1', '3', '12', 1);

        $feito = app(PlanReviewService::class)->apply([]);

        $this->assertGreaterThan(0, $feito['reduced']);
        $reduzidos = GymPlanAdjustment::query()->where('kind', 'fewer_sets')->with('exercise')->get()->map(fn ($a) => $a->exercise->name);
        // Afundo é foco de esporte de raquete: fica com todas as séries.
        $this->assertNotContains('Afundo', $reduzidos->all());
        $this->assertNotContains('Exercício indicado 1', $reduzidos->all());
    }

    public function test_treino_em_andamento_segura_a_revisao(): void
    {
        $this->rotinaDaAna();
        $this->limitacao();
        GymSession::create(['workout_id' => GymWorkout::query()->where('name', 'Treino A')->value('id'), 'performed_on' => '2026-11-26', 'started_at' => now()]);

        $this->expectException(DomainException::class);
        app(PlanReviewService::class)->apply([]);
    }

    public function test_a_dose_escrita_vira_os_campos_do_treino(): void
    {
        foreach ([
            '3 × 12' => [3, 12, 12, null], '4x8 a 10' => [4, 8, 10, null], '3 × 30 s' => [3, null, null, 30],
            '2 × 1 min' => [2, null, null, 60], '' => [3, 10, 10, null], 'como orientado' => [3, 10, 10, null],
        ] as $dose => [$series, $min, $max, $segundos]) {
            [$meta] = PlanReviewService::parseDose((string) $dose);
            $this->assertSame([$series, $min, $max, $segundos], [$meta['target_sets'], $meta['target_reps_min'], $meta['target_reps_max'], $meta['target_duration_seconds']], (string) $dose);
        }
    }

    public function test_a_tela_de_revisao_aplica_o_que_foi_escolhido(): void
    {
        $this->rotinaDaAna();
        $this->limitacao();
        $chaves = array_keys(app(PlanReviewService::class)->suggestions()['items']);

        Livewire::test(ActivityReview::class)->assertSee('só sugere')
            ->set('decisions.'.ActivityReview::key($chaves[0]).'.action', 'keep')
            ->call('apply')->assertHasNoErrors()->assertSee('Treino atualizado');

        $this->assertSame(3, GymPlanAdjustment::query()->count());
        $this->assertSame(1, GymExerciseCaution::query()->count());
    }

    public function test_o_treino_automatico_deixa_de_fora_o_que_a_saude_pede_e_nao_grava_isso_na_fase(): void
    {
        $this->seed(GymExerciseCatalogSeeder::class);
        $this->limitacao();
        $limites = app(PlanReviewService::class)->generatorLimits();

        $rascunho = app(GymWorkoutGenerator::class)->generate(new GymGeneratorInput(
            GymGoal::Hypertrophy, GymLevel::Intermediate, 3, 60, GymEquipmentAccess::FullGym,
            limitationRestrictions: $limites['restrictions'], avoidLoads: $limites['avoid'], excludeNames: $limites['exclude'],
        ));
        $nomes = collect($rascunho['workouts'])->flatMap(fn (array $t) => array_column($t['exercises'], 'name'));

        $this->assertGreaterThan(8, $nomes->count());
        foreach ($nomes as $nome) {
            $this->assertSame([], MovementLoad::hits($nome, $limites['avoid']), $nome);
        }
        // A limitação não vira "região protegida" da fase: liberada, ela deixa de valer.
        $this->assertSame([], $rascunho['input']['restrictions']);

        Livewire::test(GymPlanGenerator::class)->assertSee('Encontrado na sua Saúde')->assertSee('Lombar')
            ->call('generate')->assertSee('Considerei o que está na sua Saúde');
    }

    public function test_desligar_no_perfil_para_de_considerar(): void
    {
        $this->rotinaDaAna();
        $this->limitacao();
        app(ActivityService::class)->saveProfile([], [0, 2, 4], 60, false);

        $this->assertSame(0, app(PlanReviewService::class)->pendingCount());
        $this->assertNull(app(RunningService::class)->block());
    }

    // --- Limitação na Saúde ---------------------------------------------------------------------

    public function test_painel_da_limitacao_grava_cifrado_e_sugere_o_que_evitar_pela_regiao(): void
    {
        $acompanhamento = $this->acompanhamento();

        Livewire::test(LimitationPanel::class, ['followupId' => $acompanhamento->id])
            ->call('create')->assertSet('avoid', ['impact', 'spinal_load', 'rotation', 'flexion'])
            ->set('region', 'shoulder')->assertSet('avoid', ['overhead'])
            ->set('guidance', 'Sem carga alta por enquanto')->call('save')->assertHasNoErrors();

        $limitacao = PhysicalLimitation::query()->sole();
        $this->assertSame('Ombro', $limitacao->regionLabel());
        $linha = DB::table('physical_limitations')->first();
        foreach (['region', 'avoid', 'guidance'] as $coluna) {
            $this->assertStringNotContainsString('shoulder', (string) $linha->{$coluna});
            $this->assertStringNotContainsString('overhead', (string) $linha->{$coluna});
            $this->assertStringNotContainsString('carga alta', (string) $linha->{$coluna});
        }
    }

    public function test_o_atalho_para_o_treino_so_aparece_para_quem_tem_a_area_atividades(): void
    {
        $limitacao = $this->limitacao();

        Livewire::test(LimitationPanel::class, ['followupId' => $limitacao->followup_id])->assertSeeHtml('data-revisar-treino');

        // O cônjuge vê a limitação, mas o treino não é dele.
        app(ProfileContext::class)->set($this->perfil, $this->membroJoao);
        Livewire::test(LimitationPanel::class, ['followupId' => $limitacao->followup_id])->assertSee('Lombar')->assertDontSeeHtml('data-revisar-treino');

        // No espaço de uma pessoa acompanhada não há Atividades, mesmo para ela mesma.
        $this->perfil->forceFill(['profile_type' => ProfileType::Care])->save();
        app(ProfileContext::class)->set($this->perfil, $this->membroAna);
        Livewire::test(LimitationPanel::class, ['followupId' => $limitacao->followup_id])->assertSee('Lombar')->assertDontSeeHtml('data-revisar-treino');
    }

    public function test_a_politica_e_o_consentimento_citam_o_diario_e_as_limitacoes(): void
    {
        $this->sair();
        $this->get(route('legal.privacy'))->assertOk()->assertSee('limitações físicas', false)->assertSee('diário', false)->assertSee('Médicos e profissionais:', false);

        $semConsentimento = User::factory()->create(['health_consent_at' => null, 'health_consent_version' => null]);
        $this->assertFalse($semConsentimento->hasCurrentHealthConsent());
        $this->assertStringContainsString('diário de atividades físicas', view('legal.health-consent', ['errors' => new \Illuminate\Support\ViewErrorBag])->render());
    }

    public function test_a_consulta_de_outra_pessoa_nao_se_registra(): void
    {
        $limitacao = $this->limitacao();
        app(ProfileContext::class)->set($this->perfil, $this->membroJoao);

        $this->expectException(DomainException::class);
        app(LimitationService::class)->registerConsultation($limitacao, [], $this->membroJoao);
    }

    public function test_a_tela_da_consulta_registra_e_leva_para_a_revisao(): void
    {
        $this->rotinaDaAna();
        $limitacao = $this->limitacao();
        $stiff = $this->exercicioDoPlano('Stiff');

        Livewire::test(ActivityConsultation::class)->assertSet('limitationId', $limitacao->id)
            ->set('marks.'.$stiff->id, 'avoid')->set('running', 'yes')
            ->set('exName', 'Exercício indicado 1')->set('exSets', '3')->set('exReps', '10')
            ->call('save')->assertHasNoErrors()->assertRedirect(route('health.activities.review'));

        $this->assertSame('yes', $limitacao->fresh()->runningAnswer());
        $this->assertSame(1, HealthActivityExercise::query()->count());
        $this->assertSame('avoid', GymExerciseCaution::query()->sole()->mark->value);
    }

    // --- Corrida --------------------------------------------------------------------------------

    public function test_corrida_recomenda_aguardar_e_guarda_a_escolha_de_comecar_assim_mesmo(): void
    {
        $this->limitacao();
        $servico = app(RunningService::class);

        $this->assertStringContainsString('Recomendo aguardar', $servico->block());
        try {
            $servico->start(1, 2, [4, 6], 0);
            $this->fail('Começou sem a escolha.');
        } catch (DomainException) {
        }

        $programa = $servico->start(1, 2, [4, 6], 0, mesmoAssim: true);
        $this->assertNotNull($programa->chose_despite_limitation_at);
        $this->assertSame(1, $programa->step);

        PhysicalLimitation::query()->update(['status' => 'released']);
        $this->assertNull($servico->block());
        $this->assertNull($servico->start(2, 2, [4, 6], 0)->chose_despite_limitation_at);
        $this->assertSame(1, RunningProgram::query()->where('is_active', true)->count());
    }

    public function test_a_semana_da_corrida_fecha_uma_vez_e_a_dor_maior_volta_um_passo(): void
    {
        $servico = app(RunningService::class);
        $programa = $servico->start(2, 2, [1, 3], 1);
        $semanas = app(ActivityWeekService::class);
        $inicio = CarbonImmutable::parse('2026-11-23');

        foreach ($semanas->week($inicio)['items']->filter(fn (array $i) => $i['running']) as $sessao) {
            $semanas->confirm($inicio, $sessao['ref'], 30, 4, 1);
        }

        $fechar = $servico->weekToClose($programa);
        $this->assertSame('advance', $fechar['suggestion']);

        $servico->closeWeek($programa, 'advance');
        $this->assertSame(4, $programa->fresh()->step);
        $this->assertNull($servico->weekToClose($programa->fresh()));

        try {
            $servico->closeWeek($programa->fresh(), 'advance');
            $this->fail('Fechou a mesma semana duas vezes.');
        } catch (DomainException) {
        }
        $this->assertSame(4, $programa->fresh()->step);

        ActivityRecord::query()->update(['pain' => 5]);
        $this->assertSame('back', $servico->weekSummary($programa->fresh(), $inicio)['suggestion']);
    }

    public function test_a_tela_da_corrida_so_monta_com_a_escolha_marcada(): void
    {
        $this->limitacao();

        Livewire::test(ActivityRunning::class)->assertSee('Recomendo aguardar')->assertDontSee('Montar programa')
            ->set('anyway', true)->set('weekdays', ['4', '6'])->call('start')->assertHasNoErrors()->assertSee('Você está aqui');
    }

    public function test_tirar_a_conta_leva_tudo_junto(): void
    {
        $this->rotinaDaAna();
        $this->limitacao();
        app(PlanReviewService::class)->apply([]);
        app(RunningService::class)->start(1, 2, [4, 6], 0, mesmoAssim: true);
        $this->registrar('2026-11-24', 60, 6);

        $this->sair();
        app(\App\Services\Privacy\AccountDeletionService::class)->delete($this->ana->fresh());

        foreach (['activity_profiles', 'activity_commitments', 'activity_records', 'physical_limitations', 'gym_plan_adjustments', 'running_programs'] as $tabela) {
            $this->assertSame(0, DB::table($tabela)->count(), $tabela);
        }
    }

    // --- Apoio ----------------------------------------------------------------------------------

    private function consultorDaAna(): User
    {
        $consultor = User::factory()->consultant()->create();
        ConsultantClient::factory()->create(['consultant_id' => $consultor->id, 'client_id' => $this->ana->id, 'status' => ConsultantClientStatus::Active]);

        return $consultor;
    }

    private function entrarComoAna(): void
    {
        $this->actingAs($this->ana);
        app(ProfileContext::class)->set($this->perfil, $this->membroAna);
    }

    /** Rota de verdade monta o contexto pelo middleware: o que o teste montou à mão não pode sobrar. */
    private function sair(): void
    {
        auth()->logout();
        app(ProfileContext::class)->clear();
    }

    /** Tênis fixo na terça, tênis sem dia certo, academia segunda, quarta e sexta, com A (superior), B (pernas) e C. */
    private function rotinaDaAna(): void
    {
        $atividades = app(ActivityService::class);
        $atividades->saveCommitment(['activity' => 'tennis', 'weekday' => 1, 'minutes' => 60, 'effort' => 6]);
        $atividades->saveCommitment(['activity' => 'tennis', 'times_per_week' => 2, 'minutes' => 90, 'effort' => 7]);
        $atividades->saveProfile(['Jogar sem dor'], [0, 2, 4], 60, true);

        $planos = app(GymPlanService::class);
        $plano = $planos->createPlan('Fase tênis');

        foreach ([
            'Treino A' => [['Puxada frontal', GymMuscleGroup::Back], ['Remada curvada', GymMuscleGroup::Back], ['Rotação do tronco no cabo', GymMuscleGroup::Core]],
            'Treino B' => [['Agachamento livre', GymMuscleGroup::Legs], ['Stiff', GymMuscleGroup::Legs], ['Afundo', GymMuscleGroup::Legs], ['Elevação pélvica', GymMuscleGroup::Glutes]],
            'Treino C' => [['Supino reto', GymMuscleGroup::Chest], ['Remada baixa', GymMuscleGroup::Back], ['Dead bug', GymMuscleGroup::Core]],
        ] as $nome => $exercicios) {
            $treino = $planos->addWorkout($plano, $nome, null);

            foreach ($exercicios as [$exercicio, $grupo]) {
                $planos->addExercise($treino, $planos->findOrCreateExercise($exercicio, $grupo, GymMeasureType::LoadReps), ['target_sets' => 3, 'target_reps_min' => 10, 'target_reps_max' => 12, 'rest_seconds' => 90]);
            }
        }
    }

    /** @return list<array{0: string, 1: int, 2: int}> */
    private function itensDe(GymWorkout $treino): array
    {
        return GymWorkoutExercise::query()->where('workout_id', $treino->id)->with('exercise')->orderBy('position')->get()
            ->map(fn (GymWorkoutExercise $i) => [$i->exercise->name, $i->position, $i->target_sets])->all();
    }

    private function exercicioDoPlano(string $nome): \App\Models\GymExercise
    {
        return \App\Models\GymExercise::query()->where('name', $nome)->firstOrFail();
    }

    private function acompanhamento(): HealthFollowup
    {
        return app(HealthFollowupService::class)->create($this->membroAna, ['title' => 'Dor nas costas', 'status' => 'investigating', 'started_on' => '2026-11-20'], $this->membroAna);
    }

    /** @param  array<string, mixed>  $dados */
    private function limitacao(array $dados = []): PhysicalLimitation
    {
        return app(LimitationService::class)->save($this->acompanhamento(), $dados + [
            'region' => 'lower_back', 'status' => 'investigating', 'avoid' => ['impact', 'spinal_load', 'rotation', 'flexion'],
        ], $this->membroAna);
    }

    private function programa(PhysicalLimitation $limitacao, string $nome, string $series, string $reps, int $vezes = 2): void
    {
        $servico = app(HealthActivityService::class);
        $atividade = $servico->create($limitacao->followup, ['kind' => HealthActivityKind::Home->value, 'per_week' => $vezes], $this->membroAna);
        $servico->addExercise($atividade, $nome, $series, $reps, null, $this->membroAna);
    }

    private function registrar(string $data, int $minutos, int $esforco): ActivityRecord
    {
        return app(ActivityService::class)->record(['activity' => 'tennis', 'done_on' => $data, 'minutes' => $minutos, 'effort' => $esforco]);
    }

    /** @return array<string, mixed> */
    private function semana(): array
    {
        return app(ActivityWeekService::class)->week(CarbonImmutable::parse('2026-11-23'));
    }

    /** @return array<string, mixed> */
    private function item(string $nome): array
    {
        $item = $this->semana()['items']->firstWhere('name', $nome);
        $this->assertNotNull($item, $nome);

        return $item;
    }
}
