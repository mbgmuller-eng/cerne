<?php

namespace Tests\Feature;

use App\Enums\AssetClass;
use App\Enums\PortfolioDisplayGroup;
use App\Enums\ReserveType;
use App\Livewire\Investments\InvestmentsIndex;
use App\Models\FinancialProfile;
use App\Models\InvestmentRecord;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Carteira por setor" deixou de agrupar pelo campo sector (só 5
 * valores, empilha quase tudo em renda fixa/variável) e passou a
 * agrupar por InvestmentRecord::displayGroup() — reserva de paz/
 * oportunidade vira grupo próprio (não some dentro de renda fixa,
 * mesmo sendo um CDB de verdade), e o resto usa a mesma classificação
 * fina da carteira recomendada (ver InvestorType::
 * recommendedAllocations()).
 */
class PortfolioDisplayGroupTest extends TestCase
{
    use RefreshDatabase;

    public function test_reserva_manda_mais_que_a_classe_do_ativo(): void
    {
        $paz = InvestmentRecord::factory()->make(['asset_class' => AssetClass::Cdb, 'reserve_type' => ReserveType::Paz]);
        $oportunidade = InvestmentRecord::factory()->make(['asset_class' => AssetClass::Tesouro, 'reserve_type' => ReserveType::Oportunidade]);

        self::assertSame(PortfolioDisplayGroup::ReservaPaz, $paz->displayGroup());
        self::assertSame(PortfolioDisplayGroup::ReservaOportunidade, $oportunidade->displayGroup());
    }

    public function test_previdencia_tem_grupo_proprio(): void
    {
        $previdencia = InvestmentRecord::factory()->make(['asset_class' => AssetClass::Previdencia]);

        self::assertSame(PortfolioDisplayGroup::Retirement, $previdencia->displayGroup());
    }

    public function test_demais_classes_seguem_a_alocacao_recomendada(): void
    {
        $casos = [
            [AssetClass::Cdb, PortfolioDisplayGroup::FixedIncome],
            [AssetClass::Fundo, PortfolioDisplayGroup::Funds],
            [AssetClass::Fii, PortfolioDisplayGroup::EquitiesFiis],
            [AssetClass::Cripto, PortfolioDisplayGroup::DigitalAssets],
            [AssetClass::Etf, PortfolioDisplayGroup::Etfs],
            [AssetClass::AcaoExterior, PortfolioDisplayGroup::International],
            [AssetClass::Poupanca, PortfolioDisplayGroup::Other],
        ];

        foreach ($casos as [$classe, $esperado]) {
            $ativo = InvestmentRecord::factory()->make(['asset_class' => $classe]);
            self::assertSame($esperado, $ativo->displayGroup(), $classe->value);
        }
    }

    public function test_tela_agrupa_reserva_separada_de_renda_fixa_na_ordem_certa(): void
    {
        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id]);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($perfil, $membro);

        InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')->create([
            'asset_class' => AssetClass::Cdb,
            'reserve_type' => ReserveType::Paz,
            'current_amount' => '5000.00',
        ]);
        InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')->create([
            'asset_class' => AssetClass::Cdb,
            'reserve_type' => null,
            'current_amount' => '3000.00',
        ]);
        InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')->create([
            'asset_class' => AssetClass::Etf,
            'current_amount' => '1000.00',
        ]);

        $byGroup = Livewire::test(InvestmentsIndex::class)->get('byGroup');

        self::assertSame(
            [PortfolioDisplayGroup::ReservaPaz->value, PortfolioDisplayGroup::FixedIncome->value, PortfolioDisplayGroup::Etfs->value],
            $byGroup->keys()->all(),
        );
        self::assertCount(1, $byGroup[PortfolioDisplayGroup::ReservaPaz->value]);
        self::assertCount(1, $byGroup[PortfolioDisplayGroup::FixedIncome->value]);
        self::assertSame('5000.00', $byGroup[PortfolioDisplayGroup::ReservaPaz->value]->first()->current_amount);
    }
}
