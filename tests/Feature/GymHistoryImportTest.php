<?php

namespace Tests\Feature;

use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Models\FinancialProfile;
use App\Models\GymEquipment;
use App\Models\GymExercise;
use App\Models\GymPlan;
use App\Models\GymSession;
use App\Models\GymSetLog;
use App\Models\GymWorkout;
use App\Models\ProfileMember;
use App\Models\Subscription;
use App\Models\User;
use App\Services\GymHistoryImporter;
use App\Services\GymPlanService;
use App\Services\GymProgressService;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Importação de histórico (JSON revisado): valida o arquivo inteiro antes
 * de gravar, é idempotente e só escreve na conta da pessoa indicada.
 */
class GymHistoryImportTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;
    private FinancialProfile $perfil;
    private ProfileMember $membro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = User::factory()->create(['email' => 'marcelo@cerne.test']);
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->usuario->id]);
        $this->membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuario->id]);
    }

    public function test_arquivo_de_exemplo_do_repositorio_e_valido(): void
    {
        $dados = json_decode(file_get_contents(database_path('examples/gym-history.example.json')), true);

        self::assertSame([], app(GymHistoryImporter::class)->validate($dados));
    }

    public function test_importa_plano_sessoes_e_series_com_a_carga_derivada(): void
    {
        $this->entrar();

        $r = app(GymHistoryImporter::class)->import($this->dados());

        self::assertEquals(['plan_created' => true, 'workouts' => 1, 'exercises' => 3, 'plan_exercises' => 3, 'equipment' => 1, 'sessions' => 2, 'sets' => 6, 'skipped_sessions' => 0], $r);
        self::assertSame(2, GymSession::query()->whereNotNull('finished_at')->count());

        $agachamento = GymSetLog::query()->whereHas('exercise', fn ($q) => $q->where('name', 'Agachamento'))->orderBy('set_number')->first();
        self::assertSame('per_side', $agachamento->load_mode->value);
        self::assertSame('30.00', $agachamento->load_total_kg); // 15 kg por lado

        $esteira = GymSetLog::query()->whereNotNull('distance_meters')->firstOrFail();
        self::assertSame(1000, $esteira->distance_meters);
        self::assertSame(631, $esteira->duration_seconds);
    }

    public function test_sessao_importada_leva_data_e_observacao_de_data_aproximada(): void
    {
        $this->entrar();
        app(GymHistoryImporter::class)->import($this->dados());

        $sessao = GymSession::query()->orderBy('performed_on')->get();

        self::assertSame('2026-08-01', $sessao[0]->performed_on->toDateString());
        self::assertNull($sessao[0]->notes);
        self::assertStringContainsString('Data aproximada', $sessao[1]->notes);
    }

    public function test_rodar_duas_vezes_nao_duplica_nada(): void
    {
        $this->entrar();
        $importador = app(GymHistoryImporter::class);

        $importador->import($this->dados());
        $segunda = $importador->import($this->dados());

        self::assertSame(0, $segunda['sessions']);
        self::assertSame(2, $segunda['skipped_sessions']);
        self::assertSame(0, $segunda['workouts'] + $segunda['exercises'] + $segunda['plan_exercises'] + $segunda['equipment']);
        self::assertSame(1, GymPlan::query()->count());
        self::assertSame(2, GymSession::query()->count());
        self::assertSame(6, GymSetLog::query()->count());
    }

    public function test_simulacao_nao_grava_nada_mas_conta_como_se_gravasse(): void
    {
        $this->entrar();

        $r = app(GymHistoryImporter::class)->import($this->dados(), dryRun: true);

        self::assertSame(2, $r['sessions']);
        self::assertSame(6, $r['sets']);
        self::assertSame(0, GymPlan::query()->count() + GymExercise::query()->count() + GymSession::query()->count() + GymEquipment::query()->count());
    }

    public function test_nao_toma_o_plano_ativo_de_quem_ja_montou_o_seu(): void
    {
        $this->entrar();
        $meu = app(GymPlanService::class)->createPlan('Meu plano');

        app(GymHistoryImporter::class)->import($this->dados());

        self::assertTrue($meu->fresh()->is_active);
        self::assertFalse(GymPlan::query()->where('name', 'Fase 1')->firstOrFail()->is_active);
    }

    public function test_nova_fase_com_activate_encerra_a_anterior_reaproveita_exercicios_e_reinicia_a_rotacao(): void
    {
        $this->entrar();
        $importador = app(GymHistoryImporter::class);
        $importador->import($this->dados());

        $fase2 = [
            'version' => 1,
            'plan' => [
                'name' => 'Fase 2', 'started_on' => '2026-09-28', 'activate' => true, 'notes' => "Orientações\nda fase",
                'workouts' => [
                    ['name' => 'Treino A', 'exercises' => [
                        ['name' => 'Agachamento', 'muscle_group' => 'legs', 'measure_type' => 'load_reps', 'sets' => 3, 'reps_min' => 8, 'reps_max' => 10],
                    ]],
                    ['name' => 'Treino B', 'exercises' => []],
                ],
            ],
        ];
        self::assertSame([], $importador->validate($fase2));
        $r = $importador->import($fase2);

        $antiga = GymPlan::query()->where('name', 'Fase 1')->firstOrFail();
        $nova = GymPlan::query()->where('name', 'Fase 2')->firstOrFail();

        self::assertFalse($antiga->is_active);
        self::assertSame('2026-09-28', $antiga->ended_on->toDateString());
        self::assertTrue($nova->is_active);
        self::assertSame("Orientações\nda fase", $nova->notes);
        self::assertSame(0, $r['exercises']); // "Agachamento" já era do catálogo: o histórico continua ligado
        self::assertSame(3, GymExercise::query()->count());
        self::assertSame($nova->id, app(GymPlanService::class)->activePlan()->id);
        // Sessões da fase anterior não contam: a rotação recomeça no primeiro treino da nova.
        self::assertSame('Treino A', app(GymPlanService::class)->nextWorkout($nova)->name);

        \Livewire\Livewire::test(\App\Livewire\Health\Gym\GymHome::class)
            ->assertSee('Orientações da fase')
            ->assertSee('Orientações');
    }

    public function test_validacao_de_activate_notes_e_data_de_inicio(): void
    {
        $dados = $this->dados();
        $dados['plan']['activate'] = 'sim';
        $dados['plan']['notes'] = ['x'];
        $dados['plan']['started_on'] = '28/09/2026';

        $erros = implode("\n", app(GymHistoryImporter::class)->validate($dados));

        self::assertStringContainsString('activate', $erros);
        self::assertStringContainsString('notes', $erros);
        self::assertStringContainsString('started_on', $erros);
    }

    public function test_conjuge_sem_perfil_proprio_importa_pelo_comando_e_o_titular_nao_ve(): void
    {
        $usuarioConjuge = User::factory()->create(['email' => 'esposa@cerne.test']);
        $conjuge = ProfileMember::factory()->secondary()->create(['profile_id' => $this->perfil->id, 'user_id' => $usuarioConjuge->id]);
        $arquivo = tempnam(sys_get_temp_dir(), 'gym');
        file_put_contents($arquivo, json_encode($this->dados()));

        $this->artisan('cerne:gym-import', ['file' => $arquivo, '--email' => 'esposa@cerne.test'])->assertSuccessful();

        self::assertSame(2, GymSession::withoutGlobalScopes()->where('member_id', $conjuge->id)->count());
        self::assertSame(0, GymSession::withoutGlobalScopes()->where('member_id', $this->membro->id)->count());

        // Titular abrindo a própria conta não enxerga nada da esposa.
        $this->entrar();
        self::assertSame(0, GymSession::query()->count());

        unlink($arquivo);
    }

    public function test_reaproveita_exercicio_e_treino_que_ja_existem(): void
    {
        $this->entrar();
        $servico = app(GymPlanService::class);
        $plano = $servico->createPlan('Fase 1');
        $treino = $servico->addWorkout($plano, 'Treino C', null);

        $r = app(GymHistoryImporter::class)->import($this->dados());

        self::assertSame(0, $r['workouts']);
        self::assertSame(1, GymWorkout::query()->count());
        self::assertSame($treino->id, GymSession::query()->firstOrFail()->workout_id);
    }

    public function test_grava_so_na_conta_do_contexto_e_o_conjuge_nao_ve(): void
    {
        $conjuge = ProfileMember::factory()->secondary()->create([
            'profile_id' => $this->perfil->id, 'user_id' => User::factory()->create()->id,
        ]);
        $this->entrar();
        app(GymHistoryImporter::class)->import($this->dados());

        self::assertSame(0, GymSetLog::query()->where('member_id', '!=', $this->membro->id)->count());

        app(ProfileContext::class)->set($this->perfil, $conjuge);
        self::assertSame(0, GymSession::query()->count() + GymSetLog::query()->count());
    }

    public function test_validacao_acusa_todos_os_problemas_de_uma_vez(): void
    {
        $dados = $this->dados();
        $dados['plan']['workouts'][0]['exercises'][0]['muscle_group'] = 'pescoco';
        $dados['sessions'][0]['date'] = '2026-13-40';
        $dados['sessions'][0]['sets'][0]['exercise'] = 'Não existe';
        $dados['sessions'][1]['workout'] = 'Treino Z';
        $dados['sessions'][1]['sets'][0]['mode'] = 'quilos';

        $erros = app(GymHistoryImporter::class)->validate($dados);

        self::assertGreaterThanOrEqual(4, count($erros));
        self::assertStringContainsString('muscle_group', implode("\n", $erros));
        self::assertStringContainsString('data inválida', implode("\n", $erros));
        self::assertStringContainsString('Não existe', implode("\n", $erros));
        self::assertStringContainsString('Treino Z', implode("\n", $erros));
    }

    public function test_carga_sem_modo_e_serie_repetida_sao_recusadas(): void
    {
        $dados = $this->dados();
        unset($dados['sessions'][0]['sets'][0]['mode']);
        $dados['sessions'][0]['sets'][1]['set'] = 1; // o mesmo exercício já tem a série 1

        $erros = implode("\n", app(GymHistoryImporter::class)->validate($dados));

        self::assertStringContainsString('sem "mode"', $erros);
        self::assertStringContainsString('repetida', $erros);
    }

    public function test_versao_desconhecida_e_recusada(): void
    {
        self::assertNotSame([], app(GymHistoryImporter::class)->validate(['version' => 2]));
    }

    public function test_esteira_compara_velocidade_e_nao_tempo(): void
    {
        $this->entrar();
        app(GymHistoryImporter::class)->import($this->dados());
        $esteira = GymExercise::query()->where('name', 'Esteira')->firstOrFail();

        $analise = app(GymProgressService::class)->analyze($esteira);

        // 1 km em 10:31 = 5,70 km/h; a sessão de 10:05 (mais rápida) é a melhor marca.
        self::assertSame('speed', $analise['metric']);
        self::assertSame('5.70', $analise['sessions'][0]['value']);
        self::assertSame('5.95', $analise['best']['value']);
    }

    public function test_comando_simula_importa_e_recusa_conta_sem_perfil(): void
    {
        $arquivo = tempnam(sys_get_temp_dir(), 'gym');
        file_put_contents($arquivo, json_encode($this->dados()));

        $this->artisan('cerne:gym-import', ['file' => $arquivo, '--email' => 'marcelo@cerne.test', '--dry-run' => true])
            ->expectsOutputToContain('SIMULAÇÃO')
            ->assertSuccessful();
        self::assertSame(0, GymSession::withoutGlobalScopes()->count());

        $this->artisan('cerne:gym-import', ['file' => $arquivo, '--email' => 'marcelo@cerne.test'])->assertSuccessful();
        self::assertSame(2, GymSession::withoutGlobalScopes()->where('member_id', $this->membro->id)->count());

        User::factory()->create(['email' => 'sem-perfil@cerne.test']);
        $this->artisan('cerne:gym-import', ['file' => $arquivo, '--email' => 'sem-perfil@cerne.test'])->assertFailed();

        $this->artisan('cerne:gym-import', ['file' => $arquivo])->assertFailed();

        unlink($arquivo);
    }

    public function test_comando_nao_grava_nada_se_o_arquivo_for_invalido(): void
    {
        $dados = $this->dados();
        $dados['sessions'][1]['workout'] = 'Treino Z';
        $arquivo = tempnam(sys_get_temp_dir(), 'gym');
        file_put_contents($arquivo, json_encode($dados));

        $this->artisan('cerne:gym-import', ['file' => $arquivo, '--email' => 'marcelo@cerne.test'])->assertFailed();

        self::assertSame(0, GymSession::withoutGlobalScopes()->count() + GymPlan::withoutGlobalScopes()->count());
        unlink($arquivo);
    }

    private function entrar(): void
    {
        $this->actingAs($this->usuario);
        app(ProfileContext::class)->set($this->perfil, $this->membro);

        // Academia é gateada por EntitlementService (módulo Saúde) — sem
        // isso, GymHome dá 402.
        if (! Subscription::query()->where('user_id', $this->usuario->id)->exists()) {
            Subscription::create([
                'user_id' => $this->usuario->id,
                'kind' => SubscriptionKind::Direct,
                'bundle' => SubscriptionBundle::Completo,
                'status' => SubscriptionStatus::Active,
                'started_at' => now(),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function dados(): array
    {
        return [
            'version' => 1,
            'plan' => [
                'name' => 'Fase 1',
                'started_on' => '2026-07-27',
                'workouts' => [[
                    'name' => 'Treino C',
                    'focus' => 'Pernas',
                    'exercises' => [
                        ['name' => 'Agachamento', 'muscle_group' => 'legs', 'measure_type' => 'load_reps', 'sets' => 3, 'reps_min' => 12, 'reps_max' => 12, 'rest_seconds' => 60, 'equipment' => 'Barra'],
                        ['name' => 'Panturrilha', 'muscle_group' => 'legs', 'measure_type' => 'load_reps', 'sets' => 3, 'reps_min' => 15, 'reps_max' => 15],
                        ['name' => 'Esteira', 'muscle_group' => 'cardio', 'measure_type' => 'distance_duration', 'sets' => 1],
                    ],
                ]],
            ],
            'sessions' => [
                [
                    'workout' => 'Treino C', 'date' => '2026-08-01', 'date_approx' => false, 'notes' => null,
                    'sets' => [
                        ['exercise' => 'Agachamento', 'set' => 1, 'reps' => 12, 'load' => '15', 'mode' => 'per_side', 'equipment' => 'Barra'],
                        ['exercise' => 'Agachamento', 'set' => 2, 'reps' => 12, 'load' => '15', 'mode' => 'per_side', 'equipment' => 'Barra'],
                        ['exercise' => 'Panturrilha', 'set' => 1, 'reps' => 15, 'mode' => 'bodyweight'],
                        ['exercise' => 'Esteira', 'set' => 1, 'duration' => 631, 'distance' => 1000],
                    ],
                ],
                [
                    'workout' => 'Treino C', 'date' => '2026-08-08', 'date_approx' => true, 'notes' => 'Esteira com 3% de inclinação.',
                    'sets' => [
                        ['exercise' => 'Agachamento', 'set' => 1, 'reps' => 12, 'load' => '20', 'mode' => 'per_side', 'equipment' => 'Barra'],
                        ['exercise' => 'Esteira', 'set' => 1, 'duration' => 605, 'distance' => 1000],
                    ],
                ],
            ],
        ];
    }
}
