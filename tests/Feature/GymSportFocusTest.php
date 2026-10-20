<?php

namespace Tests\Feature;

use App\Enums\GymEquipmentAccess;
use App\Enums\GymGoal;
use App\Enums\GymLevel;
use App\Enums\GymMeasureType;
use App\Enums\GymMuscleGroup;
use App\Enums\GymSeason;
use App\Enums\GymSport;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Livewire\Health\Gym\GymPlanGenerator;
use App\Models\FinancialProfile;
use App\Models\GymExerciseCatalog;
use App\Models\GymPlan;
use App\Models\ProfileMember;
use App\Models\Subscription;
use App\Models\User;
use App\Services\GymLibraryImporter;
use App\Services\GymPhaseReviewService;
use App\Services\GymPlanService;
use App\Services\GymWorkoutGenerator;
use App\Support\GymGeneratorInput;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Database\Seeders\GymExerciseCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Foco esportivo da academia: o esporte puxa exercícios, espaços extras e cuidados; a temporada limita dias e volume; o pedido volta
 * na revisão da fase ("seu esporte continua o mesmo?"). Usa o catálogo curado de verdade.
 */
class GymSportFocusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-20 12:00:00'));
        $this->seed(GymExerciseCatalogSeeder::class);

        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id]);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($perfil, $membro);
        Subscription::create([
            'user_id' => $usuario->id, 'kind' => SubscriptionKind::Direct,
            'bundle' => SubscriptionBundle::Completo, 'status' => SubscriptionStatus::Active, 'started_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function gerar(array $c = []): array
    {
        $c = array_merge([
            'goal' => GymGoal::Strength, 'level' => GymLevel::Intermediate, 'days' => 4, 'minutes' => 60, 'access' => GymEquipmentAccess::FullGym,
            'sport' => null, 'sportDays' => 0, 'season' => null, 'variant' => 0,
        ], $c);

        return app(GymWorkoutGenerator::class)->generate(new GymGeneratorInput(
            $c['goal'], $c['level'], $c['days'], $c['minutes'], $c['access'], [], $c['variant'], [], [], $c['sport'], $c['sportDays'], $c['season'],
        ));
    }

    /** @return list<array<string, mixed>> */
    private function linhas(array $plano): array
    {
        return collect($plano['workouts'])->flatMap(fn ($w) => $w['exercises'])->all();
    }

    private function nomes(array $plano): array
    {
        return array_column($this->linhas($plano), 'name');
    }

    // --- Os esportes ---------------------------------------------------------------------------------------------------------

    public function test_todo_esporte_tem_perfil_completo(): void
    {
        foreach (GymSport::cases() as $esporte) {
            self::assertNotSame('', $esporte->label());
            self::assertNotSame('', $esporte->shortLabel());
            self::assertNotEmpty($esporte->emphasis(), $esporte->value.' sem ênfase');
            self::assertCount(3, $esporte->extraSlots(), $esporte->value.' precisa de 3 espaços extras para revezar');
            self::assertNotEmpty($esporte->tips());
            self::assertInstanceOf(GymGoal::class, $esporte->defaultGoal());

            foreach ($esporte->extraSlots() as [$grupo, $especie]) {
                self::assertNotNull(GymMuscleGroup::tryFrom($grupo));
                self::assertContains($especie, ['any', 'compound', 'isolation', 'calf', 'biceps', 'triceps']);
            }
        }

        self::assertTrue(GymSport::Running->isEndurance());
        self::assertFalse(GymSport::Racket->isEndurance());
    }

    // --- O treino ------------------------------------------------------------------------------------------------------------

    public function test_sem_esporte_nada_muda(): void
    {
        $a = $this->gerar();
        $b = $this->gerar(['sport' => null, 'sportDays' => 5, 'season' => GymSeason::InSeason]);

        self::assertSame($this->nomes($a), $this->nomes($b), 'temporada sem esporte é ignorada');
        self::assertNull($a['notes']);
        self::assertNull($a['input']['sport']);
    }

    public function test_corrida_ganha_panturrilha_gluteo_e_perna_unilateral(): void
    {
        $comum = $this->nomes($this->gerar());
        $corrida = $this->nomes($this->gerar(['sport' => GymSport::Running, 'sportDays' => 3, 'season' => GymSeason::OffSeason]));

        $conta = fn (array $nomes, string $p) => count(array_filter($nomes, fn (string $n) => str_contains(mb_strtolower($n), $p)));

        self::assertGreaterThan($conta($comum, 'panturrilha'), $conta($corrida, 'panturrilha'), 'mais panturrilha que o treino comum');
        self::assertGreaterThanOrEqual(2, $conta($corrida, 'panturrilha'));
        self::assertGreaterThan($conta($comum, 'afundo') + $conta($comum, 'step'), $conta($corrida, 'afundo') + $conta($corrida, 'step'), 'mais perna unilateral');
    }

    public function test_o_tamanho_do_treino_nao_muda_so_o_que_entra(): void
    {
        $comum = $this->gerar();
        $esporte = $this->gerar(['sport' => GymSport::Soccer, 'sportDays' => 2, 'season' => GymSeason::OffSeason]);

        foreach ($comum['workouts'] as $i => $t) {
            self::assertCount(count($t['exercises']), $esporte['workouts'][$i]['exercises']);
        }
    }

    public function test_esporte_de_resistencia_nao_soma_cardio_e_o_outro_segue_a_regra_do_objetivo(): void
    {
        $semCardio = $this->gerar(['goal' => GymGoal::Endurance, 'sport' => GymSport::Running, 'sportDays' => 3, 'season' => GymSeason::OffSeason]);
        $comCardio = $this->gerar(['goal' => GymGoal::Endurance]);
        $raquete = $this->gerar(['goal' => GymGoal::Endurance, 'sport' => GymSport::Racket, 'sportDays' => 3, 'season' => GymSeason::OffSeason]);

        $cardio = fn (array $p) => count(array_filter($this->linhas($p), fn ($l) => $l['kind'] === 'cardio'));

        self::assertSame(0, $cardio($semCardio), 'correr já é o condicionamento');
        self::assertGreaterThan(0, $cardio($comCardio));
        self::assertGreaterThan(0, $cardio($raquete), 'raquete segue o objetivo');
    }

    public function test_em_competicao_poucos_dias_e_uma_serie_a_menos(): void
    {
        $fora = $this->gerar(['days' => 5, 'sport' => GymSport::Racket, 'sportDays' => 2, 'season' => GymSeason::OffSeason]);
        $dentro = $this->gerar(['days' => 5, 'sport' => GymSport::Racket, 'sportDays' => 2, 'season' => GymSeason::InSeason]);

        self::assertCount(5, $fora['workouts']);
        self::assertCount(3, $dentro['workouts'], 'em competição, no máximo 3 dias');
        self::assertSame(3, $dentro['input']['days']);
        self::assertStringContainsString('Limitei a 3 treinos', $dentro['notes']);
        self::assertStringContainsString('uma série a menos', $dentro['notes']);

        $primeiro = fn (array $p) => $p['workouts'][0]['exercises'][0];
        self::assertSame($primeiro($fora)['sets'] - 1, $primeiro($dentro)['sets']);

        foreach ($this->linhas($dentro) as $l) {
            if ($l['measure'] === 'load_reps') {
                self::assertGreaterThanOrEqual(2, $l['sets']);
            }
        }
    }

    public function test_pre_temporada_limita_a_quatro_e_esporte_quase_todo_dia_a_tres(): void
    {
        self::assertCount(4, $this->gerar(['days' => 6, 'sport' => GymSport::Soccer, 'sportDays' => 2, 'season' => GymSeason::PreSeason])['workouts']);

        $diario = $this->gerar(['days' => 6, 'sport' => GymSport::Running, 'sportDays' => 5, 'season' => GymSeason::OffSeason]);
        self::assertCount(3, $diario['workouts']);
        self::assertStringContainsString('quase todo dia', $diario['notes']);
    }

    public function test_quem_pede_menos_dias_que_o_limite_fica_com_o_que_pediu(): void
    {
        self::assertCount(2, $this->gerar(['days' => 2, 'sport' => GymSport::Cycling, 'sportDays' => 2, 'season' => GymSeason::InSeason])['workouts']);
    }

    public function test_o_esporte_libera_o_que_o_treino_comum_nunca_sorteia(): void
    {
        $proibidos = ['Flexão de Punho', 'Extensão de Punho', 'Rotação Externa (halter/cabo/polia)', 'Rosca Inversa'];

        $comum = $this->nomes($this->gerar(['days' => 3]));
        self::assertSame([], array_values(array_intersect($proibidos, $comum)));

        $tenis = [];
        foreach ([0, 1, 2] as $v) {
            $tenis = array_merge($tenis, $this->nomes($this->gerar(['days' => 3, 'goal' => GymGoal::GeneralHealth, 'sport' => GymSport::Racket, 'sportDays' => 3, 'season' => GymSeason::OffSeason, 'variant' => $v])));
        }

        self::assertNotEmpty(array_intersect($proibidos, $tenis), 'antebraço e ombro entram no treino de quem joga tênis');

        $natacao = $this->nomes($this->gerar(['sport' => GymSport::Swimming, 'sportDays' => 3, 'season' => GymSeason::OffSeason]));
        self::assertNotContains('Flexão de Punho', $natacao, 'o que não é do esporte continua de fora');
    }

    public function test_o_nome_o_resumo_e_as_orientacoes_falam_do_esporte(): void
    {
        $p = $this->gerar(['sport' => GymSport::Swimming, 'sportDays' => 3, 'season' => GymSeason::PreSeason, 'days' => 3]);

        self::assertStringContainsString('natação, pré-temporada', $p['name']);
        self::assertStringContainsString('Reforço para natação', $p['summary']);
        self::assertStringContainsString('ombro', $p['notes']);
        self::assertSame('swimming', $p['input']['sport']);
        self::assertSame(3, $p['input']['sport_days']);
        self::assertSame('pre_season', $p['input']['season']);
    }

    // --- Exercícios importados no sorteio ----------------------------------------------------------------------------------

    private function importado(array $campos): GymExerciseCatalog
    {
        return GymExerciseCatalog::create($campos + [
            'profile_id' => null, 'muscle_group' => GymMuscleGroup::Chest, 'measure_type' => GymMeasureType::LoadReps, 'equipment_hint' => 'Halteres',
            'in_auto_workout' => true, 'source_id' => 'x-'.uniqid(), 'source_level' => 'beginner', 'source_mechanic' => 'compound', 'source_category' => 'strength',
        ]);
    }

    public function test_o_nivel_da_biblioteca_manda_no_exercicio_importado(): void
    {
        $this->importado(['name' => 'Supino Maluco de Mestre', 'source_level' => 'intermediate']);
        $gerador = app(GymWorkoutGenerator::class);
        $c = GymExerciseCatalog::query()->where('name', 'Supino Maluco de Mestre')->firstOrFail();

        $iniciante = new GymGeneratorInput(GymGoal::Hypertrophy, GymLevel::Beginner, 3, 60, GymEquipmentAccess::FullGym);
        $intermediario = new GymGeneratorInput(GymGoal::Hypertrophy, GymLevel::Intermediate, 3, 60, GymEquipmentAccess::FullGym);

        self::assertNull($gerador->rowFor($c->id, $iniciante), 'nível intermediário não serve a iniciante');
        self::assertNotNull($gerador->rowFor($c->id, $intermediario));
    }

    public function test_a_biblioteca_diz_se_o_exercicio_e_composto_ou_isolado(): void
    {
        $this->importado(['name' => 'Exercício Sem Palavra Chave', 'muscle_group' => GymMuscleGroup::Arms, 'source_mechanic' => 'isolation']);
        $c = GymExerciseCatalog::query()->where('name', 'Exercício Sem Palavra Chave')->firstOrFail();

        $linha = app(GymWorkoutGenerator::class)->rowFor($c->id, new GymGeneratorInput(GymGoal::Hypertrophy, GymLevel::Intermediate, 3, 60, GymEquipmentAccess::FullGym));

        self::assertSame('isolation', $linha['kind']);
    }

    // --- Salvar e acompanhar -----------------------------------------------------------------------------------------------

    public function test_salvar_guarda_o_esporte_a_temporada_e_as_orientacoes(): void
    {
        $rascunho = $this->gerar(['sport' => GymSport::Racket, 'sportDays' => 3, 'season' => GymSeason::PreSeason, 'days' => 3]);

        $fase = app(GymPlanService::class)->createPlanFromDraft($rascunho)->fresh();

        self::assertSame(GymSport::Racket, $fase->sport);
        self::assertSame(3, $fase->sport_days);
        self::assertSame(GymSeason::PreSeason, $fase->season);
        self::assertStringContainsString('lado dominante', $fase->notes);
    }

    private function faseDeEsporte(int $semanas, GymSeason $temporada): GymPlan
    {
        $fase = app(GymPlanService::class)->createPlanFromDraft($this->gerar(['sport' => GymSport::Racket, 'sportDays' => 2, 'season' => $temporada, 'days' => 3]));
        $fase->update(['started_on' => CarbonImmutable::today()->subWeeks($semanas)->toDateString()]);

        return $fase->fresh();
    }

    public function test_a_pre_temporada_tem_prazo_curto_e_o_aviso_fala_da_temporada(): void
    {
        $servico = app(GymPhaseReviewService::class);

        self::assertSame([4, 6], $servico->thresholds(GymGoal::Strength, GymLevel::Intermediate, GymSeason::PreSeason));
        self::assertSame([8, 12], $servico->thresholds(GymGoal::Strength, GymLevel::Intermediate, GymSeason::InSeason), 'o objetivo deixa de mandar no prazo');
        self::assertSame([6, 8], $servico->thresholds(GymGoal::Strength, GymLevel::Intermediate), 'sem temporada vale o objetivo');

        $fase = $this->faseDeEsporte(5, GymSeason::PreSeason);
        self::assertSame('slow', $servico->review($fase)['status'], 'sem treinos feitos, o aviso é de retomar a rotina');

        // Com a rotina em dia, 5 semanas já estão na faixa de troca da pré-temporada e 7 passam dela.
        $treino = $fase->workouts()->firstOrFail();
        foreach (range(1, 14) as $i) {
            \App\Models\GymSession::create([
                'workout_id' => $treino->id, 'performed_on' => CarbonImmutable::today()->subDays($i)->toDateString(),
                'started_at' => CarbonImmutable::today()->subDays($i)->setTime(18, 0), 'finished_at' => CarbonImmutable::today()->subDays($i)->setTime(19, 0),
            ]);
        }

        $soon = $servico->review($fase->fresh());
        self::assertSame('soon', $soon['status']);
        self::assertStringContainsString('esportes de raquete (pré-temporada)', $soon['message']);

        $fase->update(['started_on' => CarbonImmutable::today()->subWeeks(7)->toDateString()]);
        self::assertSame('due', $servico->review($fase->fresh())['status']);
    }

    // --- A tela --------------------------------------------------------------------------------------------------------------

    public function test_a_tela_pergunta_o_que_melhorar_e_o_esporte_sugere_o_objetivo(): void
    {
        Livewire::test(GymPlanGenerator::class)
            ->assertSee('O que você quer melhorar?')->assertSee('Um esporte ou atividade')->assertDontSee('Em que fase da temporada você está')
            ->set('focus', 'sport')
            ->assertSee('Qual esporte')->assertSee('Vezes por semana no esporte')->assertSee('Fora de temporada')->assertSee('Em competição')
            ->assertSee('Como quer treinar na academia?')
            ->assertSet('goal', 'strength')
            ->set('sport', 'swimming')->assertSet('goal', 'general_health');
    }

    public function test_a_tela_gera_o_treino_do_esporte_e_mostra_as_orientacoes(): void
    {
        $tela = Livewire::test(GymPlanGenerator::class)
            ->set('focus', 'sport')->set('sport', 'racket')->set('sportDays', 3)->set('season', 'in_season')->set('days', 5)->set('level', 'intermediate')
            ->call('generate')->assertHasNoErrors()
            ->assertSee('Orientações para esta fase')->assertSee('Limitei a 3 treinos')->assertSee('Reforço para esportes de raquete');

        self::assertCount(3, $tela->get('draft.workouts'));
        self::assertSame(0, GymPlan::query()->count(), 'nada é gravado antes do Salvar');
    }

    public function test_a_tela_valida_o_esporte_so_quando_o_foco_e_esporte(): void
    {
        Livewire::test(GymPlanGenerator::class)->set('focus', 'sport')->set('sport', 'xadrez')->call('generate')->assertHasErrors('sport');
        Livewire::test(GymPlanGenerator::class)->set('focus', 'sport')->set('sportDays', 9)->call('generate')->assertHasErrors('sportDays');
        Livewire::test(GymPlanGenerator::class)->set('focus', 'sport')->set('season', 'verao')->call('generate')->assertHasErrors('season');
        Livewire::test(GymPlanGenerator::class)->set('focus', 'body')->set('sport', 'xadrez')->call('generate')->assertHasErrors('sport');
        Livewire::test(GymPlanGenerator::class)->set('focus', 'body')->call('generate')->assertHasNoErrors();
    }

    public function test_a_revisao_mostra_o_esporte_e_pergunta_a_temporada(): void
    {
        $this->faseDeEsporte(9, GymSeason::OffSeason);

        Livewire::test(GymPlanGenerator::class)
            ->assertSet('step', 'review')->assertSet('focus', 'sport')->assertSet('sport', 'racket')->assertSet('season', 'off_season')
            ->assertSee('Tênis, padel e outros esportes de raquete')->assertSee('Pratica por semana')
            ->assertSee('Em que fase da temporada você está agora?');
    }

    public function test_manter_os_objetivos_com_a_temporada_nova_gera_o_treino_da_temporada_nova(): void
    {
        $this->faseDeEsporte(9, GymSeason::OffSeason);

        $tela = Livewire::test(GymPlanGenerator::class)->set('season', 'in_season')->call('keepGoals')->assertSet('step', 'draft');

        self::assertSame('in_season', $tela->get('draft.input.season'));
        self::assertSame('racket', $tela->get('draft.input.sport'));
        self::assertLessThanOrEqual(3, count($tela->get('draft.workouts')));

        $tela->set('draft.name', 'Fase de competição')->call('save');
        $nova = app(GymPlanService::class)->activePlan();
        self::assertSame(GymSeason::InSeason, $nova->season);
        self::assertSame(GymSport::Racket, $nova->sport);
    }

    public function test_quem_muda_de_corpo_para_esporte_na_revisao_ajusta_so_o_que_mudou(): void
    {
        app(GymPlanService::class)->createPlanFromDraft($this->gerar(['goal' => GymGoal::Hypertrophy]));

        Livewire::test(GymPlanGenerator::class)
            ->assertSet('focus', 'body')->call('changeGoals')->assertSet('step', 'form')
            ->set('focus', 'sport')->set('sport', 'running')->call('generate')->assertSet('step', 'draft');
    }

    // --- A biblioteca --------------------------------------------------------------------------------------------------------

    public function test_so_forca_comum_de_academia_e_liberada_para_o_treino_automatico(): void
    {
        $base = ['name' => 'Remada Qualquer', 'category' => 'strength', 'level' => 'beginner', 'mechanic' => 'compound', 'equipment_hint' => 'Cabo/polia'];

        self::assertTrue(GymLibraryImporter::eligibleForAuto($base));
        self::assertFalse(GymLibraryImporter::eligibleForAuto(['level' => 'expert'] + $base));
        self::assertFalse(GymLibraryImporter::eligibleForAuto(['category' => 'stretching'] + $base));
        self::assertFalse(GymLibraryImporter::eligibleForAuto(['category' => 'strongman'] + $base));
        self::assertFalse(GymLibraryImporter::eligibleForAuto(['mechanic' => null] + $base));
        self::assertFalse(GymLibraryImporter::eligibleForAuto(['equipment_hint' => 'Faixa elástica'] + $base));
        self::assertFalse(GymLibraryImporter::eligibleForAuto(['equipment_hint' => null] + $base));
        self::assertFalse(GymLibraryImporter::eligibleForAuto(['name' => 'Supino com Correntes'] + $base));
        self::assertFalse(GymLibraryImporter::eligibleForAuto(['name' => 'Arranco de Força'] + $base));
        self::assertFalse(GymLibraryImporter::eligibleForAuto(['name' => 'Encolhimento de Ombros na Máquina de Panturrilha'] + $base));
    }

    public function test_exercicio_importado_antes_ganha_a_metadata_e_a_liberacao_sem_desfazer_escolha_do_admin(): void
    {
        $liberavel = $this->importado(['name' => 'Remada Qualquer', 'source_id' => 'Remada_Q', 'source_level' => null, 'source_mechanic' => null, 'source_category' => null, 'in_auto_workout' => false, 'equipment_hint' => 'Cabo/polia']);
        $jaAjustado = $this->importado(['name' => 'Outra Remada', 'source_id' => 'Outra_R', 'source_category' => 'strength', 'in_auto_workout' => false, 'equipment_hint' => 'Cabo/polia']);

        $linhas = [
            ['source_id' => 'Remada_Q', 'name' => 'Remada Qualquer', 'muscle_group' => 'back', 'measure_type' => 'load_reps', 'equipment_hint' => 'Cabo/polia', 'level' => 'beginner', 'mechanic' => 'compound', 'category' => 'strength', 'instructions' => ['Passo.']],
            ['source_id' => 'Outra_R', 'name' => 'Outra Remada', 'muscle_group' => 'back', 'measure_type' => 'load_reps', 'equipment_hint' => 'Cabo/polia', 'level' => 'beginner', 'mechanic' => 'compound', 'category' => 'strength', 'instructions' => ['Passo.']],
        ];

        $r = app(GymLibraryImporter::class)->import($linhas);

        self::assertSame(1, $r['liberados']);
        $liberavel->refresh();
        self::assertTrue($liberavel->in_auto_workout);
        self::assertSame('beginner', $liberavel->source_level);
        self::assertSame('compound', $liberavel->source_mechanic);
        self::assertFalse($jaAjustado->fresh()->in_auto_workout, 'a escolha do admin (já com metadata) não é desfeita');
    }

    public function test_a_biblioteca_do_repositorio_traz_nivel_mecanica_e_categoria_dos_novos(): void
    {
        $linhas = json_decode(file_get_contents(database_path('data/gym-exercise-library.json')), true, flags: JSON_THROW_ON_ERROR);
        $novos = array_filter($linhas, fn (array $l) => isset($l['name']));
        $liberados = array_filter($novos, fn (array $l) => GymLibraryImporter::eligibleForAuto($l));

        foreach ($novos as $l) {
            self::assertContains($l['level'], ['beginner', 'intermediate', 'expert'], $l['name']);
            self::assertNotNull($l['category'], $l['name']);
        }

        self::assertGreaterThan(200, count($liberados));
        self::assertLessThan(count($novos) / 2, count($liberados), 'a maior parte da biblioteca fica fora do sorteio');
    }
}
