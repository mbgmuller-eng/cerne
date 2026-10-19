<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Livewire\Consultant\PortfolioOverview;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ordenação da tabela "Por cliente": critério, sentido (crescente ou decrescente) e o padrão que o consultor
 * guarda na conta (users.portfolio_prefs).
 */
class PortfolioSortTest extends TestCase
{
    use RefreshDatabase;

    private User $consultor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consultor = User::factory()->consultant()->create();
        $this->cliente('Bruno Lima', '2024-05-01');
        $this->cliente('Ana Souza', '2026-02-10');
        $this->cliente('Carla Dias', '2022-01-15');
        $this->actingAs($this->consultor);
    }

    public function test_sem_padrao_guardado_vale_atencao_e_o_sentido_natural_de_cada_criterio(): void
    {
        $c = Livewire::test(PortfolioOverview::class);

        self::assertSame('atencao', $c->get('ordenarClientes'));
        self::assertSame('asc', $c->get('sentidoClientes'));

        // "Cliente desde" sempre abriu com o mais novo primeiro; patrimônio com o maior.
        $c->set('ordenarClientes', 'desde')->assertSet('sentidoClientes', 'desc')
            ->tap(fn ($c) => self::assertSame(['Ana Souza', 'Bruno Lima', 'Carla Dias'], $this->nomes($c)));
    }

    public function test_inverte_nome_de_a_a_z_para_z_a_a(): void
    {
        Livewire::test(PortfolioOverview::class)
            ->set('ordenarClientes', 'nome')
            ->tap(fn ($c) => self::assertSame(['Ana Souza', 'Bruno Lima', 'Carla Dias'], $this->nomes($c)))
            ->set('sentidoClientes', 'desc')
            ->tap(fn ($c) => self::assertSame(['Carla Dias', 'Bruno Lima', 'Ana Souza'], $this->nomes($c)));
    }

    public function test_inverte_cliente_desde_do_mais_antigo_ao_mais_novo(): void
    {
        Livewire::test(PortfolioOverview::class)
            ->set('ordenarClientes', 'desde')
            ->set('sentidoClientes', 'asc')
            ->tap(fn ($c) => self::assertSame(['Carla Dias', 'Bruno Lima', 'Ana Souza'], $this->nomes($c)));
    }

    public function test_quem_nao_tem_o_dado_fica_no_fim_nos_dois_sentidos(): void
    {
        $pendente = User::factory()->create(['name' => 'Davi Pendente']);
        ConsultantClient::factory()->pending()->create(['consultant_id' => $this->consultor->id, 'client_id' => $pendente->id]);

        foreach (['asc', 'desc'] as $sentido) {
            Livewire::test(PortfolioOverview::class)
                ->set('ordenarClientes', 'patrimonio')
                ->set('sentidoClientes', $sentido)
                ->tap(fn ($c) => self::assertSame('Davi Pendente', last($this->nomes($c))));
        }
    }

    public function test_salvar_como_padrao_guarda_criterio_e_sentido_e_vale_na_proxima_visita(): void
    {
        Livewire::test(PortfolioOverview::class)
            ->set('ordenarClientes', 'nome')
            ->set('sentidoClientes', 'desc')
            ->call('salvarPadraoClientes');

        // O JSON do MySQL não guarda a ordem das chaves.
        self::assertEquals(['sort' => 'nome', 'dir' => 'desc'], $this->consultor->fresh()->portfolio_prefs['clients']);

        $c = Livewire::test(PortfolioOverview::class);
        $c->assertSet('ordenarClientes', 'nome')->assertSet('sentidoClientes', 'desc')
            ->tap(fn ($c) => self::assertSame(['Carla Dias', 'Bruno Lima', 'Ana Souza'], $this->nomes($c)))
            ->assertSee('Ordem padrão');
    }

    public function test_url_com_outro_criterio_ganha_do_padrao_guardado(): void
    {
        $this->consultor->update(['portfolio_prefs' => ['clients' => ['sort' => 'nome', 'dir' => 'desc']]]);

        Livewire::withQueryParams(['ordenarClientes' => 'desde', 'sentidoClientes' => 'asc'])
            ->test(PortfolioOverview::class)
            ->assertSet('ordenarClientes', 'desde')
            ->assertSet('sentidoClientes', 'asc');
    }

    public function test_padrao_guardado_invalido_cai_no_padrao_do_sistema(): void
    {
        $this->consultor->update(['portfolio_prefs' => ['clients' => ['sort' => 'inexistente', 'dir' => 'x']]]);

        Livewire::test(PortfolioOverview::class)
            ->assertSet('ordenarClientes', 'atencao')
            ->assertSet('sentidoClientes', 'asc');
    }

    public function test_nao_salva_padrao_com_criterio_ou_sentido_invalido(): void
    {
        Livewire::test(PortfolioOverview::class)
            ->set('ordenarClientes', 'inexistente')
            ->call('salvarPadraoClientes');

        self::assertNull($this->consultor->fresh()->portfolio_prefs);
    }

    public function test_padrao_da_lista_sem_seguro_e_independente_da_tabela_principal(): void
    {
        Livewire::test(PortfolioOverview::class)
            ->set('ordenarSemSeguro', 'desde')
            ->set('sentidoSemSeguro', 'asc')
            ->call('salvarPadraoSemSeguro');

        $prefs = $this->consultor->fresh()->portfolio_prefs;
        self::assertEquals(['sort' => 'desde', 'dir' => 'asc'], $prefs['no_life']);
        self::assertArrayNotHasKey('clients', $prefs);
    }

    /** @return list<string> a ordem da tabela "Por cliente", sem confundir com a lista "Sem seguro de vida" */
    private function nomes(\Livewire\Features\SupportTesting\Testable $c): array
    {
        return array_column($c->viewData('dados')['por_cliente'], 'name');
    }

    private function cliente(string $nome, string $aceitoEm): void
    {
        $user = User::factory()->create(['name' => $nome]);
        ConsultantClient::factory()->create([
            'consultant_id' => $this->consultor->id,
            'client_id' => $user->id,
            'status' => ConsultantClientStatus::Active,
            'accepted_at' => $aceitoEm.' 10:00:00',
        ]);
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $user->id]);
        ProfileMember::factory()->create(['profile_id' => $perfil->id]);
    }
}
