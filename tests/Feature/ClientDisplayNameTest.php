<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Livewire\Consultant\PortfolioInsurance;
use App\Livewire\Consultant\PortfolioInvestments;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\InsurancePolicy;
use App\Models\InvestmentRecord;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\ConsultantPortfolioService;
use App\Support\ClientDisplayName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Nome do cliente nos cartões do consultor ("Seguros da carteira", "Investimentos da carteira", datas importantes):
 * no casal, o cônjuge aparece junto com o titular.
 */
class ClientDisplayNameTest extends TestCase
{
    use RefreshDatabase;

    private User $consultor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consultor = User::factory()->consultant()->create();
    }

    /** @return array{0: FinancialProfile, 1: ProfileMember, 2: ?ProfileMember} cliente ativo, com ou sem cônjuge */
    private function cliente(string $titular, ?string $conjuge = null, bool $conjugeComLogin = true): array
    {
        $usuario = User::factory()->create(['name' => $titular]);
        ConsultantClient::factory()->create(['consultant_id' => $this->consultor->id, 'client_id' => $usuario->id, 'status' => ConsultantClientStatus::Active]);

        $perfil = $conjuge === null
            ? FinancialProfile::factory()->create(['owner_user_id' => $usuario->id])
            : FinancialProfile::factory()->couple()->create(['owner_user_id' => $usuario->id]);
        $membroTitular = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id, 'name' => $titular]);

        $membroConjuge = null;
        if ($conjuge !== null) {
            $userConjuge = $conjugeComLogin ? User::factory()->create(['name' => $conjuge]) : null;
            $membroConjuge = ProfileMember::factory()->secondary()->create(['profile_id' => $perfil->id, 'user_id' => $userConjuge?->id, 'name' => $conjuge]);
        }

        return [$perfil, $membroTitular, $membroConjuge];
    }

    private function nome(FinancialProfile $perfil, bool $soComLogin = false): string
    {
        return ClientDisplayName::forProfile($perfil->fresh(['owner', 'members.user']), $soComLogin);
    }

    // ---- a regra do nome

    public function test_perfil_individual_mostra_so_o_titular(): void
    {
        [$perfil] = $this->cliente('André Albuquerque');

        self::assertSame('André Albuquerque', $this->nome($perfil));
    }

    public function test_casal_com_sobrenomes_diferentes_mostra_os_dois_nomes_completos(): void
    {
        [$perfil] = $this->cliente('André Müller Jr', 'Helen Muller');

        self::assertSame('André Müller Jr e Helen Muller', $this->nome($perfil));
    }

    public function test_casal_com_o_mesmo_sobrenome_mostra_o_sobrenome_uma_vez_so(): void
    {
        [$perfil] = $this->cliente('Rosana Neidert', 'Ricieri Neidert');

        self::assertSame('Rosana e Ricieri Neidert', $this->nome($perfil));
    }

    public function test_conjuge_sem_login_aparece_pelo_nome_do_cadastro(): void
    {
        [$perfil] = $this->cliente('Hugo Lingiardi', 'Emanuelle Santos', conjugeComLogin: false);

        self::assertSame('Hugo Lingiardi e Emanuelle Santos', $this->nome($perfil));
    }

    public function test_a_carteira_so_junta_quando_o_conjuge_tem_login(): void
    {
        [$semLogin] = $this->cliente('Hugo Lingiardi', 'Emanuelle Santos', conjugeComLogin: false);
        [$comLogin] = $this->cliente('Rodrigo Burgos', 'Mariana Tanaka');

        self::assertSame('Hugo Lingiardi', $this->nome($semLogin, soComLogin: true), 'a Carteira usa o nome combinado como sinal de "dois acessos"');
        self::assertSame('Rodrigo Burgos e Mariana Tanaka', $this->nome($comLogin, soComLogin: true));
    }

    public function test_perfil_de_casal_sem_conjuge_cadastrado_mostra_so_o_titular(): void
    {
        $usuario = User::factory()->create(['name' => 'Fulano de Tal']);
        $perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $usuario->id]);
        ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id, 'name' => 'Fulano de Tal']);

        self::assertSame('Fulano de Tal', $this->nome($perfil));
    }

    public function test_conjuge_inativo_nao_entra_no_nome(): void
    {
        [$perfil, , $conjuge] = $this->cliente('Rodrigo Burgos', 'Mariana Tanaka');
        $conjuge->update(['is_active' => false]);

        self::assertSame('Rodrigo Burgos', $this->nome($perfil));
    }

    // ---- nas telas do consultor

    public function test_seguros_da_carteira_titula_o_cartao_do_casal_com_os_dois_nomes(): void
    {
        [$perfil, $titular, $conjuge] = $this->cliente('André Müller Jr', 'Helen Muller');
        InsurancePolicy::factory()->life()->for($perfil, 'profile')->for($titular, 'member')->create(['insurer_name' => 'Azos']);
        InsurancePolicy::factory()->life()->for($perfil, 'profile')->for($conjuge, 'member')->create(['insurer_name' => 'Azos']);
        [$soloPerfil, $soloMembro] = $this->cliente('Felipe Menegatti');
        InsurancePolicy::factory()->life()->for($soloPerfil, 'profile')->for($soloMembro, 'member')->create(['insurer_name' => 'Azos']);

        $this->actingAs($this->consultor);
        $tela = Livewire::test(PortfolioInsurance::class);

        self::assertSame(['André Müller Jr e Helen Muller', 'Felipe Menegatti'], $tela->viewData('grouped')->keys()->all());
        $tela->assertSee('André Müller Jr e Helen Muller');
    }

    public function test_buscar_pelo_nome_do_conjuge_acha_o_cartao_do_casal(): void
    {
        [$perfil, $titular] = $this->cliente('André Müller Jr', 'Helen Muller');
        InsurancePolicy::factory()->life()->for($perfil, 'profile')->for($titular, 'member')->create(['insurer_name' => 'Azos']);
        [$outroPerfil, $outroMembro] = $this->cliente('Felipe Menegatti');
        InsurancePolicy::factory()->life()->for($outroPerfil, 'profile')->for($outroMembro, 'member')->create(['insurer_name' => 'Azos']);

        $this->actingAs($this->consultor);

        $achados = Livewire::test(PortfolioInsurance::class)->set('busca', 'helen')->viewData('grouped')->keys()->all();

        self::assertSame(['André Müller Jr e Helen Muller'], $achados);
    }

    public function test_investimentos_da_carteira_usa_o_mesmo_nome(): void
    {
        [$perfil, $titular] = $this->cliente('Rosana Neidert', 'Ricieri Neidert');
        InvestmentRecord::factory()->for($perfil, 'profile')->for($titular, 'member')->create();

        $this->actingAs($this->consultor);

        $nomes = collect(app(ConsultantPortfolioService::class)->allActiveInvestments($this->consultor))->pluck('client_name')->unique()->all();

        self::assertSame(['Rosana e Ricieri Neidert'], $nomes);
        Livewire::test(PortfolioInvestments::class)->assertSee('Rosana e Ricieri Neidert');
    }
}
