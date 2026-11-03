<?php

namespace Tests\Feature;

use App\Enums\CaregiverAccess;
use App\Enums\CaregiverStatus;
use App\Enums\FixedBillPaymentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\MemberRole;
use App\Enums\Necessity;
use App\Livewire\Family\FamilyFinance;
use App\Models\BankAccount;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\BelongsToProfileOrShared;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\FixedBill;
use App\Models\FixedBillPayment;
use App\Models\InvestmentRecord;
use App\Models\ProfileCaregiver;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\DashboardService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Familiar que acompanha as finanças de um casal (os filhos de dois idosos): só leitura, só o que NÃO é privado, só das pessoas que o acompanhamento
 * cobre. O que um cônjuge escondeu do outro também fica escondido dos filhos, nos lançamentos, nas contas, nas faturas, nos vencimentos e nos totais.
 */
class FamilyFinanceAccessTest extends TestCase
{
    use RefreshDatabase;

    /** Os únicos models que quem acompanha as finanças enxerga. Mudar esta lista é uma decisão de privacidade: revise antes. */
    private const FAMILY_VISIBLE = [
        'BankAccount', 'CreditCard', 'CreditCardInvoice', 'Debt', 'DebtInstallment', 'ExpenseCategory', 'ExpenseRecord', 'ExpenseSubcategory', 'FixedBill',
        'FixedBillPayment', 'Goal', 'IncomeCategory', 'IncomeRecord', 'InsurancePolicy', 'InvestmentRecord', 'RecurringIncome',
    ];

    private User $pai;

    private User $mae;

    private FinancialProfile $perfil;

    private ProfileMember $membroPai;

    private ProfileMember $membroMae;

    private User $filhoDoPai;

    private User $filhoDosDois;

