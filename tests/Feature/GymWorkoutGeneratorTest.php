<?php

namespace Tests\Feature;

use App\Enums\GymEquipmentAccess;
use App\Enums\GymGoal;
use App\Enums\GymLevel;
use App\Enums\GymRestriction;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Livewire\Health\Gym\GymPlanGenerator;
use App\Models\FinancialProfile;
use App\Models\GymExercise;
use App\Models\GymPlan;
use App\Models\GymWorkoutExercise;
use App\Models\ProfileMember;
use App\Models\Subscription;
use App\Models\User;
use App\Services\GymPlanService;
use App\Services\GymWorkoutGenerator;
use App\Support\GymExerciseClassifier as C;
use App\Support\GymGeneratorInput;
use App\Support\ProfileContext;
use Database\Seeders\GymExerciseCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Treino automático: as regras do gerador (divisão, volume, prescrição, equipamento, restrições, nível), o rascunho que a
 * pessoa revisa e o salvamento como fase nova. Usa o catálogo de verdade (130 exercícios).
 */
class GymWorkoutGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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

    private function gerar(array $campos = []): array
    {
        $c = array_merge([
            'goal' => GymGoal::Hypertrophy, 'level' => GymLevel::Intermediate, 'days' => 4, 'minutes' => 60,
            'access' => GymEquipmentAccess::FullGym, 'restrictions' => [], 'variant' => 0,
        ], $campos);

        return app(GymWorkoutGenerator::class)->generate(new GymGeneratorInput($c['goal'], $c['level'], $c['days'], $c['minutes'], $c['access'], $c['restrictions'], $c['variant']));
    }

    /** @return list<array<string, mixed>> */
    private function exercicios(array $plano): array
    {
        return collect($plano['workouts'])->flatMap(fn (array $w) => $w['exercises'])->all();
    }

    /** Só os de força (sem o bloco de condicionamento). */
    private function forca(array $treino): array
    {
        return array_values(array_filter($treino['exercises'], fn (array $e) => $e['kind'] !== 'cardio'));
    }

    // --- Divisão e volume -----------------------------------------------------------------------------------------------

    public function test_a_divisao_acompanha_os_dias_por_semana(): void
    {
        $esperado = [
            2 => ['Corpo inteiro', 'Corpo inteiro'],
            3 => ['Corpo inteiro', 'Corpo inteiro', 'Corpo inteiro'],
            4 => ['Parte superior', 'Parte inferior', 'Parte superior', 'Parte inferior'],
            5 => ['Empurrar: peito, ombros e tríceps', 'Puxar: costas e bíceps', 'Pernas e glúteos', 'Parte superior', 'Parte inferior'],
            6 => ['Empurrar: peito, ombros e tríceps', 'Puxar: costas e bíceps', 'Pernas e glúteos', 'Empurrar: peito, ombros e tríceps', 'Puxar: costas e bíceps', 'Pernas e glúteos'],
        ];

        foreach ($esperado as $dias => $focos) {
            $plano = $this->gerar(['days' => $dias]);

            self::assertSame($focos, array_column($plano['workouts'], 'focus'), "{$dias} dias");
            self::assertSame(array_map(fn ($i) => 'Treino '.chr(65 + $i), array_keys($focos)), array_column($plano['workouts'], 'name'));
        }
    }

    public function test_o_tempo_define_quantos_exercicios_e_o_iniciante_faz_um_a_menos(): void
    {
        foreach ([30 => 4, 45 => 6, 60 => 7, 90 => 9] as $minutos => $quantos) {
            $intermediario = $this->gerar(['minutes' => $minutos, 'days' => 4]);
            $iniciante = $this->gerar(['minutes' => $minutos, 'days' => 4, 'level' => GymLevel::Beginner]);

            self::assertSame($quantos, count($this->forca($intermediario['workouts'][0])), "{$minutos} min");
            self::assertSame(max(3, $quantos - 1), count($this->forca($iniciante['workouts'][0])), "{$minutos} min iniciante");
        }
    }

    public function test_nenhum_treino_repete_exercicio_e_os_dias_nao_repetem_tudo(): void
    {
        $plano = $this->gerar(['days' => 5, 'minutes' => 60]);

        foreach ($plano['workouts'] as $treino) {
            $ids = array_column($treino['exercises'], 'catalog_id');
            self::assertSame($ids, array_values(array_unique($ids)), 'repetiu exercício no mesmo treino');
        }

        // Os dois treinos de parte superior/inferior (D e E) não são cópias dos de empurrar/puxar/pernas.
        $todos = array_column($this->exercicios($plano), 'catalog_id');
        self::assertGreaterThan(count($todos) * 0.7, count(array_unique($todos)), 'muita repetição entre os dias');
    }

    public function test_todo_treino_de_uma_hora_trabalha_os_grandes_grupos_e_o_core_aparece(): void
    {
        $plano = $this->gerar(['days' => 3, 'minutes' => 60]);
        $grupos = array_unique(array_column($this->exercicios($plano), 'group_value'));

        foreach (['chest', 'back', 'legs', 'shoulders', 'core'] as $grupo) {
            self::assertContains($grupo, $grupos, "faltou {$grupo}");
        }
    }

    public function test_o_primeiro_exercicio_de_cada_dia_traz_a_nota_de_aquecimento(): void
    {
        foreach ($this->gerar(['days' => 3])['workouts'] as $treino) {
            self::assertStringContainsString('aquecimento', $treino['exercises'][0]['notes']);
        }
    }

    // --- Prescrição ------------------------------------------------------------------------------------------------------

    public function test_a_prescricao_segue_o_objetivo(): void
    {
        $compostos = fn (array $plano) => array_filter($this->exercicios($plano), fn ($e) => $e['kind'] === 'compound' && $e['group_value'] !== 'core' && $e['measure'] === 'load_reps');

        foreach ($compostos($this->gerar(['goal' => GymGoal::Hypertrophy])) as $e) {
            self::assertSame([6, 10, 90], [$e['reps_min'], $e['reps_max'], $e['rest']], $e['name']);
        }

        foreach ($compostos($this->gerar(['goal' => GymGoal::Strength])) as $e) {
            self::assertSame([3, 6, 180], [$e['reps_min'], $e['reps_max'], $e['rest']], $e['name']);
            self::assertSame(4, $e['sets']);
        }

        foreach ($compostos($this->gerar(['goal' => GymGoal::WeightLoss])) as $e) {
            self::assertSame([12, 15, 45], [$e['reps_min'], $e['reps_max'], $e['rest']], $e['name']);
        }

        foreach ($compostos($this->gerar(['goal' => GymGoal::Endurance])) as $e) {
            self::assertSame([15, 20], [$e['reps_min'], $e['reps_max']], $e['name']);
        }
    }

    public function test_exercicio_em_tempo_vem_com_duracao_e_sem_pausa_nem_repeticoes(): void
    {
        $emTempo = array_filter($this->exercicios($this->gerar(['days' => 5, 'minutes' => 90, 'level' => GymLevel::Advanced])), fn ($e) => $e['measure'] !== 'load_reps' && $e['kind'] !== 'cardio');

        self::assertNotEmpty($emTempo, 'o treino de 90 min traz prancha');

        foreach ($emTempo as $e) {
            self::assertNotNull($e['duration']);
            self::assertNull($e['rest']);
            self::assertNull($e['reps_min']);
        }
    }

    public function test_condicionamento_entra_pelo_objetivo_e_nunca_como_exercicio_de_forca(): void
    {
        $emagrecer = $this->gerar(['goal' => GymGoal::WeightLoss, 'days' => 3, 'minutes' => 45]);
        foreach ($emagrecer['workouts'] as $treino) {
            self::assertSame('cardio', end($treino['exercises'])['kind'], 'todo dia termina com condicionamento');
        }

        self::assertSame([], array_filter($this->exercicios($this->gerar(['goal' => GymGoal::Hypertrophy])), fn ($e) => $e['kind'] === 'cardio'));

        $saude = $this->gerar(['goal' => GymGoal::GeneralHealth, 'days' => 3]);
        self::assertSame(['strength', 'strength', 'cardio'], array_map(fn ($t) => end($t['exercises'])['kind'] === 'cardio' ? 'cardio' : 'strength', $saude['workouts']), 'só o último dia');

        foreach ($this->exercicios($emagrecer) as $e) {
            if ($e['kind'] !== 'cardio') {
                self::assertFalse(C::isConditioning($e['name']), "{$e['name']} é condicionamento e entrou como força");
            }
        }
    }

    // --- Equipamento, restrições, nível --------------------------------------------------------------------------------------

    public function test_em_casa_nada_exige_aparelho_ou_barra(): void
    {
        foreach ($this->exercicios($this->gerar(['access' => GymEquipmentAccess::Home, 'days' => 4])) as $e) {
            self::assertTrue(C::fitsAccess($e['name'], $e['equipment'], GymEquipmentAccess::Home), "{$e['name']} ({$e['equipment']}) não cabe em casa");
            // "Máquina ou halteres" (panturrilha em pé) serve em casa, no modo halteres; o que exige só aparelho não.
            self::assertDoesNotMatchRegularExpression('/leg press|no cabo|polia|hack|voador|peck/iu', $e['name']);
        }
    }

    public function test_so_com_o_peso_do_corpo_nao_pede_nada_alem_disso(): void
    {
        $exercicios = $this->exercicios($this->gerar(['access' => GymEquipmentAccess::Bodyweight, 'days' => 3]));

        self::assertNotEmpty($exercicios);
        foreach ($exercicios as $e) {
            self::assertTrue(C::fitsAccess($e['name'], $e['equipment'], GymEquipmentAccess::Bodyweight), "{$e['name']} ({$e['equipment']})");
        }
    }

    public function test_o_que_se_faz_sem_carga_ganha_a_nota_da_mochila_so_quando_nao_e_do_corpo(): void
    {
        $afundo = collect($this->exercicios($this->gerar(['access' => GymEquipmentAccess::Bodyweight, 'days' => 3])))->first(fn ($e) => str_contains(C::normalize($e['name']), 'afundo'));

        self::assertNotNull($afundo);
        self::assertStringContainsString('mochila', $afundo['notes']);

        $flexao = collect($this->exercicios($this->gerar(['access' => GymEquipmentAccess::Bodyweight, 'days' => 3])))->first(fn ($e) => str_contains(C::normalize($e['name']), 'flexao'));
        self::assertStringNotContainsString('mochila', (string) $flexao['notes']);
    }

    public function test_restricoes_tiram_os_exercicios_que_mais_exigem_a_regiao(): void
    {
        $joelho = array_column($this->exercicios($this->gerar(['restrictions' => [GymRestriction::Knee], 'days' => 4, 'goal' => GymGoal::WeightLoss])), 'name');
        $ombro = array_column($this->exercicios($this->gerar(['restrictions' => [GymRestriction::Shoulder], 'days' => 5])), 'name');
        $lombar = array_column($this->exercicios($this->gerar(['restrictions' => [GymRestriction::LowerBack], 'days' => 4, 'level' => GymLevel::Advanced])), 'name');

        foreach ($joelho as $nome) {
            self::assertDoesNotMatchRegularExpression('/agachamento|leg press|afundo|extensora|salto|burpee|step/iu', $nome);
        }
        foreach ($ombro as $nome) {
            self::assertDoesNotMatchRegularExpression('/desenvolvimento|militar|arnold|mergulho|paralelas|elevação (frontal|lateral)/iu', $nome);
        }
        foreach ($lombar as $nome) {
            self::assertDoesNotMatchRegularExpression('/terra|stiff|good morning|remada curvada|hiperextensão/iu', $nome);
        }
    }

    public function test_iniciante_nao_pega_tecnica_exigente_e_prefere_aparelho_guiado(): void
    {
        $plano = $this->gerar(['level' => GymLevel::Beginner, 'days' => 3, 'minutes' => 60]);

        foreach ($this->exercicios($plano) as $e) {
            self::assertDoesNotMatchRegularExpression('/levantamento terra|stiff|good morning|búlgaro|barra fixa|frontal com barra|clean|turkish/iu', $e['name']);
        }

        $primeiro = $plano['workouts'][0]['exercises'];
        self::assertContains(C::equipment($primeiro[1]['equipment'], $primeiro[1]['name'])[0], ['machine', 'cable', 'dumbbell'], 'o primeiro empurrar/puxar é guiado');
    }

    public function test_nunca_sugere_reabilitacao_ou_exercicio_muito_especifico(): void
    {
        foreach ($this->gerar(['level' => GymLevel::Advanced, 'days' => 6, 'minutes' => 90])['workouts'] as $treino) {
            foreach ($treino['exercises'] as $e) {
                self::assertDoesNotMatchRegularExpression('/rotação (externa|interna)|punho|farmer|arremesso|battle rope|trenó|kettlebell swing|turkish|clean/iu', $e['name']);
            }
        }
    }

    // --- Determinismo e variações --------------------------------------------------------------------------------------------

    public function test_mesmo_pedido_mesmo_treino_e_outras_opcoes_mudam_os_exercicios(): void
    {
        self::assertSame($this->gerar(), $this->gerar());

        $a = array_column($this->exercicios($this->gerar(['variant' => 0])), 'catalog_id');
        $b = array_column($this->exercicios($this->gerar(['variant' => 1])), 'catalog_id');

        self::assertNotSame($a, $b);
    }

    public function test_grupo_sem_exercicio_possivel_vira_aviso_em_vez_de_erro(): void
    {
        // Só com o peso do corpo o catálogo não tem exercício de costas nem de ombros.
        $plano = $this->gerar(['access' => GymEquipmentAccess::Bodyweight, 'days' => 3]);

        self::assertNotEmpty($plano['warnings']);
        self::assertMatchesRegularExpression('/costas|ombros/u', implode(' ', $plano['warnings']));
        self::assertNotEmpty($this->exercicios($plano));
    }

    // --- Trocas do rascunho -----------------------------------------------------------------------------------------------------

    public function test_alternativas_sao_do_mesmo_grupo_respeitam_o_equipamento_e_nao_repetem_o_treino(): void
    {
        $plano = $this->gerar(['access' => GymEquipmentAccess::Home, 'days' => 3]);
        $treino = $plano['workouts'][0]['exercises'];
        $linha = $treino[1];
        $input = new GymGeneratorInput(GymGoal::Hypertrophy, GymLevel::Intermediate, 3, 60, GymEquipmentAccess::Home);

        $opcoes = app(GymWorkoutGenerator::class)->alternatives($linha, $input, array_column($treino, 'catalog_id'));

        self::assertNotEmpty($opcoes);
        foreach ($opcoes as $o) {
            self::assertSame($linha['group'], $o['group']);
            self::assertNotContains($o['id'], array_column($treino, 'catalog_id'));
            self::assertTrue(C::fitsAccess($o['name'], $o['equipment'], GymEquipmentAccess::Home), $o['name']);
        }

        $busca = app(GymWorkoutGenerator::class)->alternatives($linha, $input, [], 'AGACHAMENTO');
        self::assertNotEmpty($busca);
        self::assertContainsOnly('string', array_column($busca, 'name'));
    }

    public function test_row_for_refaz_a_prescricao_e_recusa_o_que_nao_cabe(): void
    {
        $input = new GymGeneratorInput(GymGoal::Strength, GymLevel::Intermediate, 3, 60, GymEquipmentAccess::FullGym);
        $supino = \App\Models\GymExerciseCatalog::query()->where('name', 'Supino Reto com Barra')->firstOrFail();

        $linha = app(GymWorkoutGenerator::class)->rowFor($supino->id, $input);
        self::assertSame([3, 6, 180], [$linha['reps_min'], $linha['reps_max'], $linha['rest']]);

        $emCasa = new GymGeneratorInput(GymGoal::Strength, GymLevel::Intermediate, 3, 60, GymEquipmentAccess::Home);
        self::assertNull(app(GymWorkoutGenerator::class)->rowFor($supino->id, $emCasa), 'barra livre não cabe em casa');
        self::assertNull(app(GymWorkoutGenerator::class)->rowFor('nao-existe', $input));
    }

    // --- Tela e salvamento -------------------------------------------------------------------------------------------------------

    public function test_a_tela_gera_o_rascunho_sem_gravar_nada_ate_salvar(): void
    {
        Livewire::test(GymPlanGenerator::class)
            ->set('goal', 'hypertrophy')->set('level', 'intermediate')->set('days', 4)->set('minutes', 60)->set('access', 'full_gym')
            ->call('generate')->assertHasNoErrors()
            ->assertSee('Treino A')->assertSee('Parte superior')->assertSee('Salvar como nova fase');

        self::assertSame(0, GymPlan::query()->count());
        self::assertSame(0, GymExercise::query()->count());
    }

    public function test_a_tela_valida_o_pedido(): void
    {
        Livewire::test(GymPlanGenerator::class)->set('days', 7)->call('generate')->assertHasErrors('days');
        Livewire::test(GymPlanGenerator::class)->set('minutes', 20)->call('generate')->assertHasErrors('minutes');
        Livewire::test(GymPlanGenerator::class)->set('goal', 'x')->call('generate')->assertHasErrors('goal');
        Livewire::test(GymPlanGenerator::class)->set('restrictions', ['pescoço'])->call('generate')->assertHasErrors('restrictions.0');
    }

    public function test_trocar_e_remover_no_rascunho_e_salvar_cria_a_fase_nova_e_encerra_a_anterior(): void
    {
        $antiga = app(GymPlanService::class)->createPlan('Fase antiga');

        $tela = Livewire::test(GymPlanGenerator::class)
            ->set('days', 3)->set('level', 'intermediate')->call('generate');

        $original = $tela->get('draft.workouts.0.exercises.1.name');
        $outro = \App\Models\GymExerciseCatalog::query()->where('name', 'Supino Reto com Halteres')->firstOrFail();

        $tela->call('openSwap', 0, 1)->assertSet('showSwap', true)->call('swapTo', $outro->id)->assertSet('showSwap', false);
        self::assertSame('Supino Reto com Halteres', $tela->get('draft.workouts.0.exercises.1.name'));
        self::assertNotSame($original, 'Supino Reto com Halteres');

        $antes = count($tela->get('draft.workouts.0.exercises'));
        $tela->call('removeExercise', 0, 0);
        self::assertCount($antes - 1, $tela->get('draft.workouts.0.exercises'));

        $tela->set('draft.name', 'Minha fase nova')->call('save')->assertRedirect(route('health.gym.index'));

        $nova = app(GymPlanService::class)->activePlan();
        self::assertSame('Minha fase nova', $nova->name);
        self::assertFalse($antiga->fresh()->is_active);
        self::assertSame(3, $nova->workouts()->count());

        $itens = GymWorkoutExercise::query()->whereIn('workout_id', $nova->workouts()->pluck('id'))->with('exercise', 'defaultEquipment')->get();
        self::assertGreaterThan(10, $itens->count());
        self::assertTrue($itens->contains(fn ($i) => $i->exercise->name === 'Supino Reto com Halteres' && $i->defaultEquipment?->name === 'Halteres'));
        self::assertTrue($itens->every(fn ($i) => $i->target_sets >= 1 && $i->position >= 1));
    }

    public function test_salvar_e_tudo_ou_nada(): void
    {
        $antiga = app(GymPlanService::class)->createPlan('Fase antiga');
        $rascunho = ['name' => 'Quebrada', 'workouts' => [['name' => 'Treino A', 'focus' => null, 'exercises' => [
            ['catalog_id' => \App\Models\GymExerciseCatalog::query()->value('id'), 'sets' => 3, 'reps_min' => 8, 'reps_max' => 12, 'duration' => null, 'rest' => 60, 'notes' => null],
            ['catalog_id' => 'id-que-nao-existe', 'sets' => 3, 'reps_min' => 8, 'reps_max' => 12, 'duration' => null, 'rest' => 60, 'notes' => null],
        ]]]];

        try {
            app(GymPlanService::class)->createPlanFromDraft($rascunho);
            self::fail('salvou um rascunho quebrado');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            // esperado
        }

        self::assertTrue($antiga->fresh()->is_active, 'a fase atual continua ativa');
        self::assertSame(1, GymPlan::query()->count());
    }

    public function test_rascunho_sem_nenhum_exercicio_nao_salva(): void
    {
        $tela = Livewire::test(GymPlanGenerator::class)->set('days', 2)->call('generate');

        foreach ([0, 1] as $w) {
            foreach (array_keys($tela->get("draft.workouts.$w.exercises")) as $_) {
                $tela->call('removeExercise', $w, 0);
            }
        }

        $tela->call('save')->assertHasErrors('draft');
        self::assertSame(0, GymPlan::query()->count());
    }

    public function test_so_o_dono_abre_o_treino_automatico(): void
    {
        $outro = User::factory()->consultant()->create();
        $this->actingAs($outro);
        app(ProfileContext::class)->clear();

        $this->get(route('health.gym.generate'))->assertStatus(302);
    }

    public function test_sem_o_modulo_saude_nao_monta_treino(): void
    {
        Subscription::query()->delete();

        Livewire::test(GymPlanGenerator::class)->assertStatus(402);
    }
    // --- Classificador -------------------------------------------------------------------------------------------------------------

    public function test_classificador_le_composto_isolado_braco_e_familia(): void
    {
        self::assertSame('compound', C::kind('Supino Inclinado com Halteres', 'chest'));
        self::assertSame('isolation', C::kind('Crucifixo Reto', 'chest'));
        self::assertSame('compound', C::kind('Agachamento Livre com Barra', 'legs'));
        self::assertSame('isolation', C::kind('Cadeira Extensora', 'legs'));
        self::assertTrue(C::isCalf('Panturrilha Sentada (Seated Calf Raise)'));
        self::assertSame('triceps', C::armPart('Tríceps Corda no Cabo'));
        self::assertSame('triceps', C::armPart('Mergulho no Banco (Bench Dip)'));
        self::assertSame('biceps', C::armPart('Rosca Martelo'));
        self::assertSame(C::family('Supino Reto com Barra'), C::family('Supino Inclinado com Halteres'));
        self::assertNotSame(C::family('Elevação Lateral'), C::family('Elevação Pélvica Unilateral'));
        self::assertGreaterThan(C::priority('Supino Declinado'), C::priority('Supino Reto com Halteres'));
    }

    public function test_classificador_le_o_equipamento(): void
    {
        self::assertSame(['machine'], C::equipment('Máquina', 'Leg Press 45°'));
        self::assertContains('cable', C::equipment('Cabo/polia alta', 'Puxada Frontal (Pulley)'));
        self::assertContains('barbell', C::equipment('Barra livre', 'Supino Reto com Barra'));
        self::assertContains('bodyweight', C::equipment('Peso do corpo/assistida', 'Barra Fixa (Pull-up)'));
        self::assertNotContains('barbell', C::equipment('Peso do corpo/assistida', 'Barra Fixa (Pull-up)'), 'barra fixa não é barra livre');
        self::assertTrue(C::fitsAccess('Flexão de Braço', 'Peso do corpo', GymEquipmentAccess::Bodyweight));
        self::assertFalse(C::fitsAccess('Supino Reto com Barra', 'Barra livre', GymEquipmentAccess::Home));
        self::assertTrue(C::fitsAccess('Remada Unilateral com Halter', 'Halter', GymEquipmentAccess::Home));
    }
}
