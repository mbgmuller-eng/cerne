<?php

namespace Tests\Feature;

use App\Enums\AssetClass;
use App\Enums\InvestmentSector;
use App\Livewire\Investments\InvestmentsIndex;
use App\Models\FinancialProfile;
use App\Models\InvestmentRecord;
use App\Models\InvestmentSnapshot;
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

    public function test_investimento_marcado_oculto_grava_is_private(): void
    {
        [, $membro] = $this->criarPerfil();

        Livewire::test(InvestmentsIndex::class)
            ->set('investmentName', 'Fundo sigiloso')
            ->set('investmentAssetClass', AssetClass::Cdb->value)
            ->set('investmentMemberId', $membro->id)
            ->set('investmentCurrentAmount', '2000.00')
            ->set('investmentIsPrivate', true)
            ->call('saveInvestment')
            ->assertHasNoErrors();

        $investimento = InvestmentRecord::withoutProfileScope()->where('name', 'Fundo sigiloso')->sole();
        self::assertTrue($investimento->is_private);
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

    public function test_editar_carrega_os_dados_no_formulario(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $investimento = InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')->create([
            'name' => 'CDB Original',
            'asset_class' => AssetClass::Cdb,
            'current_amount' => '8000.00',
            'invested_amount' => '7500.00',
        ]);

        Livewire::test(InvestmentsIndex::class)
            ->call('editInvestment', $investimento->id)
            ->assertSet('editingInvestmentId', $investimento->id)
            ->assertSet('investmentName', 'CDB Original')
            ->assertSet('investmentAssetClass', AssetClass::Cdb->value)
            ->assertSet('investmentCurrentAmount', '8000.00')
            ->assertSet('investmentValueDate', now()->toDateString())
            ->assertSet('investmentInvestedAmount', '7500.00')
            ->assertSet('showInvestmentForm', true);
    }

    public function test_editar_atualiza_um_ativo_sem_cota_sem_criar_linha_nova(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $investimento = InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')->create([
            'name' => 'CDB Original',
            'asset_class' => AssetClass::Cdb,
            'institution' => 'Banco Antigo',
            'current_amount' => '8000.00',
            'invested_amount' => '7500.00',
        ]);

        Livewire::test(InvestmentsIndex::class)
            ->call('editInvestment', $investimento->id)
            ->set('investmentInstitution', 'Banco Novo')
            ->set('investmentCurrentAmount', '8500.00')
            ->call('saveInvestment')
            ->assertHasNoErrors();

        self::assertSame(1, InvestmentRecord::query()->count());
        $investimento->refresh();
        self::assertSame('Banco Novo', $investimento->institution);
        self::assertSame('8500.00', $investimento->current_amount);
        self::assertSame('7500.00', $investimento->invested_amount); // não mexeu
    }

    public function test_editar_ativo_com_cota_nao_mexe_em_quantidade_nem_preco_medio(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $investimento = InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')->create([
            'name' => 'Petrobras PN',
            'ticker' => 'PETR4',
            'asset_class' => AssetClass::Acao,
            'quantity' => '100.000000',
            'average_price' => '32.500000',
            'current_amount' => '3250.00',
            'invested_amount' => '3250.00',
        ]);

        Livewire::test(InvestmentsIndex::class)
            ->call('editInvestment', $investimento->id)
            ->set('investmentCurrentAmount', '4000.00') // valorizou
            ->call('saveInvestment')
            ->assertHasNoErrors();

        self::assertSame(0, InvestmentTransaction::query()->count()); // não criou transação nova
        $investimento->refresh();
        self::assertSame('100.000000', $investimento->quantity);
        self::assertSame('32.500000', $investimento->average_price);
        self::assertSame('4000.00', $investimento->current_amount);
    }

    /**
     * A tela de Evolução do patrimônio (aba Performance) é construída em
     * cima de InvestmentSnapshot — sem gravar uma foto aqui, atualizar o
     * valor à mão só apareceria no gráfico na próxima captura automática
     * do dia 1 (InvestmentSnapshotService::captureMonth()), o que deixaria
     * a curva desatualizada por semanas.
     */
    public function test_editar_valor_atual_grava_a_foto_do_mes_pro_grafico_de_evolucao(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $investimento = InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')->create([
            'current_amount' => '8000.00',
        ]);

        Livewire::test(InvestmentsIndex::class)
            ->call('editInvestment', $investimento->id)
            ->set('investmentCurrentAmount', '8500.00')
            ->set('investmentValueDate', '2026-03-15')
            ->call('saveInvestment')
            ->assertHasNoErrors();

        $foto = InvestmentSnapshot::query()->where('investment_id', $investimento->id)->where('year', 2026)->where('month', 3)->first();
        self::assertNotNull($foto);
        self::assertSame('8500.00', $foto->amount);
    }

    public function test_editar_duas_vezes_no_mesmo_mes_atualiza_a_mesma_foto_sem_duplicar(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $investimento = InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')->create([
            'current_amount' => '8000.00',
        ]);

        Livewire::test(InvestmentsIndex::class)
            ->call('editInvestment', $investimento->id)
            ->set('investmentCurrentAmount', '8300.00')
            ->set('investmentValueDate', '2026-03-05')
            ->call('saveInvestment');

        Livewire::test(InvestmentsIndex::class)
            ->call('editInvestment', $investimento->id)
            ->set('investmentCurrentAmount', '8600.00')
            ->set('investmentValueDate', '2026-03-20')
            ->call('saveInvestment');

        self::assertSame(1, InvestmentSnapshot::query()->where('investment_id', $investimento->id)->where('year', 2026)->where('month', 3)->count());
        $foto = InvestmentSnapshot::query()->where('investment_id', $investimento->id)->where('year', 2026)->where('month', 3)->first();
        self::assertSame('8600.00', $foto->amount); // a edição mais recente vence, mesma foto do mês.
    }

    public function test_data_deste_valor_nao_pode_ser_no_futuro(): void
    {
        [, $membro] = $this->criarPerfil();
        $investimento = InvestmentRecord::factory()->for($membro->profile, 'profile')->for($membro, 'member')->create();

        Livewire::test(InvestmentsIndex::class)
            ->call('editInvestment', $investimento->id)
            ->set('investmentCurrentAmount', '1000.00')
            ->set('investmentValueDate', now()->addDay()->toDateString())
            ->call('saveInvestment')
            ->assertHasErrors(['investmentValueDate']);
    }

    public function test_nao_consegue_editar_investimento_de_outro_perfil(): void
    {
        $this->criarPerfil();

        $outroPerfil = FinancialProfile::factory()->create();
        $outroMembro = ProfileMember::factory()->create(['profile_id' => $outroPerfil->id]);
        $investimentoAlheio = InvestmentRecord::factory()->for($outroPerfil, 'profile')->for($outroMembro, 'member')->create();

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::test(InvestmentsIndex::class)->call('editInvestment', $investimentoAlheio->id);
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
