<?php

namespace Tests\Feature;

use App\Enums\AssetClass;
use App\Enums\ConsultantClientStatus;
use App\Enums\FundingMethod;
use App\Enums\GoalStatus;
use App\Enums\MemberRole;
use App\Livewire\CategorizationRules\CategorizationRulesIndex;
use App\Livewire\Consultant\LeadRenewalSettings;
use App\Livewire\Goals\GoalsIndex;
use App\Livewire\Profile\MyAccount;
use App\Models\ConsultantClient;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\ExpenseSubcategory;
use App\Models\FinancialProfile;
use App\Models\Goal;
use App\Models\InvestmentRecord;
use App\Models\PartnerInvite;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Telas de configuração e objetivos: o quadro "Ao salvar" de Objetivos, Regras de categorização, Minha conta (convite, cônjuge sem login, aniversário) e
 * preferências de renovação. O número do aviso sai da mesma conta que a lista usa, e os testes conferem o que é gravado.
 */
class ConfigurationConsequenceTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private FinancialProfile $perfil;

    private ProfileMember $membro;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('pt_BR');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 09:00:00'));
        $this->seed(TaxonomySeeder::class);

        $this->usuario = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->usuario->id]);
        $this->membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuario->id, 'role' => MemberRole::Primary, 'name' => 'Marcelo']);
        $this->actingAs($this->usuario);
        app(ProfileContext::class)->set($this->perfil, $this->membro);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ objetivos

    public function test_objetivo_diz_o_progresso_e_quanto_guardar_por_mes_na_mesma_conta_da_lista(): void
    {
        Livewire::test(GoalsIndex::class)->call('openForm')
            ->set('name', 'Viagem')->set('estimatedValue', '10000,00')->set('currentAmount', '2500,00')->set('targetDate', '2027-10-20')
            ->assertSee('Com R$ 2.500,00 de R$ 10.000,00, o objetivo fica em 25,0%.')
            ->assertSee('Faltam R$ 7.500,00 até 20/10/2027: cerca de R$ 625,00 por mês.')
            ->call('save')->assertHasNoErrors();

        // O que a lista mostra depois de salvar é exatamente o que o aviso disse.
        $this->assertSame('625.00', Goal::query()->sole()->monthlyNeeded());
    }

    public function test_objetivo_sem_data_ou_com_data_passada_explica_por_que_nao_ha_valor_mensal(): void
    {
        Livewire::test(GoalsIndex::class)->call('openForm')
            ->set('estimatedValue', '10000,00')
            ->assertSee('Sem data-alvo, o Cerne não calcula quanto guardar por mês.')
            ->set('targetDate', '2026-01-10')
            ->assertSee('A data-alvo já passou, então o Cerne não calcula quanto guardar por mês.');
    }

    public function test_objetivo_ligado_a_investimento_usa_o_saldo_dele(): void
    {
        $investimento = InvestmentRecord::factory()->for($this->perfil, 'profile')->for($this->membro, 'member')->create([
            'name' => 'Reserva do carro', 'asset_class' => AssetClass::Cdb, 'current_amount' => '4000.00',
        ]);

        Livewire::test(GoalsIndex::class)->call('openForm')
            ->set('name', 'Carro')->set('estimatedValue', '10000,00')->set('linkType', 'investment')->set('linkedInvestmentId', $investimento->id)
            ->assertSee('O avanço vem do saldo de '.$investimento->displayName().': R$ 4.000,00 de R$ 10.000,00 (40,0%).')
            ->call('save')->assertHasNoErrors();

        $this->assertSame('4000.00', Goal::query()->sole()->accumulated());
    }

    public function test_editar_objetivo_avisa_que_quanto_ja_tem_deixa_de_valer_ao_ligar_e_o_que_conquistado_faz(): void
    {
        $investimento = InvestmentRecord::factory()->for($this->perfil, 'profile')->for($this->membro, 'member')->create(['asset_class' => AssetClass::Cdb, 'current_amount' => '4000.00']);
        $objetivo = Goal::create([
            'name' => 'Viagem', 'priority' => 1, 'estimated_value' => '10000.00', 'funding_method' => FundingMethod::LumpSum, 'current_amount' => '500.00',
            'status' => GoalStatus::Active, 'created_by_user_id' => $this->usuario->id,
        ]);

        Livewire::test(GoalsIndex::class)
            ->call('edit', $objetivo->id)
            ->set('linkType', 'investment')->set('linkedInvestmentId', $investimento->id)
            ->assertSee('O valor que estava em "Quanto já tem" (R$ 500,00) deixa de valer')
            ->set('status', 'achieved')
            ->assertSee('Conquistado: o objetivo sai da lista em andamento e deixa de entrar nos totais do topo.')
            ->call('save')->assertHasNoErrors();

        $this->assertSame('0.00', $objetivo->refresh()->current_amount);
    }

    public function test_as_dicas_do_objetivo_dizem_o_que_entra_no_calculo(): void
    {
        Livewire::test(GoalsIndex::class)->call('openForm')
            ->set('fundingMethod', 'installments')
            ->assertSee('Com a data, o Cerne calcula quanto guardar por mês.')
            ->assertSee('Só descreve o objetivo na lista. Não muda o cálculo de quanto guardar por mês.')
            ->assertSee('Fica guardado com o objetivo. Não entra no cálculo de quanto guardar por mês.')
            ->assertSee('É o valor que você informa e atualiza à mão. Com investimento ou consórcio ligado, ele não vale.');
    }

    // ------------------------------------------------------------------ regras de categorização

    public function test_regra_de_despesa_diz_o_que_acontece_nas_proximas_importacoes_e_quantos_lancamentos_ja_existem(): void
    {
        $categoria = ExpenseCategory::factory()->create(['necessity' => null, 'name' => 'Alimentação']);
        $sub = ExpenseSubcategory::factory()->create(['category_id' => $categoria->id, 'name' => 'Supermercado']);
        ExpenseRecord::factory()->for($this->perfil, 'profile')->count(2)->create(['description' => 'MERCADO EXTRA']);
        ExpenseRecord::factory()->for($this->perfil, 'profile')->create(['description' => 'Padaria']);

        Livewire::test(CategorizationRulesIndex::class)
            ->call('toggleExpenseForm')
            ->set('expensePattern', 'mercado')->set('expenseCategoryId', $categoria->id)->set('expenseSubcategoryId', $sub->id)->set('expenseNecessity', 'essential')
            ->assertSee('Nas próximas importações, a linha cuja descrição contém "mercado" já vem sugerida como Alimentação › Supermercado (essencial). Você ainda confirma na revisão.')
            ->assertSee('Já existem 2 lançamentos com esse trecho na descrição. Ao salvar, o Cerne pergunta se você quer recategorizá-los: nada muda sozinho.')
            ->call('saveExpenseRule')->assertHasNoErrors()
            // A contagem do aviso é a mesma da oferta que o salvar faz, e a oferta não muda nada sozinha.
            ->assertSet('regraAplicavelExistentes.quantidade', 2);

        $this->assertSame(1, \App\Models\ExpenseCategorizationRule::query()->count());
        $this->assertSame(0, ExpenseRecord::query()->where('category_id', $categoria->id)->count());
    }

    // ------------------------------------------------------------------ minha conta

    public function test_convidar_conjuge_diz_o_e_mail_a_validade_e_o_convite_que_deixa_de_valer(): void
    {
        PartnerInvite::issue($this->perfil, $this->usuario, 'Ana', 'ana-antiga@example.com');

        Livewire::test(MyAccount::class)
            ->call('toggleInviteForm')
            ->set('partnerEmail', 'ana@example.com')
            ->assertSee('Um e-mail com o link do convite vai para ana@example.com. O link vale por 7 dias.')
            ->assertSee('O convite pendente para ana-antiga@example.com deixa de valer: só o novo link funciona.')
            ->assertSee('o perfil passa a ser de casal e ela entra com login próprio. Cada um decide o que oculta do outro.');
    }

    public function test_cadastrar_conjuge_sem_login_diz_que_o_perfil_vira_de_casal(): void
    {
        Livewire::test(MyAccount::class)
            ->call('togglePartnerOnlyForm')
            ->set('partnerOnlyName', 'Bruno')
            ->assertSee('O perfil passa a ser de casal e Bruno entra como segundo membro, sem login.')
            ->assertSee('Você pode convidá-lo(a) por e-mail depois: a pessoa assume este cadastro')
            ->call('addPartnerWithoutLogin')->assertHasNoErrors();

        $this->assertSame('couple', $this->perfil->refresh()->profile_type->value);
        $this->assertSame(1, ProfileMember::query()->where('profile_id', $this->perfil->id)->where('role', MemberRole::Secondary)->whereNull('user_id')->count());
    }

    public function test_aniversario_diz_quantos_dias_antes_o_profissional_vinculado_e_avisado(): void
    {
        Livewire::test(MyAccount::class)
            ->call('toggleOwnBirthdate')
            ->assertSee('O consultor ou corretor vinculado é avisado 7 dias antes do aniversário.');
    }

    // ------------------------------------------------------------------ renovações do profissional

    public function test_preferencias_de_renovacao_dizem_a_janela_e_o_que_nao_muda(): void
    {
        $consultor = User::factory()->consultant()->create();
        $cliente = User::factory()->create();
        ConsultantClient::factory()->create(['consultant_id' => $consultor->id, 'client_id' => $cliente->id, 'status' => ConsultantClientStatus::Active]);
        $this->actingAs($consultor);

        Livewire::test(LeadRenewalSettings::class)
            ->set('types', ['vida', 'carro'])->set('days', '60')
            ->assertSee('cada apólice dos seus clientes dos 2 tipos marcados que vence nos próximos 60 dias vira um contato de renovação no funil.')
            ->assertSee('Contatos que já foram criados não mudam nem se repetem.')
            ->set('types', [])
            ->assertSee('Nenhum tipo marcado: nenhuma apólice vira contato de renovação.');
    }

    public function test_notificacoes_dizem_que_cada_marca_vale_na_hora(): void
    {
        Livewire::test(MyAccount::class)->assertSee('Cada marca vale na hora, sem botão de salvar.');
    }
}
