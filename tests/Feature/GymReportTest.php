<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\GymLoadMode;
use App\Enums\GymMeasureType;
use App\Enums\GymMuscleGroup;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Livewire\Health\Gym\GymProgress;
use App\Livewire\Health\Gym\GymReport;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\GymExercise;
use App\Models\GymPlan;
use App\Models\GymSession;
use App\Models\GymSetLog;
use App\Models\GymWorkout;
use App\Models\GymWorkoutExercise;
use App\Models\ProfileMember;
use App\Models\Subscription;
use App\Models\User;
use App\Services\GymPlanService;
use App\Services\GymReportService;
use App\Support\GymReportCharts;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Relatório de evolução da Academia: o resumo, a frequência, os destaques e cada exercício dentro do seu treino, na tela e em PDF.
 */
class GymReportTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private FinancialProfile $perfil;

    private GymWorkout $treinoA;

    private GymWorkout $treinoB;

    private GymWorkoutExercise $supino;

    private GymWorkoutExercise $remada;

    private GymWorkoutExercise $agachamento;

    private float $volumeEsperado = 0.0;

    protected function setUp(): void
    {
        parent::setUp();

        // Sexta-feira. O período dos testes é 26/10 a 20/11: quatro semanas (segundas: 26/10, 02/11, 09/11, 16/11).
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-20 12:00:00'));

        $this->usuario = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuario->id]);
        $this->actingAs($this->usuario);
        app(ProfileContext::class)->set($this->perfil, $membro);
        Subscription::create([
            'user_id' => $this->usuario->id, 'kind' => SubscriptionKind::Direct,
            'bundle' => SubscriptionBundle::Completo, 'status' => SubscriptionStatus::Active, 'started_at' => now(),
        ]);

        $plano = app(GymPlanService::class);
        $fase = $plano->createPlan('Fase 1');
        $fase->update(['started_on' => '2026-10-26']);
        $this->treinoA = $plano->addWorkout($fase, 'Treino A', 'Peito e costas');
        $this->treinoB = $plano->addWorkout($fase, 'Treino B', 'Pernas');
        $meta = ['target_sets' => 3, 'target_reps_min' => 8, 'target_reps_max' => 12, 'rest_seconds' => 60];
        $this->supino = $plano->addExercise($this->treinoA, $plano->findOrCreateExercise('Supino', GymMuscleGroup::Chest, GymMeasureType::LoadReps), $meta);
        $this->remada = $plano->addExercise($this->treinoA, $plano->findOrCreateExercise('Remada', GymMuscleGroup::Back, GymMeasureType::LoadReps), $meta);
        $this->agachamento = $plano->addExercise($this->treinoB, $plano->findOrCreateExercise('Agachamento', GymMuscleGroup::Legs, GymMeasureType::LoadReps), $meta);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /**
     * Uma sessão numa data, com 3 séries de 10 repetições na carga de cada exercício informado.
     *
     * @param  array<string, string>  $cargas  id do item => carga
     */
    private function treinar(string $data, GymWorkout $treino, array $cargas, bool $finalizada = true, int $minutos = 60): GymSession
    {
        $inicio = CarbonImmutable::parse($data.' 18:00:00');
        $sessao = GymSession::create([
            'workout_id' => $treino->id, 'performed_on' => $data, 'started_at' => $inicio,
            'finished_at' => $finalizada ? $inicio->addMinutes($minutos) : null,
        ]);

        foreach ($cargas as $itemId => $carga) {
            $item = GymWorkoutExercise::query()->findOrFail($itemId);

            foreach ([1, 2, 3] as $n) {
                GymSetLog::create([
                    'session_id' => $sessao->id, 'exercise_id' => $item->exercise_id, 'set_number' => $n, 'reps' => 10,
                    'load_value' => $carga, 'load_mode' => GymLoadMode::Total, 'completed_at' => $inicio->addMinutes($n),
                ]);

                if ($finalizada) {
                    $this->volumeEsperado += (float) $carga * 10;
                }
            }
        }

        return $sessao;
    }

    /** Um mês típico: 5 treinos finalizados e 1 aberto, mais uma sessão antiga (setembro) de cada treino para os recordes. */
    private function mesTipico(): void
    {
        $this->treinar('2026-09-15', $this->treinoA, [$this->supino->id => '50']);          // antes do período: supino 50
        $this->treinar('2026-09-20', $this->treinoB, [$this->agachamento->id => '50']);     // antes do período: agachamento 50
        $this->volumeEsperado = 0.0;                                                          // o volume esperado é só o do período

        $this->treinar('2026-10-27', $this->treinoA, [$this->supino->id => '40', $this->remada->id => '40']);
        $this->treinar('2026-10-29', $this->treinoB, [$this->agachamento->id => '60']);
        $this->treinar('2026-11-03', $this->treinoA, [$this->supino->id => '42.5', $this->remada->id => '45']);
        $this->treinar('2026-11-10', $this->treinoA, [$this->supino->id => '45', $this->remada->id => '52.5']);
        $this->treinar('2026-11-12', $this->treinoB, [$this->agachamento->id => '60'], minutos: 360);   // 6 h: esquecido aberto, fora da média
        $this->treinar('2026-11-18', $this->treinoA, [$this->supino->id => '100'], finalizada: false);   // aberto: nunca conta
    }

    private function relatorio(string $de = '2026-10-26', string $ate = '2026-11-20'): array
    {
        return app(GymReportService::class)->report(CarbonImmutable::parse($de), CarbonImmutable::parse($ate));
    }

    // --- Resumo -------------------------------------------------------------------------------------------------------

    public function test_resumo_conta_treinos_constancia_series_e_volume(): void
    {
        $this->mesTipico();

        $r = $this->relatorio()['summary'];

        self::assertSame(5, $r['sessions'], 'o treino aberto não conta');
        self::assertSame('1,3', $r['per_week']);
        self::assertSame(4, $r['weeks_total']);
        self::assertSame(3, $r['weeks_trained'], 'a semana de 16/11 ficou sem treino finalizado');
        self::assertSame(75, $r['consistency']);
        self::assertSame(7, $r['longest_gap_days'], 'de 03/11 a 10/11');
        self::assertSame(2 * 3 + 1 * 3 + 2 * 3 + 2 * 3 + 1 * 3, $r['sets']);
        self::assertEqualsWithDelta($this->volumeEsperado, $r['volume_raw'], 0.01);
        self::assertSame(60, $r['avg_minutes'], 'a sessão de 6 horas não entra na média');
        self::assertSame(8, $r['days_since_last'], 'o último treino finalizado foi em 12/11');
    }

    public function test_periodo_sem_treino_devolve_resumo_vazio_sem_erro(): void
    {
        $r = $this->relatorio('2026-01-01', '2026-01-31');

        self::assertSame(0, $r['summary']['sessions']);
        self::assertSame([], $r['workouts']);
        self::assertSame([], $r['insights']);
        self::assertNull($r['summary']['avg_minutes']);
    }

    public function test_frequencia_mostra_a_semana_sem_treino_e_agrupa_por_mes_em_periodo_longo(): void
    {
        $this->mesTipico();

        $semanal = $this->relatorio();
        self::assertSame('semana', $semanal['weekly_unit']);
        self::assertSame([['label' => '26/10', 'count' => 2], ['label' => '02/11', 'count' => 1], ['label' => '09/11', 'count' => 2], ['label' => '16/11', 'count' => 0]], $semanal['weekly']);

        $longo = $this->relatorio('2025-11-21', '2026-11-20');
        self::assertSame('mês', $longo['weekly_unit']);
        self::assertGreaterThanOrEqual(12, count($longo['weekly']));
        self::assertSame(2, collect($longo['weekly'])->firstWhere('label', 'out/26')['count'] ?? null, '27/10 e 29/10');
    }

    public function test_compara_com_o_periodo_anterior_do_mesmo_tamanho(): void
    {
        $this->mesTipico();
        $this->treinar('2026-10-05', $this->treinoA, [$this->supino->id => '38']);   // período anterior: 28 dias antes de 26/10

        $r = $this->relatorio();

        self::assertSame(1, $r['previous']['sessions']);
        self::assertStringContainsString('4 treino(s) a mais', implode(' ', $r['insights']));
    }

    // --- Treinos e exercícios -------------------------------------------------------------------------------------------------

    public function test_cada_exercicio_aparece_no_seu_treino_na_ordem_do_plano_com_saiu_de_chegou_a(): void
    {
        $this->mesTipico();

        $treinos = $this->relatorio()['workouts'];

        self::assertSame(['Treino A', 'Treino B'], array_column($treinos, 'name'));
        self::assertSame('Peito e costas', $treinos[0]['focus']);
        self::assertSame('Fase 1', $treinos[0]['plan']);
        self::assertSame(3, $treinos[0]['sessions']);
        self::assertSame(['Supino', 'Remada'], array_map(fn ($l) => $l['exercise']->name, $treinos[0]['exercises']));

        [$supino, $remada] = $treinos[0]['exercises'];
        self::assertSame(['40.00', '45.00'], [$supino['first']['value'], $supino['last']['value']]);
        self::assertSame('12.5', $supino['change_pct']);
        self::assertSame('31.3', $remada['change_pct'], '40 para 52,5 é 31,25%: arredonda para 31,3, não trunca');
        self::assertSame(3, $supino['sessions_count']);
        self::assertSame([40.0, 42.5, 45.0], $supino['values']);
        self::assertSame('0.0', $treinos[1]['exercises'][0]['change_pct']);
    }

    public function test_recorde_so_vale_quando_supera_o_que_a_pessoa_ja_tinha_feito_antes(): void
    {
        $this->mesTipico();

        $recordes = array_map(fn ($l) => $l['exercise']->name, $this->relatorio()['highlights']['records']);

        // Agachamento: 50 antes, 60 agora. Supino: 50 antes, 45 agora. Remada: nunca fez antes, então não é recorde.
        self::assertSame(['Agachamento'], $recordes);
    }

    public function test_destaques_ordenam_as_maiores_evolucoes_e_nao_incluem_quem_nao_melhorou(): void
    {
        $this->mesTipico();

        $evolucao = array_map(fn ($l) => $l['exercise']->name, $this->relatorio()['highlights']['evolution']);

        self::assertSame(['Remada', 'Supino'], $evolucao, 'o agachamento ficou em 0%');
    }

    public function test_exercicio_parado_vira_atencao(): void
    {
        $dias = ['2026-10-20', '2026-10-27', '2026-11-03', '2026-11-10', '2026-11-17'];
        foreach ($dias as $d) {
            $this->treinar($d, $this->treinoB, [$this->agachamento->id => '60']);
        }

        $atencao = $this->relatorio()['highlights']['attention'];

        self::assertSame('Agachamento', $atencao[0]['exercise']->name);
        self::assertSame('plateau', $atencao[0]['status']);
    }

    public function test_trocar_o_exercicio_no_plano_nao_muda_o_relatorio_do_que_ja_foi_feito(): void
    {
        $this->mesTipico();
        GymSession::query()->whereNull('finished_at')->delete(); // treino aberto com série no supino barraria a troca
        $novo = app(GymPlanService::class)->findOrCreateExercise('Supino com Halteres', GymMuscleGroup::Chest, GymMeasureType::LoadReps);

        // Depois do período, o plano troca o supino por outro exercício.
        app(GymPlanService::class)->swapExercise($this->supino->fresh(), $novo);

        $exercicios = array_map(fn ($l) => $l['exercise']->name, $this->relatorio()['workouts'][0]['exercises']);

        self::assertContains('Supino', $exercicios, 'o que foi feito continua no relatório');
        self::assertNotContains('Supino com Halteres', $exercicios);
    }

    public function test_o_treino_de_outra_fase_aparece_separado_com_o_nome_da_fase(): void
    {
        $this->mesTipico();
        $plano = app(GymPlanService::class);
        $fase2 = $plano->createPlan('Fase 2');
        $treino = $plano->addWorkout($fase2, 'Treino A', null);
        $item = $plano->addExercise($treino, $plano->findOrCreateExercise('Supino', GymMuscleGroup::Chest, GymMeasureType::LoadReps), ['target_sets' => 3]);
        $this->treinar('2026-11-19', $treino, [$item->id => '47.5']);

        $secoes = collect($this->relatorio()['workouts']);

        self::assertSame(['Fase 1', 'Fase 1', 'Fase 2'], $secoes->pluck('plan')->all());
        self::assertSame(['Treino A', 'Treino B', 'Treino A'], $secoes->pluck('name')->all());
    }

    // --- Tela, PDF e acesso ---------------------------------------------------------------------------------------------------------

    public function test_tela_mostra_resumo_destaques_e_cada_treino(): void
    {
        $this->mesTipico();

        Livewire::test(GymReport::class, ['periodo' => 'custom', 'inicio' => '2026-10-26', 'fim' => '2026-11-20'])
            ->assertSee('Relatório de evolução')->assertSee('Treinos realizados')->assertSee('75%')->assertSee('Constância')
            ->assertSee('Maiores evoluções')->assertSee('Recordes pessoais')->assertSee('Treino A')->assertSee('Peito e costas')
            ->assertSee('Supino')->assertSee('+31,3%')->assertSeeHtml('<svg');
    }

    public function test_periodo_desta_fase_comeca_onde_a_fase_comecou(): void
    {
        $this->mesTipico();

        // A fase começou em 26/10: o supino de setembro (50 kg) fica fora e a tela mostra os 5 treinos.
        Livewire::test(GymReport::class, ['periodo' => 'fase'])->assertSee('26/10/2026 a 20/11/2026')->assertSee('Desta fase');

        GymPlan::query()->update(['is_active' => false]);
        Livewire::test(GymReport::class, ['periodo' => 'fase'])->assertSet('periodo', '90');
    }

    public function test_atalhos_de_periodo_e_periodo_invalido_na_url(): void
    {
        $this->mesTipico();

        Livewire::test(GymReport::class, ['periodo' => '30'])->assertSee('22/10/2026 a 20/11/2026');
        Livewire::test(GymReport::class, ['periodo' => 'qualquer'])->assertSet('periodo', '90');
    }

    public function test_datas_invalidas_viram_aviso_e_nao_geram_pdf(): void
    {
        $this->mesTipico();

        Livewire::test(GymReport::class)
            ->set('periodo', 'custom')->set('inicio', '2026-11-20')->set('fim', '2026-11-01')
            ->assertSee('data final')->call('baixarPdf')->assertNoFileDownloaded();

        Livewire::test(GymReport::class)
            ->set('periodo', 'custom')->set('inicio', '2026-10-26')->set('fim', '2027-01-01')
            ->assertSee('data final')->call('baixarPdf')->assertNoFileDownloaded();
    }

    public function test_baixa_o_pdf_novo_pelo_relatorio_e_pelo_botao_antigo_da_evolucao(): void
    {
        $this->mesTipico();

        Livewire::test(GymReport::class, ['periodo' => 'custom', 'inicio' => '2026-10-26', 'fim' => '2026-11-20'])
            ->call('baixarPdf')->assertFileDownloaded('relatorio-academia-2026-10-26-a-2026-11-20.pdf');

        Livewire::test(GymProgress::class)
            ->set('inicioRelatorio', '2026-10-26')->set('fimRelatorio', '2026-11-20')
            ->call('baixarRelatorio')->assertFileDownloaded();
    }

    public function test_o_pdf_traz_os_graficos_como_desenho(): void
    {
        $this->mesTipico();
        $rel = $this->relatorio();

        $html = view('pdf.gym-report', [
            'relatorio' => $rel, 'progress' => app(\App\Services\GymProgressService::class),
            'membro' => app(ProfileContext::class)->member(), 'geradoEm' => now(),
        ])->render();

        self::assertStringContainsString('Resumo do período', $html);
        self::assertStringContainsString('Cada treino, exercício por exercício', $html);
        self::assertStringContainsString('data:image/svg+xml;base64,', $html);
        self::assertStringContainsString('+31,3%', $html);
        self::assertStringContainsString('recorde', $html);
    }

    public function test_consultor_leva_403_no_relatorio(): void
    {
        $consultor = User::factory()->consultant()->create();
        ConsultantClient::factory()->create(['consultant_id' => $consultor->id, 'client_id' => $this->usuario->id, 'status' => ConsultantClientStatus::Active]);

        $this->actingAs($consultor)->withSession(['cerne.active_profile_id' => $this->perfil->id])->get(route('health.gym.report'))->assertForbidden();
    }

    // --- Gráficos ------------------------------------------------------------------------------------------------------------------------

    public function test_graficos_svg_lidam_com_um_ponto_so_com_zeros_e_com_valores_iguais(): void
    {
        self::assertStringContainsString('<circle', GymReportCharts::spark([40.0]));
        self::assertStringNotContainsString('<polyline', GymReportCharts::spark([40.0]), 'um ponto não tem linha');
        self::assertStringContainsString('<polyline', GymReportCharts::spark([40.0, 40.0, 40.0]));
        self::assertSame('', GymReportCharts::spark([]));

        $barras = GymReportCharts::bars([['label' => '01/11', 'count' => 0], ['label' => '08/11', 'count' => 3]]);
        self::assertSame(2, substr_count($barras, '<rect'));
        self::assertStringContainsString('>3</text>', $barras);
        self::assertSame('', GymReportCharts::bars([]));
        self::assertStringStartsWith('data:image/svg+xml;base64,', GymReportCharts::dataUri($barras));
    }
}
