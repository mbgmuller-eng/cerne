<?php

namespace Tests\Feature;

use App\Livewire\Insurance\InsuranceIndex;
use App\Models\FinancialProfile;
use App\Models\InsurancePolicy;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Apólice: o quadro "Ao salvar" (custo mensal no resumo, renovação automática, quem vê, histórico) e as dicas. O custo mensal sai da mesma conta que o
 * resumo usa (InsurancePolicy::monthlyCostOf), e os testes conferem o que de fato é gravado.
 */
class InsuranceConsequenceTest extends TestCase
{
    use RefreshDatabase;

    private FinancialProfile $perfil;

    private ProfileMember $membro;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('pt_BR');

        $usuario = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $this->membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $usuario->id, 'name' => 'Ana']);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($this->perfil, $this->membro);
    }

    private function formulario()
    {
        return Livewire::test(InsuranceIndex::class)->call('togglePolicyForm')
            ->set('policyInsurerName', 'Porto Seguro')->set('policyStartDate', '2026-12-01');
    }

    public function test_apolice_trimestral_mostra_o_custo_mensal_e_e_o_que_o_resumo_usa(): void
    {
        $this->formulario()
            ->set('policyPaymentFrequency', 'quarterly')->set('policyMonthlyPremium', '300.00')
            ->assertSee('No resumo, esta apólice entra com R$ 100,00 por mês.')
            ->call('savePolicy')->assertHasNoErrors();

        $apolice = InsurancePolicy::withoutProfileScope()->where('insurer_name', 'Porto Seguro')->sole();
        $this->assertSame('100.00', $apolice->normalizedMonthlyCost());
    }

    public function test_apolice_anual_usa_o_premio_anual(): void
    {
        $this->formulario()
            ->set('policyPaymentFrequency', 'annual')->set('policyMonthlyPremium', '0')->set('policyAnnualPremium', '2400.00')
            ->assertSee('No resumo, esta apólice entra com R$ 200,00 por mês.');
    }

    public function test_apolice_mensal_entra_com_o_proprio_valor(): void
    {
        $this->formulario()
            ->set('policyPaymentFrequency', 'monthly')->set('policyMonthlyPremium', '180.00')
            ->assertSee('No resumo, esta apólice entra com R$ 180,00 por mês.');
    }

    public function test_sem_valor_nao_ha_linha_de_custo(): void
    {
        $this->formulario()->assertDontSee('No resumo, esta apólice entra com');
    }

    public function test_renovacao_automatica_avisa_e_de_fato_tira_o_aviso_de_vencimento(): void
    {
        $this->formulario()
            ->set('policyAutoRenews', true)
            ->assertSee('Esta apólice não gerará aviso de vencimento nem lead de renovação.');

        $renova = InsurancePolicy::factory()->for($this->perfil, 'profile')->create(['auto_renews' => true, 'expiry_date' => now()->addDays(10)]);
        $naoRenova = InsurancePolicy::factory()->for($this->perfil, 'profile')->create(['auto_renews' => false, 'expiry_date' => now()->addDays(10)]);

        $this->assertFalse($renova->isExpiring(), 'renova sozinha: não aparece nos avisos de vencimento');
        $this->assertTrue($naoRenova->isExpiring());
    }

    public function test_ocultar_do_conjuge_sem_membro_avisa_que_nao_esconde_nada(): void
    {
        $this->formulario()
            ->set('policyIsPrivate', true)
            ->assertSee('Sem um membro escolhido, a apólice continua visível aos dois')
            ->set('policyMemberId', $this->membro->id)
            ->assertSee('Só Ana e o consultor verão esta apólice.')
            ->assertDontSee('Sem um membro escolhido');
    }

    public function test_ao_editar_o_aviso_lembra_o_historico(): void
    {
        $apolice = InsurancePolicy::factory()->for($this->perfil, 'profile')->create();

        Livewire::test(InsuranceIndex::class)->call('editPolicy', $apolice->id)
            ->assertSee('entram no histórico da apólice');
    }

    public function test_os_rotulos_e_as_dicas_explicam_o_valor_e_o_vencimento(): void
    {
        Livewire::test(InsuranceIndex::class)->call('togglePolicyForm')
            ->assertSee('Valor de cada parcela')
            ->assertDontSee('Mensalidade')
            ->assertSee('Valor de cada cobrança, conforme a forma de pagamento. Se a apólice é anual, use o prêmio anual.')
            ->assertSee('Define como o valor informado vira custo mensal no resumo.')
            ->assertSee('Só entra no custo mensal quando a forma de pagamento não é mensal.')
            ->assertSee('Sem esta data, o Cerne não avisa o vencimento da apólice.')
            ->assertSee('Nome novo é aceito e vira sugestão para o administrador aprovar.');
    }

    public function test_premio_anual_invalido_mostra_o_nome_do_campo_em_portugues(): void
    {
        $this->formulario()
            ->set('policyMonthlyPremium', '10')->set('policyAnnualPremium', '-5')
            ->call('savePolicy')
            ->assertSee('prêmio anual')
            ->assertDontSee('policy annual premium');
    }
}
