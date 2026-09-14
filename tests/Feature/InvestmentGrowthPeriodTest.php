<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Livewire\Consultant\PortfolioInvestments;
use App\Livewire\Investments\InvestmentsIndex;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\InvestmentRecord;
use App\Models\InvestmentSnapshot;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Filtro de período do "Ganho" (%) — pedido do usuário: "desde o
 * início" continua igual (custo de aquisição), mas agora dá pra ver
 * "este ano" (foto de janeiro), "este mês" (foto do dia 1 do mês
 * corrente) e "comparar dois meses" (foto de A vs. foto de B), nas duas
 * telas (cliente e consultor) — ver FiltersInvestmentGrowth.
 */
class InvestmentGrowthPeriodTest extends TestCase
{
    use RefreshDatabase;

    public function test_percentage_change_calcula_variacao_entre_dois_pontos(): void
    {
        self::assertSame(20.0, InvestmentRecord::percentageChange('1000.00', '1200.00'));
        self::assertSame(-10.0, InvestmentRecord::percentageChange('1000.00', '900.00'));
    }

    public function test_percentage_change_e_nulo_sem_base_ou_com_base_zero(): void
    {
        self::assertNull(InvestmentRecord::percentageChange(null, '1000.00'));
        self::assertNull(InvestmentRecord::percentageChange('0.00', '1000.00'));
        self::assertNull(InvestmentRecord::percentageChange('1000.00', null));
    }

    public function test_cliente_periodo_este_ano_compara_com_foto_de_janeiro(): void
    {
        $hoje = CarbonImmutable::now();
        [$perfil, $membro] = $this->criarPerfil();

        $ativo = InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['current_amount' => '1100.00']);

        InvestmentSnapshot::create([
            'profile_id' => $perfil->id,
            'investment_id' => $ativo->id,
            'year' => $hoje->year,
            'month' => 1,
            'amount' => '1000.00',
        ]);

        $pcts = Livewire::test(InvestmentsIndex::class)
            ->set('growthPeriod', 'ano')
            ->get('growthPercentages');

