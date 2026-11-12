<?php

namespace Tests\Feature;

use App\Enums\FixedBillPaymentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\Necessity;
use App\Enums\RecurrenceType;
use App\Livewire\FixedBills\FixedBillsIndex;
use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\ExpenseSubcategory;
use App\Models\FinancialProfile;
use App\Models\FixedBill;
use App\Models\FixedBillPayment;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\FixedBillService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Conta fixa NO CARTÃO: no vencimento o valor entra sozinho na fatura (a que recebe compras daquela data), a conta fica paga, nenhum saldo mexe,
 * e a rotina pode rodar de novo ou duas vezes sem duplicar. Hoje é 10/12/2026; o cartão fecha dia 20 e vence dia 28.
 */
class FixedBillOnCardTest extends TestCase
{
    use RefreshDatabase;

    private FinancialProfile $perfil;

    private ProfileMember $membro;

    private CreditCard $cartao;

    private ExpenseCategory $categoria;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('pt_BR');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-10 09:00:00'));

        $usuario = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $this->membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $usuario->id, 'name' => 'Ana']);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($this->perfil, $this->membro);

        $this->cartao = CreditCard::factory()->for($this->perfil, 'profile')->create(['card_name' => 'Nubank', 'closing_day' => 20, 'due_day' => 28, 'last_four_digits' => '1234']);
        $this->categoria = ExpenseCategory::factory()->create(['necessity' => null, 'name' => 'Assinaturas']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function conta(array $campos = []): FixedBill
    {
        return app(FixedBillService::class)->create(array_merge([
            'name' => 'Streaming', 'amount' => '49.90', 'recurrence' => RecurrenceType::Monthly, 'due_day' => 12,
            'member_id' => $this->membro->id, 'credit_card_id' => $this->cartao->id, 'necessity' => Necessity::Discretionary,
            'category_id' => $this->categoria->id, 'is_variable' => false,
        ], $campos));
    }

    private function rodarNoDia(string $data): array
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($data.' 03:10:00'));

        return app(FixedBillService::class)->runDailyMaintenance();
    }

    private function fatura(int $ano, int $mes): ?CreditCardInvoice
    {
        return CreditCardInvoice::query()->where('credit_card_id', $this->cartao->id)->where('year', $ano)->where('month', $mes)->first();
    }

    public function test_no_dia_do_vencimento_a_compra_entra_na_fatura_e_a_conta_fica_paga_sem_mexer_em_saldo(): void
    {
        $conta = $this->conta();
        $this->assertSame(0, ExpenseRecord::query()->count(), 'antes do vencimento nada é lançado');

        $this->rodarNoDia('2026-12-12');

        $despesa = ExpenseRecord::query()->sole();
        $fatura = $this->fatura(2026, 12);
        $this->assertSame('49.90', $despesa->amount);
        $this->assertSame($this->cartao->id, $despesa->credit_card_id);
        $this->assertSame($fatura->id, $despesa->credit_card_invoice_id);
        $this->assertNull($despesa->bank_account_id);
        $this->assertSame('2026-12-12', $despesa->expense_date->toDateString());
        $this->assertSame($this->categoria->id, $despesa->category_id);
        $this->assertSame('49.90', $fatura->fresh()->total_amount);

        $pagamento = FixedBillPayment::query()->where('fixed_bill_id', $conta->id)->sole();
        $this->assertSame(FixedBillPaymentStatus::Paid, $pagamento->status);
        $this->assertSame('49.90', $pagamento->amount_paid);
    }

    public function test_rodar_a_rotina_de_novo_nao_duplica(): void
    {
        $this->conta();

        $this->rodarNoDia('2026-12-12');
        $this->rodarNoDia('2026-12-12');
        $this->rodarNoDia('2026-12-13');

        $this->assertSame(1, ExpenseRecord::query()->count());
        $this->assertSame('49.90', $this->fatura(2026, 12)->total_amount);
    }

    public function test_vencimento_ja_reivindicado_por_outra_execucao_nao_lanca_de_novo(): void
    {
        $conta = $this->conta();
        FixedBillPayment::query()->where('fixed_bill_id', $conta->id)->update(['status' => FixedBillPaymentStatus::Paid->value]);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-12 03:10:00'));
        $this->assertSame(0, app(FixedBillService::class)->postDueCardCharges());
        $this->assertSame(0, ExpenseRecord::query()->count());
    }

    public function test_vencimento_depois_do_fechamento_cai_na_fatura_do_mes_seguinte(): void
    {
        $this->conta(['due_day' => 25]);

        $this->rodarNoDia('2026-12-25');

        // O cartão fecha dia 20: uma compra do dia 25 pertence à fatura de janeiro.
        $this->assertNull($this->fatura(2026, 12)?->total_amount > 0 ? true : null);
        $this->assertSame('49.90', $this->fatura(2027, 1)->total_amount);
    }

    public function test_conta_cadastrada_com_o_vencimento_deste_mes_ja_passado_lanca_na_hora(): void
    {
        $this->conta(['due_day' => 5]);

        $despesa = ExpenseRecord::query()->sole();
        $this->assertSame('2026-12-05', $despesa->expense_date->toDateString());
        $this->assertSame('49.90', $this->fatura(2026, 12)->total_amount);
        $this->assertSame(FixedBillPaymentStatus::Paid, FixedBillPayment::query()->sole()->status);
    }

    public function test_fatura_ja_fechada_nao_recebe_a_cobranca_que_vai_para_a_proxima(): void
    {
        app(\App\Services\InvoiceService::class)->ensureInvoice($this->cartao, 2026, 12)->update(['status' => InvoiceStatus::Closed]);
        $this->conta();

        $this->rodarNoDia('2026-12-12');

        $this->assertSame('0.00', $this->fatura(2026, 12)->total_amount);
        $this->assertSame('49.90', $this->fatura(2027, 1)->total_amount);
    }

    public function test_conta_de_valor_variavel_espera_o_valor_e_ao_pagar_vai_para_a_fatura(): void
    {
        $conta = $this->conta(['is_variable' => true, 'name' => 'Energia']);

        $this->rodarNoDia('2026-12-12');
        $pagamento = FixedBillPayment::query()->where('fixed_bill_id', $conta->id)->sole();

        $this->assertSame(0, ExpenseRecord::query()->count(), 'valor variável nunca é lançado sozinho');
        $this->assertTrue(FixedBillPayment::query()->whereKey($pagamento->id)->sole()->status->isOutstanding(), 'segue em aberto até a pessoa informar o valor');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-14 10:00:00'));
        app(FixedBillService::class)->pay($pagamento->fresh(), '187.35', null, auth()->id());

        $despesa = ExpenseRecord::query()->sole();
        $this->assertSame('187.35', $despesa->amount);
        $this->assertSame($this->cartao->id, $despesa->credit_card_id);
        $this->assertNull($despesa->bank_account_id);
        $this->assertSame('187.35', $this->fatura(2026, 12)->total_amount);
        $this->assertSame(FixedBillPaymentStatus::Paid, $pagamento->fresh()->status);
    }

    public function test_a_despesa_herda_a_privacidade_e_o_dono_da_conta_fixa(): void
    {
        $this->membro->forceFill([])->save();
        $segundo = ProfileMember::factory()->secondary()->create(['profile_id' => $this->perfil->id, 'user_id' => User::factory()->create()->id, 'name' => 'Bruno']);
        $this->conta(['is_private' => true, 'member_id' => $this->membro->id]);

        $this->rodarNoDia('2026-12-12');

        $despesa = ExpenseRecord::query()->withoutGlobalScopes()->sole();
        $this->assertTrue($despesa->is_private);
        $this->assertSame($this->membro->id, $despesa->member_id);
        $this->assertNotNull($segundo);
    }

    public function test_cartao_inativo_nao_recebe_lancamento_e_o_vencimento_segue_em_aberto(): void
    {
        $this->conta();
        $this->cartao->update(['is_active' => false]);

        $this->rodarNoDia('2026-12-12');

        $this->assertSame(0, ExpenseRecord::query()->count());
        $this->assertTrue(FixedBillPayment::query()->sole()->status->isOutstanding());
    }

    public function test_conta_desativada_nao_lanca(): void
    {
        $conta = $this->conta();
        app(FixedBillService::class)->deactivate($conta);

        $this->rodarNoDia('2026-12-12');

        $this->assertSame(0, ExpenseRecord::query()->count());
    }

    public function test_conta_em_conta_bancaria_continua_debitando_o_saldo_como_antes(): void
    {
        $banco = BankAccount::factory()->for($this->perfil, 'profile')->create(['current_balance' => '1000.00']);
        $conta = $this->conta(['credit_card_id' => null, 'bank_account_id' => $banco->id]);
        $pagamento = FixedBillPayment::query()->where('fixed_bill_id', $conta->id)->sole();

        app(FixedBillService::class)->pay($pagamento, null, null, auth()->id());

        $this->assertSame('950.10', $banco->fresh()->current_balance);
        $this->assertNull(ExpenseRecord::query()->sole()->credit_card_id);
    }

    // ------------------------------------------------------------------ formulário

    private function formulario()
    {
        $sub = ExpenseSubcategory::factory()->create(['category_id' => $this->categoria->id]);

        return Livewire::test(FixedBillsIndex::class)->call('toggleBillForm')
            ->set('billName', 'Streaming')->set('billAmount', '49.90')->set('billDueDay', '12')->set('billNecessity', 'discretionary')
            ->set('billCategoryId', $this->categoria->id)->set('billSubcategoryId', $sub->id);
    }

    public function test_formulario_oferece_o_cartao_e_o_aviso_diz_em_qual_fatura_cai(): void
    {
        $this->formulario()
            ->assertSee('Conta bancária')->assertSee('Cartão de crédito')
            ->set('billPaymentMethod', 'cartao')
            ->assertSee('Nubank ····1234')
            ->assertSee('Escolha o cartão: a conta fixa será lançada na fatura dele a cada vencimento.')
            ->set('billCreditCardId', $this->cartao->id)
            ->assertSee('No dia de cada vencimento o Cerne lança sozinho uma compra de R$ 49,90 em Assinaturas na fatura do cartão Nubank ····1234')
            ->assertSee('O vencimento de 12/12 cai na fatura de 12/2026, que vence em 28/12/2026.');
    }

    public function test_salvar_no_cartao_grava_o_cartao_e_nenhuma_conta(): void
    {
        $banco = BankAccount::factory()->for($this->perfil, 'profile')->create();

        $this->formulario()->set('billBankAccountId', $banco->id)->set('billPaymentMethod', 'cartao')->set('billCreditCardId', $this->cartao->id)
            ->call('saveBill')->assertHasNoErrors();

        $conta = FixedBill::query()->sole();
        $this->assertSame($this->cartao->id, $conta->credit_card_id);
        $this->assertNull($conta->bank_account_id, 'cartão e conta bancária são excludentes');
    }

    public function test_cartao_exige_escolher_o_cartao(): void
    {
        $this->formulario()->set('billPaymentMethod', 'cartao')->call('saveBill')->assertHasErrors(['billCreditCardId' => 'required_if']);
        $this->assertSame(0, FixedBill::query()->count());
    }

    public function test_cartao_de_outro_perfil_e_recusado(): void
    {
        $outro = FinancialProfile::factory()->create();
        $cartaoAlheio = CreditCard::factory()->create(['profile_id' => $outro->id]);

        $this->formulario()->set('billPaymentMethod', 'cartao')->set('billCreditCardId', $cartaoAlheio->id)->call('saveBill')->assertNotFound();
        $this->assertSame(0, FixedBill::query()->count());
    }

    public function test_passar_uma_conta_existente_para_o_cartao_lanca_o_que_ja_venceu_e_avisa(): void
    {
        $conta = $this->conta(['credit_card_id' => null, 'due_day' => 5]);
        $this->assertSame(0, ExpenseRecord::query()->count());

        $sub = ExpenseSubcategory::factory()->create(['category_id' => $this->categoria->id]);

        Livewire::test(FixedBillsIndex::class)->call('editBill', $conta->id)->set('billSubcategoryId', $sub->id)
            ->set('billPaymentMethod', 'cartao')->set('billCreditCardId', $this->cartao->id)
            ->assertSee('o que já venceu e está em aberto neste mês entra na fatura ao salvar')
            ->call('saveBill')->assertHasNoErrors();

        $this->assertSame('49.90', $this->fatura(2026, 12)->total_amount);
        $this->assertSame(FixedBillPaymentStatus::Paid, FixedBillPayment::query()->sole()->status);
    }

    public function test_a_lista_mostra_em_que_cartao_a_conta_vai(): void
    {
        $this->conta();

        Livewire::test(FixedBillsIndex::class)->assertSee('no cartão Nubank ····1234');
    }
}
