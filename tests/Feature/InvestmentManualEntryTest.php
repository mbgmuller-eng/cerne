<?php

namespace Tests\Feature;

use App\Enums\AssetClass;
use App\Enums\InvestmentSector;
use App\Livewire\Investments\InvestmentsIndex;
use App\Models\FinancialProfile;
use App\Models\InvestmentRecord;
use App\Models\InvestmentTransaction;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cadastro manual de investimento: ativo com cota (ação, FII, ETF...)
 * nasce de uma transação de compra de verdade (preço médio calculado
 * pelo InvestmentTransactionService); ativo sem cota (CDB, Tesouro...)
 * entra direto com o valor informado.
 */
class InvestmentManualEntryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Os 3 campos que trocam de significado (mesma posição no grid, nome
     * de propriedade diferente) entre "ativo com cota" e "ativo sem
     * cota" precisam de wire:key — sem isso, o morph do Livewire reaproveita
     * o nó do DOM e o listener antigo fica preso à propriedade errada, e o
     * valor digitado vaza pro campo anterior (bug real, reproduzido e
     * confirmado via inspeção do payload de rede — não é só teoria).
     */
    public function test_campos_que_trocam_de_significado_tem_wire_key(): void
    {
        $this->criarPerfil();

        $html = Livewire::test(InvestmentsIndex::class)
            ->set('showInvestmentForm', true)
            ->set('investmentAssetClass', AssetClass::Acao->value)
            ->html();

        self::assertStringContainsString('wire:key="investment-field-quantity"', $html);
        self::assertStringContainsString('wire:key="investment-field-unit-price"', $html);
        self::assertStringContainsString('wire:key="investment-field-current-amount-cotas"', $html);

        $html = Livewire::test(InvestmentsIndex::class)
            ->set('showInvestmentForm', true)
            ->set('investmentAssetClass', AssetClass::Cdb->value)
            ->html();

        self::assertStringContainsString('wire:key="investment-field-current-amount-plain"', $html);
        self::assertStringContainsString('wire:key="investment-field-invested-amount"', $html);
        self::assertStringContainsString('wire:key="investment-field-return-rate"', $html);
    }

    public function test_cadastra_ativo_sem_cota_direto_com_valor_atual_e_investido(): void
    {
        [$perfil, $membro] = $this->criarPerfil();

        Livewire::test(InvestmentsIndex::class)
            ->set('investmentName', 'CDB Inter 2028')
            ->set('investmentAssetClass', AssetClass::Cdb->value)
            ->set('investmentMemberId', $membro->id)
            ->set('investmentInstitution', 'Inter')
            ->set('investmentCurrentAmount', '10000.00')
            ->set('investmentInvestedAmount', '9500.00')
            ->set('investmentReturnRate', 'CDI 112%')
            ->call('saveInvestment')
            ->assertHasNoErrors();

        $investimento = InvestmentRecord::query()->where('name', 'CDB Inter 2028')->sole();
        self::assertSame($perfil->id, $investimento->profile_id);
        self::assertSame($membro->id, $investimento->member_id);
        self::assertSame(InvestmentSector::FixedIncome, $investimento->sector);
        self::assertSame('10000.00', $investimento->current_amount);
        self::assertSame('9500.00', $investimento->invested_amount);
        self::assertNull($investimento->quantity);
    }

    public function test_ativo_sem_cota_sem_investido_informado_usa_o_valor_atual(): void
    {
        [, $membro] = $this->criarPerfil();

        Livewire::test(InvestmentsIndex::class)
            ->set('investmentName', 'Tesouro Selic')
            ->set('investmentAssetClass', AssetClass::Tesouro->value)
            ->set('investmentMemberId', $membro->id)
            ->set('investmentCurrentAmount', '5000.00')
            ->call('saveInvestment')
            ->assertHasNoErrors();

        $investimento = InvestmentRecord::query()->where('name', 'Tesouro Selic')->sole();
        self::assertSame('5000.00', $investimento->invested_amount);
    }

    public function test_cadastra_ativo_com_cota_via_transacao_de_compra(): void
    {
        [, $membro] = $this->criarPerfil();

        Livewire::test(InvestmentsIndex::class)
            ->set('investmentName', 'Petrobras PN')
            ->set('investmentTicker', 'PETR4')
            ->set('investmentAssetClass', AssetClass::Acao->value)
            ->set('investmentMemberId', $membro->id)
            ->set('investmentQuantity', '100')
            ->set('investmentUnitPrice', '32.50')
            ->call('saveInvestment')
            ->assertHasNoErrors();

        $investimento = InvestmentRecord::query()->where('ticker', 'PETR4')->sole();
        self::assertSame(InvestmentSector::VariableIncome, $investimento->sector);
        self::assertSame('100.000000', $investimento->quantity);
        self::assertSame('32.500000', $investimento->average_price);
        self::assertSame('3250.00', $investimento->invested_amount);
        // Sem valor de mercado informado, current_amount = qtd x preço de compra.
        self::assertSame('3250.00', $investimento->current_amount);

        $transacao = InvestmentTransaction::query()->where('investment_id', $investimento->id)->sole();
        self::assertSame('100.000000', $transacao->quantity);
        self::assertSame('32.500000', $transacao->unit_price);
    }

    public function test_ativo_com_cota_com_valor_de_mercado_informado_diverge_do_investido(): void
    {
        [, $membro] = $this->criarPerfil();

        Livewire::test(InvestmentsIndex::class)
            ->set('investmentName', 'CSHG Logística')
            ->set('investmentTicker', 'HGLG11')
            ->set('investmentAssetClass', AssetClass::Fii->value)
            ->set('investmentMemberId', $membro->id)
            ->set('investmentQuantity', '80')
            ->set('investmentUnitPrice', '150.00')
            ->set('investmentCurrentAmount', '13500.00')
            ->call('saveInvestment')
            ->assertHasNoErrors();

        $investimento = InvestmentRecord::query()->where('ticker', 'HGLG11')->sole();
        self::assertSame('12000.00', $investimento->invested_amount); // 80 x 150
        self::assertSame('13500.00', $investimento->current_amount); // valorizou
    }

    public function test_ativo_com_cota_exige_quantidade_e_preco(): void
    {
        [, $membro] = $this->criarPerfil();

        Livewire::test(InvestmentsIndex::class)
            ->set('investmentName', 'Ação sem dados')
            ->set('investmentAssetClass', AssetClass::Acao->value)
            ->set('investmentMemberId', $membro->id)
            ->call('saveInvestment')
            ->assertHasErrors(['investmentQuantity', 'investmentUnitPrice']);

        self::assertSame(0, InvestmentRecord::query()->where('name', 'Ação sem dados')->count());
    }

    public function test_membro_de_outro_perfil_nao_e_aceito(): void
    {
        $this->criarPerfil();

        $outroPerfil = FinancialProfile::factory()->create();
        $membroDeOutroPerfil = ProfileMember::factory()->create(['profile_id' => $outroPerfil->id]);

        Livewire::test(InvestmentsIndex::class)
            ->set('investmentName', 'Tentativa')
            ->set('investmentAssetClass', AssetClass::Cdb->value)
            ->set('investmentMemberId', $membroDeOutroPerfil->id)
            ->set('investmentCurrentAmount', '1000.00')
            ->call('saveInvestment')
            ->assertHasErrors(['investmentMemberId']);

        self::assertSame(0, InvestmentRecord::query()->where('name', 'Tentativa')->count());
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
