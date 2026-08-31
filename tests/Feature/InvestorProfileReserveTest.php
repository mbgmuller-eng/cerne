<?php

namespace Tests\Feature;

use App\Enums\EmploymentType;
use App\Enums\InvestorType;
use App\Enums\Necessity;
use App\Enums\ReserveType;
use App\Livewire\Investments\InvestmentsIndex;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\FinancialReserve;
use App\Models\InvestorProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tamanho da reserva: média dos gastos essenciais (até 12 meses com dado)
 * x meses conforme o tipo de atuação; reserva de oportunidade é sempre
 * 1/3 da reserva de paz. Casal ou solteiro, todo membro com perfil de
 * investidor cadastrado ganha as duas reservas.
 */
class InvestorProfileReserveTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_essencial_usa_apenas_os_12_meses_mais_recentes(): void
    {
        [$perfil, $membro] = $this->criarPerfil();

        // 14 meses de gasto essencial, 100 a 1400 — os 2 mais antigos
        // (1300 e 1400) precisam ficar de fora da média. Ancorado no
        // início do mês pra "hoje" não empurrar dois $i pro mesmo mês por
        // overflow de dia (ex.: 31/08 - 2 meses "transborda" pra 01/07,
        // colidindo com 31/08 - 1 mês = 31/07).
        for ($i = 1; $i <= 14; $i++) {
            ExpenseRecord::factory()->for($perfil, 'profile')->create([
                'necessity' => Necessity::Essential,
                'amount' => number_format($i * 100, 2, '.', ''),
                'expense_date' => CarbonImmutable::now()->startOfMonth()->subMonths($i),
            ]);
        }

        $investidor = InvestorProfile::create([
            'member_id' => $membro->id,
            'investor_type' => InvestorType::Moderate,
            'employment_type' => EmploymentType::Clt,
        ]);

        // Média dos 12 mais recentes: 100..1200 -> (100+1200)/2 = 650.
        self::assertSame('650.00', $investidor->essentialMonthlyAverage());
    }

    /**
     * Mês corrente pode ainda receber lançamento (a média oscilaria dia
     * a dia) e mês futuro é parcela programada, não gasto essencial
     * médio de verdade — os dois ficam de fora, só meses fechados
     * (passados) entram na média.
     */
    public function test_media_essencial_ignora_mes_corrente_e_meses_futuros(): void
    {
        [$perfil, $membro] = $this->criarPerfil();

        ExpenseRecord::factory()->for($perfil, 'profile')->create([
            'necessity' => Necessity::Essential,
            'amount' => '9999.00',
            'expense_date' => CarbonImmutable::now(), // mês corrente — fora
        ]);
        ExpenseRecord::factory()->for($perfil, 'profile')->create([
            'necessity' => Necessity::Essential,
            'amount' => '9999.00',
            'expense_date' => CarbonImmutable::now()->addMonth(), // futuro — fora
        ]);
        ExpenseRecord::factory()->for($perfil, 'profile')->create([
            'necessity' => Necessity::Essential,
            'amount' => '1000.00',
            'expense_date' => CarbonImmutable::now()->subMonth(), // mês fechado — dentro
        ]);

        $investidor = InvestorProfile::create([
            'member_id' => $membro->id,
            'investor_type' => InvestorType::Moderate,
            'employment_type' => EmploymentType::Clt,
        ]);

        self::assertSame('1000.00', $investidor->essentialMonthlyAverage());
    }

    public function test_meses_sem_gasto_essencial_nao_entram_na_media(): void
    {
        [$perfil, $membro] = $this->criarPerfil();

        ExpenseRecord::factory()->for($perfil, 'profile')->create([
            'necessity' => Necessity::Essential,
            'amount' => '1000.00',
            'expense_date' => CarbonImmutable::now()->subMonth(),
        ]);
        // Supérfluo não conta pra média essencial.
        ExpenseRecord::factory()->for($perfil, 'profile')->create([
            'necessity' => Necessity::Discretionary,
            'amount' => '5000.00',
            'expense_date' => CarbonImmutable::now()->subMonth(),
        ]);

        $investidor = InvestorProfile::create([
            'member_id' => $membro->id,
            'investor_type' => InvestorType::Moderate,
            'employment_type' => EmploymentType::Clt,
        ]);

        self::assertSame('1000.00', $investidor->essentialMonthlyAverage());
    }

    public function test_meta_da_reserva_de_paz_multiplica_pelo_tipo_de_atuacao(): void
    {
        [$perfil, $membro] = $this->criarPerfil();

        ExpenseRecord::factory()->for($perfil, 'profile')->create([
            'necessity' => Necessity::Essential,
            'amount' => '1000.00',
            'expense_date' => CarbonImmutable::now()->subMonth(),
        ]);

        $casos = [
            'public_servant' => '6000.00',
            'clt' => '9000.00',
            'self_employed' => '12000.00',
        ];

        foreach ($casos as $tipoValor => $metaEsperada) {
            $investidor = InvestorProfile::updateOrCreate(
                ['member_id' => $membro->id],
                ['investor_type' => InvestorType::Moderate, 'employment_type' => EmploymentType::from($tipoValor)],
            );

            self::assertSame($metaEsperada, $investidor->peaceReserveTarget(), $tipoValor);
        }
    }

    public function test_reserva_de_oportunidade_e_um_terco_da_reserva_de_paz(): void
    {
        [$perfil, $membro] = $this->criarPerfil();

        ExpenseRecord::factory()->for($perfil, 'profile')->create([
            'necessity' => Necessity::Essential,
            'amount' => '1000.00',
            'expense_date' => CarbonImmutable::now()->subMonth(),
        ]);

        $investidor = InvestorProfile::create([
            'member_id' => $membro->id,
            'investor_type' => InvestorType::Moderate,
            'employment_type' => EmploymentType::Clt,
        ]);

        self::assertSame('9000.00', $investidor->peaceReserveTarget());
        self::assertSame('3000.00', $investidor->opportunityReserveTarget());
    }

    /**
     * Dois provedores no mesmo perfil (cada um com perfil de investidor
     * e tipo de atuação definidos) dividem o gasto essencial da casa
     * entre si antes de multiplicar — cada um cobre a sua fatia, com o
     * PRÓPRIO multiplicador. Isso é sobre quantos provedores sustentam
     * a casa, não sobre privacidade entre o casal (ProfileAccessSettings
     * é outro assunto, independente deste).
     */
    public function test_dois_provedores_dividem_o_gasto_essencial_antes_de_multiplicar(): void
    {
        [$perfil, $ana] = $this->criarPerfil();
        $bruno = ProfileMember::factory()->create(['profile_id' => $perfil->id]);

        ExpenseRecord::factory()->for($perfil, 'profile')->create([
            'necessity' => Necessity::Essential,
            'amount' => '8000.00', // custo essencial da casa inteira
            'expense_date' => CarbonImmutable::now()->subMonth(),
        ]);

        $perfilAna = InvestorProfile::create([
            'member_id' => $ana->id,
            'investor_type' => InvestorType::Moderate,
            'employment_type' => EmploymentType::Clt, // 9 meses
        ]);
        $perfilBruno = InvestorProfile::create([
            'member_id' => $bruno->id,
            'investor_type' => InvestorType::Conservative,
            'employment_type' => EmploymentType::SelfEmployed, // 12 meses
        ]);

        // Fatia de cada um: 8000 / 2 = 4000.
        self::assertSame('36000.00', $perfilAna->peaceReserveTarget()); // 4000 x 9
        self::assertSame('48000.00', $perfilBruno->peaceReserveTarget()); // 4000 x 12
    }

    /**
     * Só um provedor com tipo de atuação definido no perfil — mesmo
     * sendo casal, ninguém mais está cobrindo metade do essencial, então
     * a meta continua cheia (mesma regra do solteiro).
     */
    public function test_casal_com_apenas_um_provedor_nao_divide(): void
    {
        [$perfil, $ana] = $this->criarPerfil();
        $bruno = ProfileMember::factory()->create(['profile_id' => $perfil->id]);

        ExpenseRecord::factory()->for($perfil, 'profile')->create([
            'necessity' => Necessity::Essential,
            'amount' => '8000.00',
            'expense_date' => CarbonImmutable::now()->subMonth(),
        ]);

        $perfilAna = InvestorProfile::create([
            'member_id' => $ana->id,
            'investor_type' => InvestorType::Moderate,
            'employment_type' => EmploymentType::Clt,
        ]);
        // Bruno tem perfil de investidor, mas ainda não informou o tipo
        // de atuação — não conta como segundo provedor ainda.
        InvestorProfile::create([
            'member_id' => $bruno->id,
            'investor_type' => InvestorType::Conservative,
        ]);

        self::assertSame('72000.00', $perfilAna->peaceReserveTarget()); // 8000 x 9, sem dividir
    }

    public function test_sem_tipo_de_atuacao_a_meta_e_zero(): void
    {
        [, $membro] = $this->criarPerfil();

        $investidor = InvestorProfile::create([
            'member_id' => $membro->id,
            'investor_type' => InvestorType::Moderate,
        ]);

        self::assertSame('0.00', $investidor->peaceReserveTarget());
        self::assertSame('0.00', $investidor->opportunityReserveTarget());
    }

    public function test_financial_reserve_sem_perfil_de_investidor_usa_o_valor_manual(): void
    {
        [$perfil, $membro] = $this->criarPerfil();

        $reserva = FinancialReserve::create([
            'profile_id' => $perfil->id,
            'member_id' => $membro->id,
            'reserve_type' => ReserveType::Paz,
            'target_amount' => '15000.00',
        ]);

        self::assertSame('15000.00', $reserva->targetAmount());
    }

    /**
     * Salvar o perfil (criar ou editar) garante as duas reservas do
     * membro — casal ou solteiro, sempre paz E oportunidade. Chamar de
     * novo (edição) não duplica: o índice único segura a repetição.
     */
    public function test_salvar_perfil_garante_as_duas_reservas_do_membro(): void
    {
        [, $membro] = $this->criarPerfil();

        $componente = Livewire::test(InvestmentsIndex::class)
            ->call('toggleInvestorProfileForm', $membro->id)
            ->set('investorTypeInput', InvestorType::Moderate->value)
            ->set('employmentTypeInput', EmploymentType::Clt->value)
            ->call('saveInvestorProfile')
            ->assertHasNoErrors();

        self::assertSame(2, FinancialReserve::query()->where('member_id', $membro->id)->count());
        self::assertNotNull(FinancialReserve::query()->where('member_id', $membro->id)->where('reserve_type', ReserveType::Paz)->first());
        self::assertNotNull(FinancialReserve::query()->where('member_id', $membro->id)->where('reserve_type', ReserveType::Oportunidade)->first());

        // Editar de novo não duplica.
        $componente
            ->call('toggleInvestorProfileForm', $membro->id)
            ->set('investorTypeInput', InvestorType::Aggressive->value)
            ->set('employmentTypeInput', EmploymentType::SelfEmployed->value)
            ->call('saveInvestorProfile')
            ->assertHasNoErrors();

        self::assertSame(2, FinancialReserve::query()->where('member_id', $membro->id)->count());
        self::assertSame(1, InvestorProfile::query()->where('member_id', $membro->id)->count());
        self::assertSame(InvestorType::Aggressive, InvestorProfile::query()->where('member_id', $membro->id)->first()->investor_type);
    }

    public function test_salvar_perfil_sem_tipo_de_atuacao_falha_a_validacao(): void
    {
        [, $membro] = $this->criarPerfil();

        Livewire::test(InvestmentsIndex::class)
            ->call('toggleInvestorProfileForm', $membro->id)
            ->set('investorTypeInput', InvestorType::Moderate->value)
            ->set('employmentTypeInput', '')
            ->call('saveInvestorProfile')
            ->assertHasErrors(['employmentTypeInput']);

        self::assertSame(0, InvestorProfile::query()->where('member_id', $membro->id)->count());
    }

    /**
     * Escala de cor por percentual completado — cinza (0–25%), vermelho
     * leve (25–50%), amarelo (50–75%), verde (75%+). Usa o fallback
     * `target_amount` manual (sem InvestorProfile) só pra controlar o
     * percentual com precisão nos limites das faixas.
     */
    public function test_escala_de_cor_da_reserva_segue_o_percentual_completado(): void
    {
        [$perfil, $membro] = $this->criarPerfil();

        $casos = [
            ['atual' => '10.00', 'esperado' => 'bg-slate-300 dark:bg-slate-500'],   // 10%
            ['atual' => '24.99', 'esperado' => 'bg-slate-300 dark:bg-slate-500'],   // logo abaixo de 25%
            ['atual' => '25.00', 'esperado' => 'bg-red-300 dark:bg-red-400/70'],    // exatamente 25%
            ['atual' => '40.00', 'esperado' => 'bg-red-300 dark:bg-red-400/70'],    // 40%
            ['atual' => '50.00', 'esperado' => 'bg-amber-500 dark:bg-amber-400'],   // exatamente 50%
            ['atual' => '65.00', 'esperado' => 'bg-amber-500 dark:bg-amber-400'],   // 65%
            ['atual' => '75.00', 'esperado' => 'bg-emerald-600 dark:bg-emerald-400'], // exatamente 75%
            ['atual' => '100.00', 'esperado' => 'bg-emerald-600 dark:bg-emerald-400'], // completa
        ];

        foreach ($casos as $caso) {
            $reserva = FinancialReserve::create([
                'profile_id' => $perfil->id,
                'member_id' => $membro->id,
                'reserve_type' => ReserveType::Paz,
                'target_amount' => '100.00',
                'current_amount' => $caso['atual'],
            ]);

            self::assertSame($caso['esperado'], $reserva->progressBarColorClass(), $caso['atual'].'%');

            $reserva->delete();
        }
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