        self::assertSame(10.0, $pcts[$ativo->id]['pct']);
        self::assertSame('100.00', $pcts[$ativo->id]['ganho']);
    }

    public function test_cliente_periodo_este_mes_compara_com_foto_do_mes_corrente(): void
    {
        $hoje = CarbonImmutable::now();
        [$perfil, $membro] = $this->criarPerfil();

        $ativo = InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['current_amount' => '2000.00']);

        InvestmentSnapshot::create([
            'profile_id' => $perfil->id,
            'investment_id' => $ativo->id,
            'year' => $hoje->year,
            'month' => $hoje->month,
            'amount' => '1600.00',
        ]);

        $pcts = Livewire::test(InvestmentsIndex::class)
            ->set('growthPeriod', 'mes')
            ->get('growthPercentages');

        self::assertSame(25.0, $pcts[$ativo->id]['pct']);
    }

    public function test_cliente_sem_foto_no_mes_devolve_nulo_em_vez_de_numero_errado(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $ativo = InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')->create();

        $pcts = Livewire::test(InvestmentsIndex::class)
            ->set('growthPeriod', 'mes')
            ->get('growthPercentages');

        self::assertNull($pcts[$ativo->id]['pct']);
        self::assertNull($pcts[$ativo->id]['ganho']);
    }

    public function test_cliente_periodo_desde_o_inicio_continua_usando_investido(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $ativo = InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['current_amount' => '1500.00', 'invested_amount' => '1000.00']);

        $pcts = Livewire::test(InvestmentsIndex::class)
            ->get('growthPercentages');

        self::assertSame(50.0, $pcts[$ativo->id]['pct']);
        self::assertSame($ativo->gainPercentage(), $pcts[$ativo->id]['pct']);
    }

    public function test_cliente_comparar_dois_meses_usa_foto_de_a_e_de_b_sem_usar_agora(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $ativo = InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['current_amount' => '9999.00']); // não deve entrar na conta

        InvestmentSnapshot::create(['profile_id' => $perfil->id, 'investment_id' => $ativo->id, 'year' => 2026, 'month' => 2, 'amount' => '1000.00']);
        InvestmentSnapshot::create(['profile_id' => $perfil->id, 'investment_id' => $ativo->id, 'year' => 2026, 'month' => 5, 'amount' => '1300.00']);

        $pcts = Livewire::test(InvestmentsIndex::class)
            ->set('growthPeriod', 'comparar')
            ->set('growthMonthA', '2026-02')
            ->set('growthMonthB', '2026-05')
            ->get('growthPercentages');

        self::assertSame(30.0, $pcts[$ativo->id]['pct']);
        self::assertSame('300.00', $pcts[$ativo->id]['ganho']);
    }

    public function test_consultor_periodo_afeta_o_pct_de_cada_linha_agrupada(): void
    {
        $hoje = CarbonImmutable::now();
        $consultor = User::factory()->consultant()->create();
        $cliente = User::factory()->create();
        ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id,
            'client_id' => $cliente->id,
            'status' => ConsultantClientStatus::Active,
        ]);

        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $cliente->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $cliente->id]);
        $ativo = InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['current_amount' => '1210.00', 'institution' => 'XP Investimentos']);

        InvestmentSnapshot::create([
            'profile_id' => $perfil->id,
            'investment_id' => $ativo->id,
            'year' => $hoje->year,
            'month' => 1,
            'amount' => '1100.00',
        ]);

        $this->actingAs($consultor);
        $grouped = Livewire::test(PortfolioInvestments::class)
            ->set('growthPeriod', 'ano')
            ->viewData('grouped');

        $linha = $grouped->first()['membros']->first()['instituicoes']->first()->first();
        self::assertSame(10.0, $linha['pct']);
    }

    /**
     * Caso que motivou o pedido: o total da carteira não é a média dos
     * percentuais dos ativos — é a soma dos valores. Dois ativos de
     * tamanhos bem diferentes (R$ 1.000 e R$ 10.000), um subindo 10% e
     * outro caindo 10%: a média simples dos dois seria 0%, mas o
     * resultado de verdade pesa pelo tamanho de cada um.
     */
    public function test_crescimento_total_soma_valores_em_vez_de_tirar_media_dos_percentuais(): void
    {
        $hoje = CarbonImmutable::now();
        [$perfil, $membro] = $this->criarPerfil();

        $pequeno = InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['current_amount' => '1100.00']); // +10% sobre 1.000
        $grande = InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['current_amount' => '9000.00']); // -10% sobre 10.000

        foreach ([$pequeno->id => '1000.00', $grande->id => '10000.00'] as $id => $valor) {
            InvestmentSnapshot::create([
                'profile_id' => $perfil->id,
                'investment_id' => $id,
                'year' => $hoje->year,
                'month' => 1,
                'amount' => $valor,
            ]);
        }

        $total = Livewire::test(InvestmentsIndex::class)
            ->set('growthPeriod', 'ano')
            ->get('growthTotal');

        // (1100 + 9000 - 1000 - 10000) / 11000 = -900/11000 ≈ -8,18%
        self::assertEqualsWithDelta(-8.18, $total['pct'], 0.01);
        self::assertSame('-900.00', $total['ganho']);
    }

    /**
     * Ativo sem foto suficiente pro período não entra na soma nem a
     * favor nem contra — só os que têm os dois pontos contribuem.
     */
    public function test_crescimento_total_ignora_ativo_sem_foto_no_periodo(): void
    {
        $hoje = CarbonImmutable::now();
        [$perfil, $membro] = $this->criarPerfil();

        $comFoto = InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['current_amount' => '1100.00']);
        InvestmentSnapshot::create([
            'profile_id' => $perfil->id,
            'investment_id' => $comFoto->id,
            'year' => $hoje->year,
            'month' => 1,
            'amount' => '1000.00',
        ]);

        // Sem nenhuma foto — não deve puxar o total pra baixo nem pra cima.
        InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['current_amount' => '50000.00']);

        $total = Livewire::test(InvestmentsIndex::class)
            ->set('growthPeriod', 'ano')
            ->get('growthTotal');

        self::assertSame(10.0, $total['pct']);
        self::assertSame('100.00', $total['ganho']);
    }

    public function test_consultor_ve_o_crescimento_total_por_cliente_no_cabecalho_do_card(): void
    {
        $hoje = CarbonImmutable::now();
        $consultor = User::factory()->consultant()->create();
        $cliente = User::factory()->create(['name' => 'Hugo Lingiardi']);
        ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id,
            'client_id' => $cliente->id,
            'status' => ConsultantClientStatus::Active,
        ]);

        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $cliente->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $cliente->id]);

        $a = InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')->create(['current_amount' => '1100.00']);
        $b = InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')->create(['current_amount' => '2200.00']);

        InvestmentSnapshot::create(['profile_id' => $perfil->id, 'investment_id' => $a->id, 'year' => $hoje->year, 'month' => 1, 'amount' => '1000.00']);
        InvestmentSnapshot::create(['profile_id' => $perfil->id, 'investment_id' => $b->id, 'year' => $hoje->year, 'month' => 1, 'amount' => '2000.00']);

        $this->actingAs($consultor);
        $grouped = Livewire::test(PortfolioInvestments::class)
            ->set('growthPeriod', 'ano')
            ->viewData('grouped');

        $doCliente = $grouped->first();
        self::assertSame(10.0, $doCliente['crescimento']['pct']);
        self::assertSame('300.00', $doCliente['crescimento']['ganho']);
    }

    /** @return array{0: FinancialProfile, 1: ProfileMember} */
    private function criarPerfil(): array
    {
        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id]);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($perfil, $membro);

        return [$perfil, $membro];
    }
}
