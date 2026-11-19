<?php

namespace Tests\Feature;

use App\Enums\FixedBillPaymentStatus;
use App\Enums\MemberRole;
use App\Livewire\Dashboard;
use App\Models\BankAccount;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\FixedBill;
use App\Models\FixedBillPayment;
use App\Models\IncomeCategory;
use App\Models\IncomeRecord;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\DashboardService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Painel novo: variação contra o mês anterior, ranking de categorias, tabela de contas, últimas despesas, escolha do mês, e os componentes
 * visuais que ele e outras telas usam (ícone de categoria, barra com "Hoje", anel de progresso). Hoje é 18/11/2026.
 */
class DashboardRedesignTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private FinancialProfile $perfil;

    private ProfileMember $membro;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-18 10:00:00'));
        Cache::flush();

        $this->usuario = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->usuario->id]);
        $this->membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuario->id, 'role' => MemberRole::Primary]);
        $this->actingAs($this->usuario);
        app(ProfileContext::class)->set($this->perfil, $this->membro);
    }

    private function despesa(string $valor, string $data, ?string $categoria = null, ?BankAccount $conta = null): ExpenseRecord
    {
        $campos = ['amount' => $valor, 'expense_date' => $data, 'member_id' => null, 'affects_balance' => $conta !== null, 'bank_account_id' => $conta?->id];
        if ($categoria !== null) {
            $campos['category_id'] = ExpenseCategory::factory()->create(['name' => $categoria])->id;
        }

        return ExpenseRecord::factory()->for($this->perfil, 'profile')->create($campos);
    }

    private function receita(string $valor, string $data): IncomeRecord
    {
        return IncomeRecord::factory()->for($this->perfil, 'profile')->create([
            'amount' => $valor, 'received_date' => $data, 'member_id' => null, 'category_id' => IncomeCategory::factory()->create()->id,
        ]);
    }

    public function test_variacao_contra_o_mes_anterior_com_a_cor_certa(): void
    {
        $this->receita('1000.00', '2026-10-05');
        $this->despesa('400.00', '2026-10-10');
        $this->receita('1500.00', '2026-11-05'); // +50%
        $this->despesa('300.00', '2026-11-10');  // -25%

        $v = app(DashboardService::class)->variation(2026, 11);

        self::assertSame(['pct' => 50.0, 'sobe' => true, 'bom' => true], $v['receitas']);
        self::assertSame(['pct' => 25.0, 'sobe' => false, 'bom' => true], $v['despesas'], 'despesa que cai é boa');
        // Sobra: 600 -> 1200 (+100%).
        self::assertSame(['pct' => 100.0, 'sobe' => true, 'bom' => true], $v['sobra']);
    }

    public function test_mes_anterior_sem_valor_nao_inventa_percentual(): void
    {
        $this->receita('1000.00', '2026-11-05');

        $v = app(DashboardService::class)->variation(2026, 11);

        self::assertNull($v['receitas']['pct'], 'dividir por zero seria inventar um número');
    }

    public function test_valor_zerado_de_qualquer_lado_nao_gera_percentual(): void
    {
        // Outubro tinha movimento e novembro está zerado: "100% a menos" só diz que não há lançamento, não que algo mudou.
        $this->receita('1000.00', '2026-10-05');
        $this->despesa('400.00', '2026-10-10');

        $v = app(DashboardService::class)->variation(2026, 11);
        foreach (['receitas', 'despesas', 'sobra'] as $campo) {
            self::assertNull($v[$campo]['pct'], "{$campo}: de um valor para zero não se compara");
        }

        // Os dois zerados também não.
        $vazio = app(DashboardService::class)->variation(2026, 6);
        foreach (['receitas', 'despesas', 'sobra'] as $campo) {
            self::assertNull($vazio[$campo]['pct']);
        }

        Livewire::test(Dashboard::class)->assertDontSeeHtml('data-variacao')->assertDontSee('poupança de');
    }

    public function test_com_valor_dos_dois_lados_o_percentual_continua_aparecendo(): void
    {
        $this->receita('1000.00', '2026-10-05');
        $this->receita('1500.00', '2026-11-05');

        Livewire::test(Dashboard::class)->assertSeeHtml('data-variacao')->assertSee('50,0%')->assertSee('poupança de');
    }

    public function test_ranking_das_categorias_ordena_e_calcula_a_parte_de_cada_uma(): void
    {
        $this->despesa('600.00', '2026-11-03', 'Mercado Teste');
        $this->despesa('200.00', '2026-11-04', 'Lazer Teste');
        $this->despesa('200.00', '2026-11-05', 'Outra Teste');
        $this->despesa('999.00', '2026-10-05', 'Fora do mês');

        $r = app(DashboardService::class)->categoryRanking(2026, 11);

        self::assertSame('Mercado Teste', $r[0]['nome']);
        self::assertSame('600.00', $r[0]['valor']);
        self::assertSame(60.0, (float) $r[0]['pct']);
        self::assertCount(3, $r);
    }

    public function test_tabela_de_contas_traz_inicial_saldo_e_previsto_pelo_mes(): void
    {
        $conta = BankAccount::factory()->create(['profile_id' => $this->perfil->id, 'member_id' => $this->membro->id, 'current_balance' => '1000.00', 'is_active' => true, 'included_in_consolidated' => true]);
        $this->receita('500.00', '2026-11-02');
        IncomeRecord::query()->latest('created_at')->first()->update(['bank_account_id' => $conta->id, 'affects_balance' => true]);
        $this->despesa('200.00', '2026-11-04', null, $conta);
        $bill = FixedBill::factory()->create(['profile_id' => $this->perfil->id, 'bank_account_id' => $conta->id, 'amount' => '120.00', 'is_variable' => false, 'member_id' => null]);
        FixedBillPayment::create(['profile_id' => $this->perfil->id, 'fixed_bill_id' => $bill->id, 'year' => 2026, 'month' => 11, 'due_date' => '2026-11-25', 'status' => FixedBillPaymentStatus::Pending]);

        $t = app(DashboardService::class)->accountsTable(2026, 11);
        $linha = $t['linhas'][0];

        self::assertTrue($t['mes_atual']);
        self::assertSame('500.00', $linha['receitas']);
        self::assertSame('200.00', $linha['despesas']);
        self::assertSame('1000.00', $linha['saldo']);
        self::assertSame('700.00', $linha['inicial'], 'saldo de hoje desfeito do que o mês movimentou');
        self::assertSame('880.00', $linha['previsto'], 'saldo menos a conta fixa ainda pendente');
        self::assertSame('880.00', $t['totais']['previsto']);
    }

    public function test_ultimas_despesas_trazem_no_maximo_seis_do_mes_da_mais_nova_para_a_mais_antiga(): void
    {
        foreach (range(1, 8) as $dia) {
            $this->despesa('10.00', sprintf('2026-11-%02d', $dia), 'Cat '.$dia);
        }
        $this->despesa('10.00', '2026-10-30', 'Fora');

        $r = app(DashboardService::class)->recentExpenses(2026, 11);

        self::assertCount(6, $r);
        self::assertSame('08/11', $r[0]['dia']);
        self::assertSame('03/11', $r[5]['dia']);
    }

    public function test_tela_navega_entre_meses_e_volta_ao_atual(): void
    {
        $this->receita('1000.00', '2026-10-05');

        Livewire::test(Dashboard::class)
            ->assertSee('novembro de 2026')
            ->call('previousMonth')->assertSet('mes', 10)->assertSet('ano', 2026)->assertSee('outubro de 2026')
            ->call('nextMonth')->assertSet('mes', 11)
            ->call('previousMonth')->call('currentMonth')->assertSet('mes', null)->assertSet('ano', null);
    }

    public function test_mes_da_url_invalido_nao_quebra(): void
    {
        Livewire::test(Dashboard::class, ['ano' => 2026, 'mes' => 99])->assertOk();
    }

    public function test_cartao_de_alerta_conta_as_vencidas(): void
    {
        $bill = FixedBill::factory()->create(['profile_id' => $this->perfil->id, 'amount' => '50.00', 'member_id' => null]);
        FixedBillPayment::create(['profile_id' => $this->perfil->id, 'fixed_bill_id' => $bill->id, 'year' => 2026, 'month' => 11, 'due_date' => '2026-11-05', 'status' => FixedBillPaymentStatus::Overdue]);

        self::assertSame(1, app(DashboardService::class)->overdueCount());
        Livewire::test(Dashboard::class)->assertSeeHtml('data-alerta');
    }

    public function test_painel_mostra_os_blocos_novos(): void
    {
        $this->receita('1000.00', '2026-11-05');
        $this->despesa('100.00', '2026-11-06', 'Mercado');

        $this->get(route('dashboard'))->assertOk()
            ->assertSeeHtml('data-kpi="receitas"')->assertSeeHtml('data-kpi="despesas"')->assertSeeHtml('data-kpi="sobra"')
            ->assertSeeHtml('data-ranking')->assertSeeHtml('data-ultimas')->assertSeeHtml('data-seletor-mes');
    }

    // ------------------------------------------------------------ componentes

    public function test_icone_da_categoria_e_estavel_e_cai_na_inicial_sem_palavra_conhecida(): void
    {
        $mercado = Blade::render('<x-icone-categoria nome="Mercado" />');
        self::assertStringContainsString('<svg', $mercado);
        self::assertSame($mercado, Blade::render('<x-icone-categoria nome="Mercado" />'), 'mesmo nome, mesma cor e desenho');

        $outro = Blade::render('<x-icone-categoria nome="Zebra" />');
        self::assertStringNotContainsString('<svg', $outro);
        self::assertStringContainsString('>Z<', preg_replace('/\s+/', '', $outro) === null ? $outro : str_replace(' ', '', $outro));
        // Mesmo nome com acento e caixa diferente acha a mesma palavra.
        self::assertStringContainsString('<svg', Blade::render('<x-icone-categoria nome="ALIMENTAÇÃO" />'));
    }

    public function test_barra_de_progresso_limita_a_largura_e_so_mostra_o_hoje_quando_pedido(): void
    {
        $com = Blade::render('<x-barra-progresso :progresso="140" cor="bg-red-500" :hoje="60" />');
        self::assertStringContainsString('width: 100%', $com, 'passar de 100% não estoura a barra');
        self::assertStringContainsString('data-marcador-hoje', $com);
        self::assertStringContainsString('left: 60%', $com);

        $sem = Blade::render('<x-barra-progresso :progresso="-5" cor="bg-red-500" />');
        self::assertStringContainsString('width: 0%', $sem);
        self::assertStringNotContainsString('data-marcador-hoje', $sem);
    }

    public function test_anel_de_progresso_mostra_o_percentual_e_fecha_em_100(): void
    {
        $meio = Blade::render('<x-anel-progresso :pct="50" />');
        self::assertStringContainsString('50%', $meio);
        self::assertStringContainsString('data-anel', $meio);

        self::assertStringContainsString('100%', Blade::render('<x-anel-progresso :pct="250" />'));
        self::assertStringContainsString('0%', Blade::render('<x-anel-progresso :pct="-10" />'));
    }
}
