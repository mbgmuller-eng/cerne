<?php

namespace Tests\Feature;

use App\Enums\FixedBillPaymentStatus;
use App\Enums\MemberRole;
use App\Livewire\Accounts\AccountStatement;
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
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Extrato de uma conta, dia a dia: o saldo de cada dia sai do saldo ATUAL desfazendo o que veio depois (o último dia sempre bate com a conta), e o
 * "Efetivar" dá baixa pelo mesmo serviço das telas de origem. Hoje é 18/11/2026.
 */
class AccountStatementTest extends TestCase
{
    use RefreshDatabase;

    private FinancialProfile $perfil;

    private BankAccount $conta;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-18 10:00:00'));

        $usuario = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $usuario->id, 'role' => MemberRole::Primary]);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($this->perfil, $membro);

        $this->conta = BankAccount::factory()->create(['profile_id' => $this->perfil->id, 'current_balance' => '1000.00', 'bank_name' => 'Banco Teste']);
    }

    private function despesa(string $valor, string $data, ?BankAccount $conta = null, bool $mexeNoSaldo = true): ExpenseRecord
    {
        return ExpenseRecord::factory()->for($this->perfil, 'profile')->create([
            'bank_account_id' => ($conta ?? $this->conta)->id, 'amount' => $valor, 'expense_date' => $data, 'affects_balance' => $mexeNoSaldo,
        ]);
    }

    private function receita(string $valor, string $data): IncomeRecord
    {
        return IncomeRecord::factory()->for($this->perfil, 'profile')->create([
            'bank_account_id' => $this->conta->id, 'amount' => $valor, 'received_date' => $data, 'affects_balance' => true,
            'category_id' => IncomeCategory::factory()->create()->id,
        ]);
    }

    public function test_o_saldo_de_cada_dia_sai_do_saldo_atual_desfazendo_o_que_veio_depois(): void
    {
        $this->receita('500.00', '2026-11-05');
        $this->despesa('200.00', '2026-11-10');
        $this->despesa('100.00', '2026-11-15');
        $this->despesa('50.00', '2026-12-02'); // já lançada, depois do mês: o saldo atual já a descontou
        $this->despesa('30.00', '2026-10-20'); // outro mês: fora da lista

        $tela = Livewire::test(AccountStatement::class, ['account' => $this->conta, 'ano' => 2026, 'mes' => 11]);

        // Fim de novembro: 1000 desfeito de -50 (dezembro) = 1050. Dia 15: 1050. Dia 10: 1150. Dia 5: 1350. Início: 850.
        $tela->assertSeeHtml('data-extrato-dia="2026-11-15"')->assertSeeHtml('data-extrato-dia="2026-11-10"')->assertSeeHtml('data-extrato-dia="2026-11-05"')
            ->assertDontSeeHtml('data-extrato-dia="2026-12-02"')->assertDontSeeHtml('data-extrato-dia="2026-10-20"');

        $html = $tela->html();
        preg_match_all('/data-extrato-dia="(\d{4}-\d{2}-\d{2})".*?data-saldo-do-dia>\s*<span>Saldo ao fim do dia<\/span>\s*<span[^>]*>(.*?)<\/span>/s', $html, $m, PREG_SET_ORDER);
        $saldos = array_map(fn ($x) => [$x[1], trim(html_entity_decode($x[2]))], $m);
        self::assertSame([['2026-11-15', 'R$ 1.050,00'], ['2026-11-10', 'R$ 1.150,00'], ['2026-11-05', 'R$ 1.350,00']], $saldos);

        // Começou o mês com 850 e, com +500 -200 -100, terminou com 1050.
        $tela->assertSee('R$ 850,00')->assertSee('R$ 1.050,00');
    }

    public function test_compra_no_cartao_nao_mexe_no_saldo_da_conta(): void
    {
        $this->despesa('300.00', '2026-11-12', null, false); // não mexe no saldo (cartão)

        Livewire::test(AccountStatement::class, ['account' => $this->conta, 'ano' => 2026, 'mes' => 11])
            ->assertDontSeeHtml('data-extrato-dia="2026-11-12"')
            ->assertSee('Nenhuma movimentação');
    }

    public function test_efetivar_conta_fixa_da_baixa_pelo_servico_e_muda_o_saldo(): void
    {
        $conta = FixedBill::factory()->create([
            'profile_id' => $this->perfil->id, 'name' => 'Internet', 'amount' => '120.00', 'bank_account_id' => $this->conta->id, 'is_variable' => false,
            'category_id' => ExpenseCategory::factory()->shared(),
        ]);
        $pagamento = FixedBillPayment::create(['profile_id' => $this->perfil->id, 'fixed_bill_id' => $conta->id, 'year' => 2026, 'month' => 11, 'due_date' => '2026-11-25', 'status' => FixedBillPaymentStatus::Pending]);

        $tela = Livewire::test(AccountStatement::class, ['account' => $this->conta, 'ano' => 2026, 'mes' => 11]);

        // Previsto: 1000 − 120 = 880, e a conta aparece com o botão.
        $tela->assertSeeHtml('data-previsto="conta"')->assertSee('Internet')->assertSee('R$ 880,00')->assertSeeHtml('data-efetivar');

        $tela->call('efetivarConta', $pagamento->id);

        $pagamento->refresh();
        self::assertSame(FixedBillPaymentStatus::Paid, $pagamento->status);
        self::assertSame('880.00', $this->conta->fresh()->current_balance);
        self::assertSame(1, ExpenseRecord::query()->where('bank_account_id', $this->conta->id)->where('description', 'like', '%Internet%')->count());
        // Pago: sai do previsto.
        $tela->assertDontSeeHtml('data-previsto="conta"');
    }

    public function test_conta_fixa_de_valor_variavel_pede_o_valor_nas_contas_fixas(): void
    {
        $conta = FixedBill::factory()->create([
            'profile_id' => $this->perfil->id, 'name' => 'Luz', 'amount' => '200.00', 'bank_account_id' => $this->conta->id, 'is_variable' => true,
            'category_id' => ExpenseCategory::factory()->shared(),
        ]);
        $pagamento = FixedBillPayment::create(['profile_id' => $this->perfil->id, 'fixed_bill_id' => $conta->id, 'year' => 2026, 'month' => 11, 'due_date' => '2026-11-25', 'status' => FixedBillPaymentStatus::Pending]);

        Livewire::test(AccountStatement::class, ['account' => $this->conta, 'ano' => 2026, 'mes' => 11])
            ->assertSee('Informar valor')->assertDontSeeHtml('data-efetivar')
            ->call('efetivarConta', $pagamento->id)
            ->assertSee('valor variável');

        self::assertSame(FixedBillPaymentStatus::Pending, $pagamento->fresh()->status);
        self::assertSame('1000.00', $this->conta->fresh()->current_balance);
    }

    public function test_nao_da_baixa_em_conta_fixa_de_outra_conta(): void
    {
        $outraConta = BankAccount::factory()->create(['profile_id' => $this->perfil->id, 'current_balance' => '500.00']);
        $bill = FixedBill::factory()->create(['profile_id' => $this->perfil->id, 'bank_account_id' => $outraConta->id, 'amount' => '50.00', 'is_variable' => false, 'category_id' => ExpenseCategory::factory()->shared()]);
        $pagamento = FixedBillPayment::create(['profile_id' => $this->perfil->id, 'fixed_bill_id' => $bill->id, 'year' => 2026, 'month' => 11, 'due_date' => '2026-11-25', 'status' => FixedBillPaymentStatus::Pending]);

        // Livewire devolve a recusa como resposta (404), não como exceção.
        Livewire::test(AccountStatement::class, ['account' => $this->conta, 'ano' => 2026, 'mes' => 11])->call('efetivarConta', $pagamento->id)->assertNotFound();

        self::assertSame(FixedBillPaymentStatus::Pending, $pagamento->fresh()->status);
        self::assertSame('500.00', $outraConta->fresh()->current_balance);
    }

    public function test_conta_de_outro_perfil_da_404(): void
    {
        $outroUsuario = User::factory()->create();
        $outroPerfil = FinancialProfile::factory()->create(['owner_user_id' => $outroUsuario->id]);
        $alheia = BankAccount::factory()->create(['profile_id' => $outroPerfil->id]);

        $this->get(route('accounts.statement', $alheia))->assertNotFound();
    }

    public function test_o_extrato_e_aberto_pela_lista_de_contas(): void
    {
        $this->get(route('accounts.index'))->assertOk()->assertSee(route('accounts.statement', $this->conta), false)->assertSee('Ver extrato');
        $this->get(route('accounts.statement', $this->conta))->assertOk()->assertSee('Banco Teste');
    }
}
