<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Livewire\Consultant\PortfolioInsurance;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\InsurancePolicy;
use App\Models\ProfileMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Seguros da carteira" (consultor): um cartão por cliente, em ordem alfabética e fechado por padrão, com o
 * tipo de cada apólice na linha e filtros por busca, tipo, seguradora e situação.
 *
 * Caso real que motivou separar por pessoa: o perfil de casal de um consultor tinha 4 apólices de vida
 * (2 por cônjuge, AZOS+ICATU cada) e a tela antiga mostrava 4 linhas com o MESMO nome de cliente — parecia
 * duplicata. Não era: eram duas pessoas diferentes no mesmo perfil.
 */
class PortfolioInsuranceGroupingTest extends TestCase
{
    use RefreshDatabase;

    private User $consultor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consultor = User::factory()->consultant()->create();
    }

    /** @return array{0: FinancialProfile, 1: ProfileMember} cliente ativo do consultor, com o titular */
    private function cliente(string $nome, ?User $consultor = null): array
    {
        $usuario = User::factory()->create(['name' => $nome]);
        ConsultantClient::factory()->create([
            'consultant_id' => ($consultor ?? $this->consultor)->id,
            'client_id' => $usuario->id,
            'status' => ConsultantClientStatus::Active,
        ]);

        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $titular = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id, 'name' => explode(' ', $nome)[0]]);

        return [$perfil, $titular];
    }

    private function apolice(FinancialProfile $perfil, ?ProfileMember $membro, string $tipo, string $seguradora, array $sobre = []): InsurancePolicy
    {
        return InsurancePolicy::factory()->for($perfil, 'profile')->when($membro, fn ($f) => $f->for($membro, 'member'))
            ->create(['insurance_type' => $tipo, 'insurer_name' => $seguradora] + $sobre);
    }

    private function tela()
    {
        $this->actingAs($this->consultor);

        return Livewire::test(PortfolioInsurance::class);
    }

    // ---- agrupamento por cliente

    public function test_casal_com_seguro_de_vida_em_duas_seguradoras_separa_por_pessoa_dentro_do_mesmo_cliente(): void
    {
        [$perfil, $titular] = $this->cliente('Marcelo Müller');
        $conjuge = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'name' => 'Helen']);

        $this->apolice($perfil, $titular, 'vida', 'AZOS');
        $this->apolice($perfil, $titular, 'vida', 'ICATU');
        $this->apolice($perfil, $conjuge, 'vida', 'AZOS');
        $this->apolice($perfil, $conjuge, 'vida', 'ICATU');

        $grouped = $this->tela()->viewData('grouped');

        self::assertSame(['Marcelo Müller'], $grouped->keys()->all(), 'as 4 apólices são todas do mesmo cliente (perfil)');
        $doCliente = $grouped->first();
        self::assertSame(4, $doCliente['quantidade']);
        self::assertTrue($doCliente['separarPorPessoa'], 'duas pessoas diferentes no mesmo perfil: precisa separar');
        self::assertCount(2, $doCliente['pessoas']);
        self::assertEqualsCanonicalizing(['Marcelo', 'Helen'], $doCliente['pessoas']->pluck('nome')->all());
        foreach ($doCliente['pessoas'] as $pessoa) {
            self::assertCount(2, $pessoa['linhas'], 'AZOS e Icatu, cada uma a sua apólice');
        }
    }

    public function test_cliente_individual_com_um_seguro_de_carro_nao_separa_por_pessoa(): void
    {
        [$perfil, $titular] = $this->cliente('Ana Cabral');
        $this->apolice($perfil, $titular, 'carro', 'Allianz');

        self::assertFalse($this->tela()->viewData('grouped')->first()['separarPorPessoa']);
    }

    public function test_um_cliente_com_varios_tipos_fica_em_um_so_cartao_e_cada_linha_diz_o_seu_tipo(): void
    {
        [$perfil, $titular] = $this->cliente('Ana Cabral');
        $this->apolice($perfil, $titular, 'vida', 'Icatu Seguros', ['policy_number' => '91.000.001']);
        $this->apolice($perfil, $titular, 'carro', 'Porto Seguro', ['insured_item' => 'Honda Civic 2022']);
        $this->apolice($perfil, $titular, 'residencia', 'Allianz');

        $tela = $this->tela();
        $grouped = $tela->viewData('grouped');

        self::assertCount(1, $grouped);
        self::assertSame(['Automóvel', 'Residencial', 'Vida'], $grouped->first()['tipos']->map->label()->all());
        $tela->assertSee('Automóvel')->assertSee('Residencial')->assertSee('Vida')->assertSee('Honda Civic 2022');
    }

    public function test_cabecalho_do_cartao_resume_apolices_capital_e_custo(): void
    {
        [$perfil, $titular] = $this->cliente('Ana Cabral');
        $this->apolice($perfil, $titular, 'vida', 'Icatu Seguros', ['coverage_amount' => '100000.00', 'monthly_premium' => '100.00']);
        $this->apolice($perfil, $titular, 'carro', 'Porto Seguro', ['coverage_amount' => '50000.00', 'monthly_premium' => '60.50']);

        $resumo = $this->tela()->viewData('grouped')->first();

        self::assertSame(2, $resumo['quantidade']);
        self::assertSame('150000.00', $resumo['cobertura']);
        self::assertSame('160.50', $resumo['mensal']);
        self::assertSame(['Icatu Seguros', 'Porto Seguro'], $resumo['seguradoras']->all());
    }

    // ---- ordem e visibilidade

    public function test_clientes_em_ordem_alfabetica_sem_diferenca_de_acento_ou_maiuscula(): void
    {
        foreach (['Zélia Souza', 'álvaro Lima', 'Bruno Alves', 'ana Cabral'] as $nome) {
            [$perfil, $titular] = $this->cliente($nome);
            $this->apolice($perfil, $titular, 'vida', 'Icatu Seguros');
        }

        self::assertSame(
            ['álvaro Lima', 'ana Cabral', 'Bruno Alves', 'Zélia Souza'],
            $this->tela()->viewData('grouped')->keys()->all(),
        );
    }

    public function test_apolices_do_cartao_ficam_em_ordem_de_tipo_e_seguradora(): void
    {
        [$perfil, $titular] = $this->cliente('Ana Cabral');
        $this->apolice($perfil, $titular, 'vida', 'Icatu Seguros');
        $this->apolice($perfil, $titular, 'carro', 'Porto Seguro');
        $this->apolice($perfil, $titular, 'vida', 'Azos');

        $linhas = $this->tela()->viewData('grouped')->first()['pessoas']->first()['linhas'];

        self::assertSame(
            [['Automóvel', 'Porto Seguro'], ['Vida', 'Azos'], ['Vida', 'Icatu Seguros']],
            $linhas->map(fn ($l) => [$l['policy']->insurance_type->label(), $l['seguradora']])->all(),
        );
    }

    public function test_os_cartoes_vem_fechados_por_padrao_e_ha_expandir_e_recolher_todos(): void
    {
        [$perfil, $titular] = $this->cliente('Ana Cabral');
        $this->apolice($perfil, $titular, 'vida', 'Icatu Seguros');

        $tela = $this->tela();

        $tela->assertSeeHtml('x-data="{ open: false }"')
            ->assertDontSeeHtml('x-data="{ open: true }"')
            ->assertSee('Expandir todos')
            ->assertSee('Recolher todos');
    }

    // ---- seguradora

    public function test_a_mesma_seguradora_com_grafias_diferentes_vira_uma_so(): void
    {
        [$perfilA, $titularA] = $this->cliente('Ana Cabral');
        [$perfilB, $titularB] = $this->cliente('Bruno Alves');
        $this->apolice($perfilA, $titularA, 'vida', 'ICATU');
        $this->apolice($perfilB, $titularB, 'vida', 'Icatu Seguros');

        $tela = $this->tela();

        self::assertSame(['Icatu Seguros'], $tela->viewData('seguradoras')->all());

        // O link antigo (?seguradora=ICATU) continua achando as duas.
        $tela->set('seguradora', 'ICATU');
        self::assertSame(['Ana Cabral', 'Bruno Alves'], $tela->viewData('grouped')->keys()->all());
    }

    // ---- filtros

    public function test_filtro_por_tipo(): void
    {
        [$perfilA, $titularA] = $this->cliente('Ana Cabral');
        [$perfilB, $titularB] = $this->cliente('Bruno Alves');
        $this->apolice($perfilA, $titularA, 'vida', 'Icatu Seguros');
        $this->apolice($perfilA, $titularA, 'carro', 'Porto Seguro');
        $this->apolice($perfilB, $titularB, 'vida', 'Icatu Seguros');

        $tela = $this->tela()->set('tipo', 'carro');

        self::assertSame(['Ana Cabral'], $tela->viewData('grouped')->keys()->all());
        self::assertSame(1, $tela->viewData('totalFiltrado'));
        self::assertSame(3, $tela->viewData('totalGeral'));
        self::assertSame(1, $tela->viewData('grouped')->first()['quantidade'], 'o resumo do cartão acompanha o filtro');
    }

    public function test_filtro_por_seguradora(): void
    {
        [$perfilA, $titularA] = $this->cliente('Ana Cabral');
        [$perfilB, $titularB] = $this->cliente('Bruno Alves');
        $this->apolice($perfilA, $titularA, 'vida', 'Azos');
        $this->apolice($perfilB, $titularB, 'vida', 'Icatu Seguros');

        self::assertSame(['Bruno Alves'], $this->tela()->set('seguradora', 'Icatu Seguros')->viewData('grouped')->keys()->all());
    }

    public function test_busca_por_cliente_pessoa_seguradora_numero_ou_item(): void
    {
        [$perfilA, $titularA] = $this->cliente('Ana Cabral');
        [$perfilB, $titularB] = $this->cliente('Bruno Alves');
        $this->apolice($perfilA, $titularA, 'vida', 'Azos', ['policy_number' => '91.092.828']);
        $this->apolice($perfilB, $titularB, 'carro', 'Porto Seguro', ['insured_item' => 'Honda Civic 2022']);

        $tela = $this->tela();

        self::assertSame(['Ana Cabral'], $tela->set('busca', 'cabral')->viewData('grouped')->keys()->all(), 'por cliente');
        self::assertSame(['Ana Cabral'], $tela->set('busca', '91092828')->viewData('grouped')->keys()->all(), 'número digitado sem pontuação');
        self::assertSame(['Ana Cabral'], $tela->set('busca', '91.092')->viewData('grouped')->keys()->all(), 'por número');
        self::assertSame(['Bruno Alves'], $tela->set('busca', 'civic')->viewData('grouped')->keys()->all(), 'por item');
        self::assertSame(['Bruno Alves'], $tela->set('busca', 'PORTO bruno')->viewData('grouped')->keys()->all(), 'várias palavras, em qualquer ordem');
        self::assertSame(['Ana Cabral'], $tela->set('busca', 'ANÁ')->viewData('grouped')->keys()->all(), 'sem diferença de acento');
        self::assertSame([], $tela->set('busca', 'inexistente')->viewData('grouped')->keys()->all());
    }

    public function test_busca_acha_a_pessoa_dentro_de_um_perfil_de_casal(): void
    {
        [$perfil, $titular] = $this->cliente('Marcelo Müller');
        $conjuge = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'name' => 'Helen']);
        $this->apolice($perfil, $conjuge, 'vida', 'Azos');

        self::assertSame(['Marcelo Müller'], $this->tela()->set('busca', 'helen')->viewData('grouped')->keys()->all());
    }

    public function test_filtro_de_situacao_vencendo_em_30_dias(): void
    {
        [$perfilA, $titularA] = $this->cliente('Ana Cabral');
        [$perfilB, $titularB] = $this->cliente('Bruno Alves');
        $this->apolice($perfilA, $titularA, 'carro', 'Porto Seguro', ['expiry_date' => now()->addDays(10)]);
        $this->apolice($perfilB, $titularB, 'carro', 'Porto Seguro', ['expiry_date' => now()->addDays(200)]);

        $tela = $this->tela()->set('situacao', 'vencendo');

        self::assertSame(['Ana Cabral'], $tela->viewData('grouped')->keys()->all());
        self::assertSame(1, $tela->viewData('grouped')->first()['vencendo']);
    }

    public function test_filtro_de_dados_incompletos_pega_apolice_sem_custo(): void
    {
        [$perfilA, $titularA] = $this->cliente('Ana Cabral');
        [$perfilB, $titularB] = $this->cliente('Bruno Alves');
        $this->apolice($perfilA, $titularA, 'vida', 'AZOS', ['monthly_premium' => '0.00', 'coverage_amount' => null]);
        $this->apolice($perfilB, $titularB, 'vida', 'AZOS', ['monthly_premium' => '80.00']);

        $tela = $this->tela()->set('situacao', 'incompleta');

        self::assertSame(['Ana Cabral'], $tela->viewData('grouped')->keys()->all());
        $tela->assertSee('Dados incompletos');
    }

    public function test_filtros_se_combinam_e_limpar_filtros_volta_a_tudo(): void
    {
        [$perfilA, $titularA] = $this->cliente('Ana Cabral');
        [$perfilB, $titularB] = $this->cliente('Bruno Alves');
        $this->apolice($perfilA, $titularA, 'vida', 'Icatu Seguros');
        $this->apolice($perfilA, $titularA, 'carro', 'Porto Seguro');
        $this->apolice($perfilB, $titularB, 'vida', 'Azos');

        $tela = $this->tela()->set('tipo', 'vida')->set('seguradora', 'Icatu Seguros');
        self::assertSame(['Ana Cabral'], $tela->viewData('grouped')->keys()->all());
        self::assertTrue($tela->viewData('filtrando'));

        $tela->call('limparFiltros');
        self::assertSame(['Ana Cabral', 'Bruno Alves'], $tela->viewData('grouped')->keys()->all());
        self::assertFalse($tela->viewData('filtrando'));
    }

    public function test_sem_resultado_diz_que_nada_combina_com_os_filtros(): void
    {
        [$perfil, $titular] = $this->cliente('Ana Cabral');
        $this->apolice($perfil, $titular, 'vida', 'Icatu Seguros');

        $this->tela()->set('busca', 'zzz')->assertSee('Nenhuma apólice combina com os filtros escolhidos.');
    }

    public function test_so_oferece_tipos_e_seguradoras_que_existem_na_carteira(): void
    {
        [$perfil, $titular] = $this->cliente('Ana Cabral');
        $this->apolice($perfil, $titular, 'vida', 'Icatu Seguros');

        $tela = $this->tela();

        self::assertSame(['Vida'], $tela->viewData('tipos')->map->label()->all());
        self::assertSame(['Icatu Seguros'], $tela->viewData('seguradoras')->all());
    }

    // ---- isolamento

    public function test_nao_mostra_cliente_de_outro_consultor_nem_apolice_inativa(): void
    {
        $outro = User::factory()->consultant()->create();
        [$perfilDele, $titularDele] = $this->cliente('Cliente Alheio', $outro);
        $this->apolice($perfilDele, $titularDele, 'vida', 'Icatu Seguros');

        [$perfil, $titular] = $this->cliente('Ana Cabral');
        $this->apolice($perfil, $titular, 'vida', 'Icatu Seguros');
        $this->apolice($perfil, $titular, 'carro', 'Porto Seguro', ['is_active' => false]);

        $tela = $this->tela();

        self::assertSame(['Ana Cabral'], $tela->viewData('grouped')->keys()->all());
        self::assertSame(1, $tela->viewData('totalGeral'));
        $tela->assertDontSee('Cliente Alheio');
    }
}
