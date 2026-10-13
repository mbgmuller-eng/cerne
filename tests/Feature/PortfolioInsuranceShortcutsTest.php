<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\InsuranceType;
use App\Livewire\Consultant\PortfolioInsurance;
use App\Livewire\Insurance\InsuranceIndex;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\InsurancePolicy;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Seguros da carteira: as renovações a tratar aparecem na própria tela, e cada cliente e cada apólice têm atalhos que
 * abrem direto a tela de Seguros do cliente (na apólice, ou já editando), sem passar pelo perfil.
 */
class PortfolioInsuranceShortcutsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: FinancialProfile} */
    private function clienteDe(User $profissional, string $nome = 'Ana Souza'): array
    {
        $cliente = User::factory()->create(['name' => $nome]);
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $cliente->id]);
        ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $cliente->id, 'name' => $nome]);
        ConsultantClient::factory()->create(['consultant_id' => $profissional->id, 'client_id' => $cliente->id, 'status' => ConsultantClientStatus::Active]);

        return [$cliente, $perfil];
    }

    private function apolice(FinancialProfile $perfil, array $sobre = []): InsurancePolicy
    {
        return InsurancePolicy::factory()->for($perfil, 'profile')->create($sobre + [
            'insurance_type' => InsuranceType::Carro,
            'insurer_name' => 'Porto Seguro',
            'monthly_premium' => '300.00',
            'coverage_amount' => '60000.00',
            'start_date' => '2025-03-01',
            'expiry_date' => null,
            'is_active' => true,
        ]);
    }

    // ---- renovações na tela

    public function test_renovacoes_proximas_aparecem_em_ordem_e_as_que_renovam_sozinhas_ficam_de_fora(): void
    {
        Carbon::setTestNow('2026-10-20');
        $consultor = User::factory()->consultant()->create();
        [, $perfil] = $this->clienteDe($consultor);
        $primeira = $this->apolice($perfil, ['insurer_name' => 'Primeira', 'expiry_date' => '2026-10-25']);
        $segunda = $this->apolice($perfil, ['insurer_name' => 'Segunda', 'expiry_date' => '2026-11-15']);
        $this->apolice($perfil, ['insurer_name' => 'Sozinha', 'expiry_date' => '2026-10-26', 'auto_renews' => true]);
        $this->apolice($perfil, ['insurer_name' => 'Longe', 'expiry_date' => '2027-06-01']);
        // Sem vencimento (saúde), aniversário em 01/11.
        $aniversario = $this->apolice($perfil, ['insurer_name' => 'Aniversaria', 'insurance_type' => InsuranceType::Saude, 'start_date' => '2024-11-01']);

        $this->actingAs($consultor);
        $lista = Livewire::test(PortfolioInsurance::class)->get('renovacoes');

        self::assertSame([$primeira->id, $aniversario->id, $segunda->id], $lista->map(fn ($r) => $r['policy']->id)->all());
        self::assertSame([5, 12, 26], $lista->pluck('days')->all());
        self::assertSame(['vencimento', 'aniversario', 'vencimento'], $lista->pluck('kind')->all());

        Livewire::test(PortfolioInsurance::class)
            ->assertSee('Renovações nos próximos dias')
            ->assertSee('Vence 25/10 · em 5 dias')
            ->assertSee('Aniversário 01/11');
    }

    public function test_sem_renovacao_proxima_o_bloco_nao_aparece(): void
    {
        Carbon::setTestNow('2026-10-20');
        $consultor = User::factory()->consultant()->create();
        [, $perfil] = $this->clienteDe($consultor);
        $this->apolice($perfil, ['expiry_date' => '2027-06-01']);

        $this->actingAs($consultor);
        Livewire::test(PortfolioInsurance::class)->assertDontSee('Renovações nos próximos dias');
    }

    public function test_a_janela_segue_a_antecedencia_escolhida_pelo_profissional(): void
    {
        Carbon::setTestNow('2026-10-20');
        $consultor = User::factory()->consultant()->create(['renewal_lead_days' => 90]);
        [, $perfil] = $this->clienteDe($consultor);
        $this->apolice($perfil, ['expiry_date' => '2027-01-10']); // 82 dias

        $this->actingAs($consultor);
        self::assertCount(1, Livewire::test(PortfolioInsurance::class)->get('renovacoes'));

        $consultor->update(['renewal_lead_days' => 30]);
        self::assertCount(0, Livewire::test(PortfolioInsurance::class)->get('renovacoes'));
    }

    public function test_corretor_so_ve_as_renovacoes_das_apolices_compartilhadas_com_ele(): void
    {
        Carbon::setTestNow('2026-10-20');
        $corretor = User::factory()->broker()->create();
        [, $perfil] = $this->clienteDe($corretor);
        $this->apolice($perfil, ['insurer_name' => 'De outro', 'expiry_date' => '2026-10-30', 'broker_id' => null]);
        $minha = $this->apolice($perfil, ['insurer_name' => 'Minha', 'expiry_date' => '2026-11-02', 'broker_id' => $corretor->id]);

        $this->actingAs($corretor);
        $lista = Livewire::test(PortfolioInsurance::class)->get('renovacoes');

        self::assertSame([$minha->id], $lista->map(fn ($r) => $r['policy']->id)->all());
    }

    public function test_so_as_renovacoes_dos_clientes_do_proprio_profissional(): void
    {
        Carbon::setTestNow('2026-10-20');
        $consultor = User::factory()->consultant()->create();
        $outro = User::factory()->consultant()->create();
        [, $perfilDeOutro] = $this->clienteDe($outro);
        $this->apolice($perfilDeOutro, ['expiry_date' => '2026-10-30']);

        $this->actingAs($consultor);
        self::assertCount(0, Livewire::test(PortfolioInsurance::class)->get('renovacoes'));
    }

    // ---- atalhos na tela

    public function test_cada_cliente_e_cada_apolice_tem_atalho_para_a_tela_de_seguros_dele(): void
    {
        $consultor = User::factory()->consultant()->create();
        [, $perfil] = $this->clienteDe($consultor);
        $apolice = $this->apolice($perfil, ['expiry_date' => '2027-06-01']);

        $this->actingAs($consultor);
        $tela = Livewire::test(PortfolioInsurance::class);

        $tela->assertSee(route('profile.switch', $perfil->id), false)
            ->assertSee('name="destino" value="seguros"', false)
            ->assertSee('name="apolice" value="'.$apolice->id.'"', false)
            ->assertSee('name="acao" value="editar"', false)
            ->assertSee('name="expandir" value="1"', false)
            ->assertSee('Detalhes')
            ->assertSee('Apólices')
            ->assertSee('Abrir perfil');
    }

    public function test_apolice_que_renova_sozinha_aparece_marcada_na_linha(): void
    {
        $consultor = User::factory()->consultant()->create();
        [, $perfil] = $this->clienteDe($consultor);
        $this->apolice($perfil, ['expiry_date' => '2027-06-01', 'auto_renews' => true]);

        $this->actingAs($consultor);
        Livewire::test(PortfolioInsurance::class)->assertSee('renova sozinha');
    }

    // ---- o atalho em si

    public function test_o_atalho_leva_direto_a_tela_de_seguros_com_a_apolice_e_a_edicao(): void
    {
        $consultor = User::factory()->consultant()->create();
        [, $perfil] = $this->clienteDe($consultor);
        $apolice = $this->apolice($perfil);

        $resposta = $this->actingAs($consultor)->post(route('profile.switch', $perfil->id), [
            'destino' => 'seguros', 'apolice' => $apolice->id, 'acao' => 'editar',
        ]);

        $resposta->assertRedirect(route('insurance.index', ['apolice' => $apolice->id, 'editar' => 1]));
        self::assertSame($perfil->id, session(\App\Http\Middleware\SetProfileContext::SESSION_KEY));
    }

    public function test_apolices_do_cliente_chega_com_tudo_aberto_e_valor_estranho_e_descartado(): void
    {
        $consultor = User::factory()->consultant()->create();
        [, $perfil] = $this->clienteDe($consultor);

        $this->actingAs($consultor)
            ->post(route('profile.switch', $perfil->id), ['destino' => 'seguros', 'expandir' => '1', 'apolice' => '../../etc/passwd', 'acao' => 'apagar'])
            ->assertRedirect(route('insurance.index', ['expandir' => 1]));
    }

    public function test_sem_destino_o_comportamento_antigo_continua(): void
    {
        $consultor = User::factory()->consultant()->create();
        [, $perfil] = $this->clienteDe($consultor);

        $this->actingAs($consultor)->post(route('profile.switch', $perfil->id))->assertRedirect(route('dashboard'));
    }

    public function test_cliente_de_outro_profissional_continua_proibido_mesmo_com_atalho(): void
    {
        $consultor = User::factory()->consultant()->create();
        [, $perfilAlheio] = $this->clienteDe(User::factory()->consultant()->create());

        $this->actingAs($consultor)->post(route('profile.switch', $perfilAlheio->id), ['destino' => 'seguros'])->assertForbidden();
    }

    // ---- voltar para a carteira

    public function test_a_carteira_manda_o_endereco_com_os_filtros_e_a_ancora_de_cada_atalho(): void
    {
        $consultor = User::factory()->consultant()->create();
        [, $perfil] = $this->clienteDe($consultor);
        $this->apolice($perfil, ['insurer_name' => 'Porto Seguro', 'expiry_date' => '2027-06-01']);

        $this->actingAs($consultor);
        $html = Livewire::withQueryParams(['busca' => 'Porto', 'tipo' => 'carro'])->test(PortfolioInsurance::class)->html();

        self::assertStringContainsString('name="voltar" value="/carteira/seguros?busca=Porto&amp;tipo=carro#cliente-'.$perfil->id.'"', $html);
        self::assertStringContainsString('id="cliente-'.$perfil->id.'"', $html);
    }

    public function test_o_atalho_guarda_o_voltar_e_a_tela_de_seguros_do_cliente_mostra_o_botao(): void
    {
        $consultor = User::factory()->consultant()->create();
        [, $perfil] = $this->clienteDe($consultor);
        $apolice = $this->apolice($perfil);

        $this->actingAs($consultor)->post(route('profile.switch', $perfil->id), [
            'destino' => 'seguros', 'apolice' => $apolice->id, 'voltar' => '/carteira/seguros?busca=Ana&situacao=vencendo#cliente-'.$perfil->id,
        ]);

        self::assertSame(
            ['url' => '/carteira/seguros?busca=Ana&situacao=vencendo#cliente-'.$perfil->id, 'profile_id' => $perfil->id],
            session('carteira_voltar'),
        );

        app(ProfileContext::class)->set($perfil, member: null, asConsultant: true);
        Livewire::test(InsuranceIndex::class)
            ->assertSee('Voltar para Seguros da carteira')
            ->assertSee('href="/carteira/seguros?busca=Ana&amp;situacao=vencendo#cliente-'.$perfil->id.'"', false);
    }

    public function test_endereco_de_fora_ou_estranho_nunca_vira_voltar(): void
    {
        $consultor = User::factory()->consultant()->create();
        [, $perfil] = $this->clienteDe($consultor);

        foreach (['https://exemplo.com/carteira/seguros', '//exemplo.com/carteira/seguros', '/carteira/seguros?x=<script>', '/dashboard', "/carteira/seguros\nSet-Cookie: a=b", 'javascript:alert(1)'] as $voltar) {
            $this->actingAs($consultor)->post(route('profile.switch', $perfil->id), ['destino' => 'seguros', 'voltar' => $voltar]);

            self::assertNull(session('carteira_voltar'), "não deveria aceitar: $voltar");
        }
    }

    public function test_abrir_o_perfil_de_forma_comum_apaga_o_voltar_e_ele_nao_vale_para_outro_cliente(): void
    {
        $consultor = User::factory()->consultant()->create();
        [, $perfil] = $this->clienteDe($consultor);
        [, $outro] = $this->clienteDe($consultor, 'Bruno Lima');

        $this->actingAs($consultor)->post(route('profile.switch', $perfil->id), ['destino' => 'seguros', 'voltar' => '/carteira/seguros']);
        self::assertNotNull(session('carteira_voltar'));

        // O voltar é de um cliente só: no perfil de outro, não aparece.
        app(ProfileContext::class)->set($outro, member: null, asConsultant: true);
        Livewire::test(InsuranceIndex::class)->assertDontSee('Voltar para Seguros da carteira');

        $this->post(route('profile.switch', $perfil->id));
        self::assertNull(session('carteira_voltar'));
    }

    public function test_sem_atalho_nao_ha_botao_de_voltar(): void
    {
        [, $perfil] = $this->noPerfilDoCliente();

        Livewire::test(InsuranceIndex::class)->assertDontSee('Voltar para Seguros da carteira');
    }
    // ---- a tela de seguros do cliente

    private function noPerfilDoCliente(): array
    {
        $consultor = User::factory()->consultant()->create();
        [, $perfil] = $this->clienteDe($consultor);
        $this->actingAs($consultor);
        app(ProfileContext::class)->set($perfil, member: null, asConsultant: true);

        return [$consultor, $perfil];
    }

    public function test_chegar_com_editar_abre_o_formulario_da_apolice_e_nao_reabre_depois(): void
    {
        [, $perfil] = $this->noPerfilDoCliente();
        $apolice = $this->apolice($perfil, ['insurer_name' => 'Azos', 'policy_number' => 'AP-9']);

        $tela = Livewire::withQueryParams(['apolice' => $apolice->id, 'editar' => '1'])->test(InsuranceIndex::class);

        $tela->assertSet('showPolicyForm', true)
            ->assertSet('editingPolicyId', $apolice->id)
            ->assertSet('policyInsurerName', 'Azos')
            ->assertSet('foco', $apolice->id);
    }

    public function test_apolice_inexistente_ou_de_outro_perfil_e_ignorada_sem_erro(): void
    {
        [, $perfil] = $this->noPerfilDoCliente();
        [, $perfilAlheio] = $this->clienteDe(User::factory()->consultant()->create(), 'Outro');
        $alheia = $this->apolice($perfilAlheio);

        foreach (['00000000-0000-0000-0000-000000000000', $alheia->id] as $id) {
            Livewire::withQueryParams(['apolice' => $id, 'editar' => '1'])->test(InsuranceIndex::class)
                ->assertSet('showPolicyForm', false)
                ->assertSet('editingPolicyId', null);
        }
    }

    public function test_apolice_em_foco_abre_o_grupo_dela_e_expandir_abre_todos(): void
    {
        [, $perfil] = $this->noPerfilDoCliente();
        $carro = $this->apolice($perfil, ['insurer_name' => 'Porto', 'policy_number' => 'CARRO-1']);
        $this->apolice($perfil, ['insurer_name' => 'Azos', 'insurance_type' => InsuranceType::Vida, 'policy_number' => 'VIDA-1']);

        $fechados = Livewire::test(InsuranceIndex::class)->html();
        self::assertSame(0, substr_count($fechados, 'open: true'));

        $comFoco = Livewire::withQueryParams(['apolice' => $carro->id])->test(InsuranceIndex::class)->html();
        self::assertSame(1, substr_count($comFoco, 'open: true'), 'só o grupo da apólice em foco');
        self::assertStringContainsString('id="apolice-'.$carro->id.'"', $comFoco);
        self::assertStringContainsString('scrollIntoView', $comFoco);

        $todos = Livewire::withQueryParams(['expandir' => '1'])->test(InsuranceIndex::class)->html();
        self::assertSame(2, substr_count($todos, 'open: true'));
    }
}
