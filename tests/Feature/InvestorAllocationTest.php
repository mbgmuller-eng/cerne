<?php

namespace Tests\Feature;

use App\Enums\AllocationAssetClass;
use App\Enums\AssetClass;
use App\Enums\EmploymentType;
use App\Enums\InvestmentSector;
use App\Enums\InvestorType;
use App\Enums\Necessity;
use App\Enums\ReserveType;
use App\Livewire\Investments\InvestmentsIndex;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\FinancialReserve;
use App\Models\InvestmentRecord;
use App\Models\InvestorProfile;
use App\Models\ProfileMember;
use App\Models\RecommendedAllocation;
use App\Models\User;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Card do perfil do investidor: alocação real da carteira (agrupada por
 * AllocationAssetClass) comparada à recomendada pelo consultor. Reserva
 * de emergência e previdência ficam FORA da comparação — não fazem parte
 * da alocação de investimento recomendada, têm meta própria.
 */
class InvestorAllocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_mapeia_classe_de_ativo_para_classe_de_alocacao(): void
    {
        self::assertSame(AllocationAssetClass::FixedIncome, AssetClass::Cdb->allocationClass());
        self::assertSame(AllocationAssetClass::EquitiesFiis, AssetClass::Fii->allocationClass());
        self::assertSame(AllocationAssetClass::DigitalAssets, AssetClass::Cripto->allocationClass());
        self::assertSame(AllocationAssetClass::Etfs, AssetClass::Etf->allocationClass());
        self::assertSame(AllocationAssetClass::International, AssetClass::AcaoExterior->allocationClass());
        self::assertNull(AssetClass::Previdencia->allocationClass());
    }

    public function test_alocacao_real_exclui_reserva_e_bate_o_percentual_recomendado(): void
    {
        [$perfil, $membro] = $this->criarPerfil();

        $investidor = InvestorProfile::create([
            'member_id' => $membro->id,
            'investor_type' => InvestorType::Moderate,
            'employment_type' => EmploymentType::PublicServant, // 6 meses
        ]);

        // Um único mês FECHADO de gasto essencial: R$ 1.000 — a média cai
        // nele (mês corrente nunca entra na média, ver regra em
        // InvestorProfile::essentialMonthlyAverage()).
        ExpenseRecord::factory()->for($perfil, 'profile')->create([
            'necessity' => Necessity::Essential,
            'amount' => '1000.00',
            'expense_date' => CarbonImmutable::now()->subMonth(),
        ]);

        RecommendedAllocation::create([
            'investor_profile_id' => $investidor->id,
            'asset_class' => AllocationAssetClass::FixedIncome,
            'target_percentage' => '60.00',
        ]);
        RecommendedAllocation::create([
            'investor_profile_id' => $investidor->id,
            'asset_class' => AllocationAssetClass::EquitiesFiis,
            'target_percentage' => '40.00',
        ]);

        InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')->create([
            'sector' => InvestmentSector::FixedIncome,
            'asset_class' => AssetClass::Cdb,
            'current_amount' => '600.00',
            'invested_amount' => '600.00',
        ]);
        InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')->create([
            'sector' => InvestmentSector::VariableIncome,
            'asset_class' => AssetClass::Acao,
            'current_amount' => '400.00',
            'invested_amount' => '400.00',
        ]);

        // Reserva não entra na comparação — some fora do total alocável,
        // mesmo sendo um CDB de verdade (a flag reserve_type é quem
        // decide isso, não a classe do ativo — ver AssetClass::
        // allocationClass()).
        InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')->create([
            'sector' => InvestmentSector::FixedIncome,
            'asset_class' => AssetClass::Cdb,
            'reserve_type' => ReserveType::Paz,
            'current_amount' => '3000.00',
            'invested_amount' => '3000.00',
        ]);
        FinancialReserve::create([
            'member_id' => $membro->id,
            'reserve_type' => ReserveType::Paz,
            'target_amount' => '3000.00',
            'current_amount' => '0.00',
        ]);

        $alocacoes = Livewire::test(InvestmentsIndex::class)->viewData('investorAllocations');

        self::assertCount(1, $alocacoes);
        $item = $alocacoes->first();

        self::assertSame('1000.00', $item['totalAlocavel']); // 600 + 400, sem a reserva
        self::assertSame('6000.00', $item['reservaSugerida']); // 1.000 de essencial x 6 meses (funcionário público)
        self::assertSame('3000.00', $item['reservaAtual']);

        $porClasse = $item['categorias']->keyBy(fn (array $c) => $c['classe']->value);
        self::assertEqualsWithDelta(60.0, $porClasse['fixed_income']['atualPct'], 0.01);
        self::assertSame(60.0, $porClasse['fixed_income']['recomendadoPct']);
        self::assertEqualsWithDelta(40.0, $porClasse['equities_fiis']['atualPct'], 0.01);
        self::assertSame(40.0, $porClasse['equities_fiis']['recomendadoPct']);
    }

    /**
     * Membro sem perfil ainda cadastrado precisa aparecer mesmo assim —
     * é o gancho pra tela oferecer o cadastro, não some da lista.
     */
    public function test_membro_sem_perfil_de_investidor_aparece_com_perfil_nulo(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')->create();

        $alocacoes = Livewire::test(InvestmentsIndex::class)->viewData('investorAllocations');

        self::assertCount(1, $alocacoes);
        $item = $alocacoes->first();
        self::assertNull($item['perfil']);
        self::assertSame('0.00', $item['reservaSugerida']);
        self::assertTrue($item['categorias']->isEmpty());
    }

    public function test_cada_tipo_de_investidor_soma_exatamente_100_por_cento(): void
    {
        foreach (InvestorType::cases() as $tipo) {
            $total = array_reduce($tipo->recommendedAllocations(), fn (string $c, string $v) => bcadd($c, $v, 2), '0.00');
            self::assertSame('100.00', $total, $tipo->label());
        }
    }

    public function test_salvar_perfil_de_investidor_sincroniza_a_carteira_recomendada_padrao(): void
    {
        [, $membro] = $this->criarPerfil();

        Livewire::test(InvestmentsIndex::class)
            ->call('toggleInvestorProfileForm', $membro->id)
            ->set('investorTypeInput', InvestorType::Moderate->value)
            ->set('employmentTypeInput', EmploymentType::Clt->value)
            ->call('saveInvestorProfile')
            ->assertHasNoErrors();

        $perfil = InvestorProfile::query()->where('member_id', $membro->id)->sole();
        $percentualDe = fn (AllocationAssetClass $classe) => $perfil->allocations->firstWhere('asset_class', $classe)?->target_percentage;

        self::assertSame('35.00', $percentualDe(AllocationAssetClass::FixedIncome));
        self::assertSame('20.00', $percentualDe(AllocationAssetClass::Funds));
        self::assertSame('20.00', $percentualDe(AllocationAssetClass::EquitiesFiis));
        self::assertSame('7.50', $percentualDe(AllocationAssetClass::DigitalAssets));
        self::assertSame('7.50', $percentualDe(AllocationAssetClass::FxCurrencies));
        self::assertSame('10.00', $percentualDe(AllocationAssetClass::Etfs));
        self::assertCount(6, $perfil->allocations);
        self::assertTrue($perfil->allocationIsValid());
    }

    public function test_trocar_tipo_de_investidor_atualiza_a_carteira_recomendada_sem_duplicar(): void
    {
        [, $membro] = $this->criarPerfil();

        Livewire::test(InvestmentsIndex::class)
            ->call('toggleInvestorProfileForm', $membro->id)
            ->set('investorTypeInput', InvestorType::Conservative->value)
            ->set('employmentTypeInput', EmploymentType::Clt->value)
            ->call('saveInvestorProfile');

        Livewire::test(InvestmentsIndex::class)
            ->call('toggleInvestorProfileForm', $membro->id)
            ->set('investorTypeInput', InvestorType::Aggressive->value)
            ->set('employmentTypeInput', EmploymentType::Clt->value)
            ->call('saveInvestorProfile');

        $perfil = InvestorProfile::query()->where('member_id', $membro->id)->sole();

        self::assertCount(6, $perfil->allocations);
        self::assertSame(
            '25.00',
            $perfil->allocations->firstWhere('asset_class', AllocationAssetClass::FixedIncome)->target_percentage,
        );
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
