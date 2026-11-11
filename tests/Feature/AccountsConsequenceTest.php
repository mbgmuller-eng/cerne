<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Livewire\Accounts\AccountsIndex;
use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\IncomeRecord;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Contas e cartões: as dicas dos campos e o quadro "Ao salvar" (saldo substituído, quem vê a conta, ciclo da fatura). O texto usa a mesma regra
 * que grava, e os testes conferem o que de fato é gravado.
 */
class AccountsConsequenceTest extends TestCase
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

    private function conta(string $saldo = '1000.00'): BankAccount
    {
        return BankAccount::factory()->for($this->perfil, 'profile')->for($this->membro, 'member')->create(['current_balance' => $saldo]);
    }

    private function cartao(int $fechamento = 20, int $vencimento = 28): CreditCard
    {
        return CreditCard::factory()->for($this->perfil, 'profile')->for($this->membro, 'member')->create(['closing_day' => $fechamento, 'due_day' => $vencimento]);
    }

    public function test_editar_o_saldo_avisa_que_ele_e_substituido_sem_criar_lancamento(): void
    {
        $conta = $this->conta('1000.00');

        Livewire::test(AccountsIndex::class)
            ->call('editAccount', $conta->id)
            ->assertDontSee('Ao salvar')
            ->set('accountBalance', '1500.00')
            ->assertSee('Ao salvar, o saldo passa de R$ 1.000,00 para R$ 1.500,00, sem criar lançamento.')
            ->call('saveAccount')->assertHasNoErrors();

        // O que o aviso promete é o que acontece: o saldo vira 1.500 e nenhum lançamento nasce.
        $this->assertSame('1500.00', $conta->refresh()->current_balance);
        $this->assertSame(0, ExpenseRecord::withoutProfileScope()->count() + IncomeRecord::withoutProfileScope()->count());
    }

    public function test_conta_nova_nao_tem_aviso_de_saldo_e_a_dica_explica_o_saldo(): void
    {
        Livewire::test(AccountsIndex::class)
            ->call('toggleAccountForm')
            ->assertDontSee('sem criar lançamento')
            ->assertSee('Saldo de hoje. Depois, lançamentos que usam esta conta somam ou subtraem dele.')
            ->assertSee('Número da conta (opcional)')
            ->assertSee('Conjunta ou não, toda conta precisa de um dono.')
            ->assertDontSee('flag de compartilhamento');
    }

    public function test_conta_nao_visivel_ao_conjuge_avisa_e_sai_do_consolidado(): void
    {
        Livewire::test(AccountsIndex::class)
            ->call('toggleAccountForm')
            ->set('accountMemberId', $this->membro->id)
            ->set('accountVisibleToPartner', false)
            ->assertSee('Só Ana e o consultor verão esta conta, e ela fica fora do consolidado.')
            ->set('accountBankName', 'Itaú')->set('accountBalance', '100.00')
            ->call('saveAccount')->assertHasNoErrors();

        $conta = BankAccount::withoutProfileScope()->where('bank_name', 'Itaú')->sole();
        $this->assertFalse($conta->visible_to_partner);
        $this->assertFalse($conta->included_in_consolidated, 'o aviso diz que fica fora do consolidado: é o que a regra de compartilhamento grava');
    }

    public function test_conta_visivel_fora_do_consolidado_avisa_que_o_saldo_nao_entra_no_total(): void
    {
        Livewire::test(AccountsIndex::class)
            ->call('toggleAccountForm')
            ->set('accountIncludedInConsolidated', false)
            ->assertSee('O saldo desta conta não entra no total consolidado nem no painel.');
    }

    public function test_conta_conjunta_nao_mostra_aviso_de_privacidade(): void
    {
        Livewire::test(AccountsIndex::class)
            ->call('toggleAccountForm')
            ->set('accountIsJoint', true)
            ->set('accountVisibleToPartner', false)
            ->assertDontSee('verão esta conta');
    }

    public function test_cartao_diz_o_ciclo_da_fatura_com_vencimento_no_proprio_mes(): void
    {
        Livewire::test(AccountsIndex::class)
            ->call('toggleCardForm')
            ->set('cardClosingDay', '20')->set('cardDueDay', '28')
            ->assertSee('Compras até o dia 20 entram na fatura do mês; as seguintes, na do mês seguinte. A fatura vence no dia 28 do próprio mês.');

        // Na regra que grava: fecha 20 e vence 28 → a fatura de dezembro vence em dezembro.
        $this->assertSame('2026-12-28', $this->cartao(20, 28)->dueDateFor(2026, 12)->toDateString());
    }

    public function test_cartao_com_vencimento_menor_que_o_fechamento_vence_no_mes_seguinte(): void
    {
        Livewire::test(AccountsIndex::class)
            ->call('toggleCardForm')
            ->set('cardClosingDay', '28')->set('cardDueDay', '5')
            ->assertSee('A fatura vence no dia 5 do mês seguinte.');

        $this->assertSame('2027-01-05', $this->cartao(28, 5)->dueDateFor(2026, 12)->toDateString());
    }

    public function test_editar_os_dias_do_cartao_avisa_que_as_faturas_antigas_guardam_as_datas(): void
    {
        $cartao = $this->cartao(20, 28);
        $fatura = CreditCardInvoice::withoutProfileScope()->create([
            'profile_id' => $this->perfil->id, 'credit_card_id' => $cartao->id, 'year' => 2026, 'month' => 12, 'closing_date' => '2026-12-20',
            'due_date' => '2026-12-28', 'total_amount' => '0.00', 'status' => InvoiceStatus::Open,
        ]);

        Livewire::test(AccountsIndex::class)
            ->call('editCard', $cartao->id)
            ->assertDontSee('As faturas já criadas guardam as datas')
            ->set('cardClosingDay', '10')->set('cardDueDay', '15')
            ->assertSee('As faturas já criadas guardam as datas que tinham: a mudança vale para as faturas novas.')
            ->call('saveCard')->assertHasNoErrors();

        $this->assertSame(10, (int) $cartao->refresh()->closing_day);
        $this->assertSame('2026-12-20', $fatura->refresh()->closing_date->toDateString(), 'a fatura que já existia não é reescrita');
        $this->assertSame('2026-12-28', $fatura->due_date->toDateString());
    }

    public function test_limite_do_cartao_e_informativo_e_a_dica_diz_isso(): void
    {
        Livewire::test(AccountsIndex::class)
            ->call('toggleCardForm')
            ->assertSee('Informativo. O Cerne não bloqueia compras acima do limite.')
            ->assertSee('Compras até este dia entram na fatura do mês. Depois, na fatura seguinte.')
            ->assertSee('Se for igual ou menor que o fechamento, a fatura vence no mês seguinte.');
    }

    public function test_conta_com_lancamentos_e_desativada_com_mensagem_sem_travessao(): void
    {
        $conta = $this->conta();
        ExpenseRecord::factory()->for($this->perfil, 'profile')->create(['bank_account_id' => $conta->id]);

        Livewire::test(AccountsIndex::class)->call('deleteAccount', $conta->id);

        // Com lançamentos, a conta é desativada e continua existindo.
        $this->assertFalse($conta->refresh()->is_active);
        $this->assertTrue(BankAccount::withoutProfileScope()->whereKey($conta->id)->exists());

        // A mensagem (que vai para a sessão e aparece no layout) usa vírgula, não travessão.
        $fonte = (string) file_get_contents(app_path('Livewire/Accounts/AccountsIndex.php'));
        $this->assertStringContainsString('Essa conta tem lançamentos, então foi desativada em vez de excluída.', $fonte);
        $this->assertStringNotContainsString('lançamentos — desativ', $fonte);
    }
}