    private User $filhoSoSaude;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pai = User::factory()->create(['name' => 'Dilermando']);
        $this->mae = User::factory()->create(['name' => 'Esposa']);
        $this->perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $this->pai->id, 'profile_name' => 'Casal Dilermando']);
        $this->membroPai = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->pai->id, 'role' => MemberRole::Primary, 'name' => 'Dilermando']);
        $this->membroMae = ProfileMember::factory()->secondary()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->mae->id, 'name' => 'Esposa']);

        $this->filhoDoPai = User::factory()->create(['name' => 'Filho A']);
        $this->filhoDosDois = User::factory()->create(['name' => 'Filha B']);
        $this->filhoSoSaude = User::factory()->create(['name' => 'Filho C']);

        $this->liberar($this->filhoDoPai, [$this->membroPai], finance: true, health: false);
        $this->liberar($this->filhoDosDois, [$this->membroPai, $this->membroMae], finance: true, health: false);
        $this->liberar($this->filhoSoSaude, [$this->membroPai], finance: false, health: true);

        $this->dados();
    }

    /** @param  list<ProfileMember>  $membros */
    private function liberar(User $filho, array $membros, bool $finance, bool $health): void
    {
        foreach ($membros as $membro) {
            ProfileCaregiver::query()->create([
                'profile_id' => $this->perfil->id, 'member_id' => $membro->id, 'user_id' => $filho->id, 'invited_email' => $filho->email, 'invited_name' => $filho->name,
                'access_level' => CaregiverAccess::Read, 'status' => CaregiverStatus::Active, 'accepted_at' => now(),
                'can_view_finance' => $finance, 'can_view_health' => $health,
            ]);
        }
    }

    private function comoMembro(User $usuario, ProfileMember $membro): void
    {
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($this->perfil, $membro);
    }

    private function comoFamiliar(User $filho): void
    {
        $this->actingAs($filho);
        $acessos = ProfileCaregiver::query()->active()->where('profile_id', $this->perfil->id)->where('user_id', $filho->id)->get()->all();
        app(ProfileContext::class)->set($this->perfil, null, false, $acessos[0], $acessos);
    }

    private function dados(): void
    {
        $this->comoMembro($this->pai, $this->membroPai);
        $hoje = CarbonImmutable::now();

        $despesa = fn (?ProfileMember $m, string $desc, string $valor, bool $privada = false) => ExpenseRecord::factory()->for($this->perfil, 'profile')->create([
            'member_id' => $m?->id, 'description' => $desc, 'amount' => $valor, 'necessity' => Necessity::Essential, 'expense_date' => $hoje->toDateString(), 'is_private' => $privada,
        ]);
        $despesa($this->membroPai, 'Farmácia do pai', '50.00');
        $despesa($this->membroPai, 'Despesa escondida do pai', '100.00', true);
        $despesa($this->membroMae, 'Cabeleireiro da mãe', '30.00');
        $despesa($this->membroMae, 'Despesa escondida da mãe', '70.00', true);
        $despesa(null, 'Supermercado da casa', '20.00');

        $conta = fn (?ProfileMember $m, string $banco, string $saldo, bool $conjunta, bool $visivel) => BankAccount::factory()->for($this->perfil, 'profile')->create([
            'member_id' => $m?->id, 'bank_name' => $banco, 'current_balance' => $saldo, 'is_joint' => $conjunta, 'visible_to_partner' => $visivel, 'included_in_consolidated' => true, 'is_active' => true,
        ]);
        $conta($this->membroPai, 'Banco Conjunto', '1000.00', true, true);
        $conta($this->membroPai, 'Banco do pai', '500.00', false, true);
        $conta($this->membroPai, 'Banco secreto do pai', '9000.00', false, false);
        $conta($this->membroMae, 'Banco da mãe', '300.00', false, true);

        $cartao = fn (string $nome, bool $visivel, string $total) => tap(CreditCard::factory()->for($this->perfil, 'profile')->create([
            'member_id' => $this->membroPai->id, 'card_name' => $nome, 'visible_to_partner' => $visivel, 'is_joint' => false, 'is_active' => true,
        ]), fn (CreditCard $c) => CreditCardInvoice::query()->create([
            'profile_id' => $this->perfil->id, 'credit_card_id' => $c->id, 'year' => $hoje->year, 'month' => $hoje->month, 'closing_date' => $hoje->addDays(2)->toDateString(),
            'due_date' => $hoje->addDays(8)->toDateString(), 'total_amount' => $total, 'status' => InvoiceStatus::Open,
        ]));
        $cartao('Cartão do pai', true, '100.00');
        $cartao('Cartão secreto do pai', false, '400.00');

        $vencimento = fn (string $nome, string $valor, bool $privada, int $dias) => tap(FixedBill::factory()->for($this->perfil, 'profile')->create([
            'member_id' => $this->membroPai->id, 'name' => $nome, 'amount' => $valor, 'is_private' => $privada, 'is_active' => true,
        ]), fn (FixedBill $b) => FixedBillPayment::query()->create([
            'profile_id' => $this->perfil->id, 'fixed_bill_id' => $b->id, 'year' => $hoje->year, 'month' => $hoje->month,
            'due_date' => $hoje->addDays($dias)->toDateString(), 'status' => FixedBillPaymentStatus::Pending,
        ]));
        $vencimento('Aluguel do pai', '2000.00', false, 3);
        $vencimento('Conta escondida do pai', '1500.00', true, 2);

        foreach ([['Tesouro do pai', '10000.00', false], ['Aplicação escondida do pai', '5000.00', true]] as [$nome, $valor, $privado]) {
            InvestmentRecord::factory()->for($this->perfil, 'profile')->create(['member_id' => $this->membroPai->id, 'name' => $nome, 'current_amount' => $valor, 'invested_amount' => $valor, 'is_private' => $privado, 'is_active' => true]);
        }
    }

    public function test_quem_acompanha_o_pai_ve_o_que_nao_e_privado_dele_e_o_da_familia_e_nada_do_resto(): void
    {
        $this->comoFamiliar($this->filhoDoPai);

        $this->assertEqualsCanonicalizing(['Farmácia do pai', 'Supermercado da casa'], ExpenseRecord::query()->pluck('description')->all());
        $this->assertEqualsCanonicalizing(['Banco Conjunto', 'Banco do pai'], BankAccount::query()->pluck('bank_name')->all(), 'a conta conjunta e a visível dele; nunca a secreta nem a da mãe');
        $this->assertSame(['Cartão do pai'], CreditCard::query()->pluck('card_name')->all());
        $this->assertSame(['Aluguel do pai'], FixedBill::query()->pluck('name')->all());
        $this->assertSame(['Tesouro do pai'], InvestmentRecord::query()->pluck('name')->all());
    }

    public function test_quem_acompanha_os_dois_ve_o_publico_de_cada_um_e_continua_sem_ver_o_escondido(): void
    {
        $this->comoFamiliar($this->filhoDosDois);

        $this->assertEqualsCanonicalizing(['Farmácia do pai', 'Cabeleireiro da mãe', 'Supermercado da casa'], ExpenseRecord::query()->pluck('description')->all());
        $this->assertEqualsCanonicalizing(['Banco Conjunto', 'Banco do pai', 'Banco da mãe'], BankAccount::query()->pluck('bank_name')->all());
    }

    public function test_fatura_e_vencimento_herdam_a_privacidade_do_cartao_e_da_conta(): void
    {
        $this->comoFamiliar($this->filhoDoPai);

        $this->assertSame(['100.00'], CreditCardInvoice::query()->pluck('total_amount')->map(fn ($v) => (string) $v)->all(), 'a fatura do cartão secreto não existe para o filho');
        $this->assertSame(1, FixedBillPayment::query()->count());
        $this->assertSame('Aluguel do pai', FixedBillPayment::query()->with('fixedBill')->sole()->fixedBill->name);
    }

    public function test_os_totais_do_painel_so_somam_o_que_o_filho_enxerga_e_o_cache_nao_vaza_entre_quem_pergunta(): void
    {
        // O pai pergunta primeiro: o total dele (com o que é privado) entra no cache.
        $this->comoMembro($this->pai, $this->membroPai);
        $doPai = app(DashboardService::class)->overview();
        $this->assertSame('15000.00', $doPai['patrimonio']['investimentos']);
        $this->assertSame('500.00', $doPai['patrimonio']['faturas']);

        $this->comoFamiliar($this->filhoDoPai);
        $doFilho = app(DashboardService::class)->overview();

        $this->assertSame('10000.00', $doFilho['patrimonio']['investimentos']);
        $this->assertSame('1500.00', $doFilho['patrimonio']['contas'], 'conjunta + a visível do pai');
        $this->assertSame('100.00', $doFilho['patrimonio']['faturas']);
        $this->assertSame('70.00', $doFilho['mes']['despesas'], 'sem o escondido do pai, sem a mãe');
        $this->assertSame(['Aluguel do pai'], array_column($doFilho['alertas']['itens'], 'nome'));

        // Os dois filhos têm acompanhamentos diferentes: cada um tem a sua chave de cache.
        $this->comoFamiliar($this->filhoDosDois);
        $this->assertSame('100.00', app(DashboardService::class)->overview()['mes']['despesas']);
    }

    public function test_quem_acompanha_as_financas_nao_grava_nem_altera_nem_apaga_nada(): void
    {
        $this->comoFamiliar($this->filhoDoPai);
        $visivel = ExpenseRecord::query()->where('description', 'Farmácia do pai')->firstOrFail();

        $this->expectException(AuthorizationException::class);

        try {
            $visivel->update(['description' => 'Mexi']);
        } finally {
            $this->assertSame('Farmácia do pai', $visivel->fresh()->description);
        }
    }

    public function test_criar_e_apagar_tambem_sao_recusados(): void
    {
        $this->comoFamiliar($this->filhoDoPai);

        try {
            ExpenseRecord::factory()->for($this->perfil, 'profile')->create(['member_id' => $this->membroPai->id, 'description' => 'Lançamento do filho']);
            $this->fail('o filho não cria lançamento');
        } catch (AuthorizationException) {
        }

        try {
            ExpenseRecord::query()->where('description', 'Farmácia do pai')->firstOrFail()->delete();
            $this->fail('o filho não apaga lançamento');
        } catch (AuthorizationException) {
        }

        $this->comoMembro($this->pai, $this->membroPai);
        $this->assertSame(0, ExpenseRecord::query()->where('description', 'Lançamento do filho')->count());
        $this->assertSame(1, ExpenseRecord::query()->where('description', 'Farmácia do pai')->count());
    }

    public function test_quem_acompanha_so_a_saude_nao_ve_nenhuma_financa(): void
    {
        $this->comoFamiliar($this->filhoSoSaude);

        $this->assertSame(0, ExpenseRecord::query()->count());
        $this->assertSame(0, BankAccount::query()->count());
        $this->assertSame(0, InvestmentRecord::query()->count());
    }

    public function test_a_lista_de_models_que_o_familiar_enxerga_e_travada(): void
    {
        $this->comoFamiliar($this->filhoDosDois);

        $visiveis = [];

        foreach (glob(app_path('Models/*.php')) as $arquivo) {
            $classe = 'App\\Models\\'.pathinfo($arquivo, PATHINFO_FILENAME);

            if (! is_subclass_of($classe, Model::class)) {
                continue;
            }

            $usos = class_uses_recursive($classe);

            if (! in_array(BelongsToProfile::class, $usos, true) && ! in_array(BelongsToProfileOrShared::class, $usos, true)) {
                continue;
            }

            if (! str_contains($classe::query()->toRawSql(), '1 = 0')) {
                $visiveis[] = class_basename($classe);
            }
        }

        sort($visiveis);
        $esperado = self::FAMILY_VISIBLE;
        sort($esperado);

        $this->assertSame($esperado, $visiveis, 'A lista do que o familiar enxerga mudou: isso é decisão de privacidade, revise antes de ajustar o teste.');
    }

    public function test_cada_um_do_casal_continua_vendo_o_que_via_antes(): void
    {
        $this->comoMembro($this->mae, $this->membroMae);

        $this->assertEqualsCanonicalizing(
            ['Farmácia do pai', 'Cabeleireiro da mãe', 'Despesa escondida da mãe', 'Supermercado da casa'],
            ExpenseRecord::query()->pluck('description')->all(),
            'a mãe vê as dela, as da casa e o que o pai não escondeu',
        );
    }

    // --- Telas e rotas -----------------------------------------------------------------------------------------------------

    public function test_a_tela_mostra_o_publico_e_nunca_o_escondido(): void
    {
        $this->comoFamiliar($this->filhoDoPai);

        Livewire::test(FamilyFinance::class)
            ->assertSee('Finanças da família')->assertSee('só leitura')
            ->call('abrir', 'lancamentos')->assertSee('Farmácia do pai')->assertSee('Supermercado da casa')
            ->assertDontSee('Despesa escondida')->assertDontSee('Cabeleireiro da mãe')
            ->call('abrir', 'contas')->assertSee('Banco Conjunto')->assertSee('Cartão do pai')->assertDontSee('Banco secreto')->assertDontSee('Cartão secreto')
            ->call('abrir', 'vencimentos')->assertSee('Aluguel do pai')->assertDontSee('Conta escondida')
            ->call('abrir', 'investimentos')->assertSee('Tesouro do pai')->assertDontSee('Aplicação escondida');
    }

    public function test_a_tela_so_abre_para_quem_acompanha_as_financas(): void
    {
        $this->comoFamiliar($this->filhoSoSaude);
        Livewire::test(FamilyFinance::class)->assertStatus(403);

        $this->comoMembro($this->pai, $this->membroPai);
        Livewire::test(FamilyFinance::class)->assertStatus(403);
    }

    public function test_pelo_navegador_quem_so_acompanha_as_financas_entra_direto_nelas_e_nao_abre_saude_nem_o_resto(): void
    {
        $this->post(route('login.store'), []); // garante sessão limpa
        $this->actingAs($this->filhoDoPai)
            ->post(route('care.open', $this->perfil->id))->assertRedirect(route('family.index'));

        $this->get(route('family.index'))->assertOk()->assertSee('Finanças da família')->assertSee('Aluguel do pai')->assertDontSee('Conta escondida');
        $this->get(route('health.card.index'))->assertRedirect(route('family.index'));
        $this->get(route('dashboard'))->assertRedirect(route('family.index'));
        $this->get(route('cashflow.index'))->assertRedirect(route('family.index'));
    }

    public function test_pelo_navegador_quem_so_acompanha_a_saude_nao_abre_as_financas(): void
    {
        $this->actingAs($this->filhoSoSaude)->post(route('care.open', $this->perfil->id))->assertRedirect(route('health.card.index'));

        $this->get(route('family.index'))->assertRedirect(route('care.index'));
    }
}
