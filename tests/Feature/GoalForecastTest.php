<?php

namespace Tests\Feature;

use App\Enums\FundingMethod;
use App\Enums\GoalStatus;
use App\Enums\MemberRole;
use App\Livewire\Goals\GoalsIndex;
use App\Models\FinancialProfile;
use App\Models\Goal;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Previsão do objetivo com "quanto vou guardar por mês": em que mês chega, se é antes ou depois da data-alvo e quanto falta aumentar. A mesma
 * frase aparece na lista e no aviso "Ao salvar" (Goal::forecastLine). Hoje é 20/10/2026.
 */
class GoalForecastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('pt_BR');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 09:00:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function objetivo(array $campos): Goal
    {
        $g = (new Goal)->forceFill(array_merge([
            'estimated_value' => '10000.00', 'current_amount' => '0.00', 'installment_amount' => '1000.00', 'target_date' => null,
            'status' => GoalStatus::Active, 'funding_method' => FundingMethod::LumpSum,
        ], $campos));
        $g->setRelation('linkedInvestment', null);
        $g->setRelation('linkedDebt', null);

        return $g;
    }

    public function test_sem_aporte_informado_nao_ha_previsao(): void
    {
        self::assertNull($this->objetivo(['installment_amount' => null])->forecast());
        self::assertNull($this->objetivo(['installment_amount' => '0.00'])->forecastLine());
    }

    public function test_sem_data_alvo_diz_so_quando_chega(): void
    {
        $g = $this->objetivo([]);

        self::assertSame(10, $g->forecast()['months']);
        self::assertNull($g->forecast()['on_track']);
        self::assertSame('No ritmo de R$ 1.000,00 por mês, chega em 08/2027.', $g->forecastLine());
    }

    public function test_o_que_sobra_conta_um_mes_a_mais(): void
    {
        self::assertSame(11, $this->objetivo(['estimated_value' => '10500.00'])->forecast()['months']);
        self::assertSame(10, $this->objetivo(['estimated_value' => '10000.00'])->forecast()['months']);
        // Quanto já tem entra: faltam 2.500 de 10.000 e o aporte é 1.000 -> 3 meses.
        self::assertSame(3, $this->objetivo(['current_amount' => '7500.00'])->forecast()['months']);
    }

    public function test_antes_da_data_alvo_esta_no_ritmo(): void
    {
        $g = $this->objetivo(['target_date' => '2027-12-20']);

        self::assertTrue($g->forecast()['on_track']);
        self::assertNull($g->forecast()['extra_needed']);
        self::assertSame('No ritmo de R$ 1.000,00 por mês, chega em 08/2027, antes da data-alvo.', $g->forecastLine());
    }

    public function test_depois_da_data_alvo_diz_os_meses_de_atraso_e_quanto_aumentar(): void
    {
        // 8 meses até 20/06/2027; faltam 10.000, então o preciso é 1.250 por mês: faltam 250. No ritmo de 1.000 chega em 10 meses (2 depois).
        $g = $this->objetivo(['target_date' => '2027-06-20']);
        $f = $g->forecast();

        self::assertFalse($f['on_track']);
        self::assertSame(2, $f['months_late']);
        self::assertSame('250.00', $f['extra_needed']);
        self::assertSame('No ritmo de R$ 1.000,00 por mês, chega em 08/2027, 2 meses depois da data-alvo. Para chegar a tempo, guarde R$ 250,00 por mês.', $g->forecastLine());
    }

    public function test_um_mes_de_atraso_fica_no_singular(): void
    {
        // 9 meses até a data-alvo; 10 de aporte: 1 mês depois.
        $g = $this->objetivo(['target_date' => '2027-07-20']);

        self::assertStringContainsString('1 mês depois da data-alvo.', (string) $g->forecastLine());
    }

    public function test_data_alvo_que_ja_passou_so_diz_que_e_depois(): void
    {
        $g = $this->objetivo(['target_date' => '2026-01-10']);

        self::assertFalse($g->forecast()['on_track']);
        self::assertNull($g->forecast()['months_late']);
        self::assertSame('No ritmo de R$ 1.000,00 por mês, chega em 08/2027, depois da data-alvo.', $g->forecastLine());
    }

    public function test_ritmo_longo_demais_vira_um_aviso_em_vez_de_uma_data_absurda(): void
    {
        $g = $this->objetivo(['estimated_value' => '1000000.00', 'installment_amount' => '100.00']);

        self::assertTrue($g->forecast()['too_long']);
        self::assertSame('No ritmo de R$ 100,00 por mês, o objetivo levaria mais de 50 anos. Aumente o valor guardado por mês.', $g->forecastLine());
    }

    public function test_objetivo_alcancado_e_o_ligado_a_consorcio_nao_tem_previsao(): void
    {
        self::assertNull($this->objetivo(['current_amount' => '10000.00'])->forecast());
        self::assertNull($this->objetivo(['status' => GoalStatus::Achieved])->forecast());
    }

    // ------------------------------------------------------------------ na tela

    public function test_o_formulario_guarda_o_aporte_e_o_aviso_ao_salvar_traz_a_previsao(): void
    {
        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id, 'role' => MemberRole::Primary, 'name' => 'Ana']);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($perfil, $membro);

        $tela = Livewire::test(GoalsIndex::class)->call('openForm')
            ->set('name', 'Reserva')->set('estimatedValue', '10000,00')->set('targetDate', '2027-06-20')->set('installmentAmount', '1000,00')
            ->assertSee('Quanto vou guardar por mês')
            ->assertSee('No ritmo de R$ 1.000,00 por mês, chega em 08/2027, 2 meses depois da data-alvo. Para chegar a tempo, guarde R$ 250,00 por mês.');

        $tela->call('save')->assertHasNoErrors();

        $objetivo = Goal::query()->sole();
        self::assertSame('1000.00', $objetivo->installment_amount);

        // A lista mostra a mesma frase.
        Livewire::test(GoalsIndex::class)->assertSee('chega em 08/2027, 2 meses depois da data-alvo.');
    }
}
