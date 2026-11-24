<?php

namespace Tests\Feature;

use App\Enums\DebtInstallmentStatus;
use App\Enums\FixedBillPaymentStatus;
use App\Enums\MemberRole;
use App\Livewire\Debts\DebtsIndex;
use App\Livewire\FixedBills\FixedBillsIndex;
use App\Models\BankAccount;
use App\Models\Debt;
use App\Models\DebtInstallment;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\FixedBill;
use App\Models\FixedBillPayment;
use App\Models\ProfileMember;
use App\Models\User;
use App\Notifications\FixedBillDueSoon;
use App\Services\DebtFixedBillSync;
use App\Services\DebtService;
use App\Services\FixedBillReconciliationService;
use App\Services\FixedBillService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Dívida, consórcio e financiamento entram sozinhos em Contas fixas: um vencimento por parcela, valor e data da parcela, e o dinheiro sai uma vez só
 * (quem paga é a parcela). A conta é vinculada à que já existia, encerrada quando a dívida acaba e nunca duplicada.
 */
class DebtFixedBillSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private FinancialProfile $perfil;

    private ProfileMember $membro;

    protected function setUp(): void
    {
        parent::setUp();

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

    private function servico(): DebtService
    {
        return app(DebtService::class);
    }

    /** @return array{category_id: string, subcategory_id: string} */
    private function classificacao(string $categoria = 'Financeiros', string $sub = 'Empréstimo'): array
    {
        $cat = ExpenseCategory::available()->where('name', $categoria)->firstOrFail();

        return ['category_id' => $cat->id, 'subcategory_id' => $cat->subcategories()->where('name', $sub)->firstOrFail()->id];
    }

    private function emprestimo(array $campos = []): Debt
    {
        return $this->servico()->create(array_merge([
            'kind' => 'loan', 'name' => 'Empréstimo pessoal', 'amortization' => 'fixed', 'installment_amount' => '650.00', 'term' => 3,
            'first_due_date' => '2026-10-25', 'paid_before' => 0,
        ], $this->classificacao(), $campos));
    }

    private function consorcio(array $campos = []): Debt
    {
        return $this->servico()->createConsortium(array_merge([
            'consortium_type' => 'property', 'name' => 'Consórcio do apartamento', 'creditor' => 'Ademicon', 'credit_amount' => '100000.00',
            'installment_amount' => '1000.00', 'term' => 100, 'first_due_date' => '2026-10-10', 'paid_before' => 0,
        ], $campos));
    }

    private function contaDe(Debt $divida): FixedBill
    {
        return FixedBill::query()->where('debt_id', $divida->id)->sole();
    }

    public function test_a_divida_cadastrada_aparece_em_contas_fixas_com_o_vencimento_do_mes(): void
    {
        $divida = $this->emprestimo();
        $conta = $this->contaDe($divida);

        $this->assertSame('Empréstimo pessoal', $conta->name);
        $this->assertSame('650.00', $conta->amount);
        $this->assertSame(25, $conta->due_day);
        $this->assertSame('monthly', $conta->recurrence->value);
        $this->assertTrue($conta->is_active);
        $this->assertFalse($conta->is_variable);
        $this->assertSame($divida->category_id, $conta->category_id);

        // Só o vencimento do mês de hoje nasce na hora (os outros nascem quando o mês chega), com a parcela e o valor dela.
        $v = FixedBillPayment::query()->sole();
        $this->assertSame('2026-10-25', $v->due_date->toDateString());
        $this->assertSame('650.00', $v->expected_amount);
        $this->assertSame(FixedBillPaymentStatus::Pending, $v->status);
        $this->assertSame($divida->installments()->where('number', 1)->value('id'), $v->debt_installment_id);
    }

    public function test_o_valor_do_vencimento_e_o_da_parcela_e_nao_o_da_conta(): void
    {
        $divida = $this->emprestimo();
        $segunda = $divida->installments()->where('number', 2)->first();
        $this->servico()->updateInstallment($segunda, '700.00', null);

        // Mês seguinte: o vencimento vem da parcela já corrigida.
        app(FixedBillService::class)->generateForMonth(2026, 11);

        $v = FixedBillPayment::query()->forPeriod(2026, 11)->sole();
        $this->assertSame('700.00', $v->expected_amount);
        $this->assertSame('700.00', $v->effectiveAmount());
    }

    public function test_conta_fixa_que_ja_existia_com_o_mesmo_nome_e_vinculada_e_nao_duplicada(): void
    {
        $existente = FixedBill::factory()->create([
            'profile_id' => $this->perfil->id, 'name' => 'Empréstimo pessoal', 'amount' => '640.00', 'due_day' => 25,
        ]);

        $divida = $this->emprestimo();

        $this->assertSame(1, FixedBill::query()->count());
        $this->assertSame($existente->id, $this->contaDe($divida)->id);
        // O valor passa a seguir a parcela.
        $this->assertSame('650.00', $existente->refresh()->amount);
    }

    public function test_conta_fixa_com_o_mesmo_valor_no_mesmo_dia_tambem_e_vinculada(): void
    {
        $existente = FixedBill::factory()->create(['profile_id' => $this->perfil->id, 'name' => 'Parcela do carro', 'amount' => '650.00', 'due_day' => 25]);

        $divida = $this->emprestimo(['name' => 'Financiamento Honda']);

        $this->assertSame(1, FixedBill::query()->count());
        $this->assertSame($existente->id, $this->contaDe($divida)->id);
    }

    public function test_conta_fixa_sem_relacao_nao_e_vinculada_e_a_divida_ganha_a_dela(): void
    {
        FixedBill::factory()->create(['profile_id' => $this->perfil->id, 'name' => 'Internet', 'amount' => '120.00', 'due_day' => 10]);

        $divida = $this->emprestimo();

        $this->assertSame(2, FixedBill::query()->count());
        $this->assertNotSame('Internet', $this->contaDe($divida)->name);
    }

    public function test_pagar_pela_conta_fixa_paga_a_parcela_e_gera_uma_despesa_so(): void
    {
        $conta = BankAccount::factory()->for($this->perfil, 'profile')->for($this->membro, 'member')->create(['current_balance' => '1000.00']);
        $divida = $this->emprestimo(['bank_account_id' => $conta->id]);
        $v = FixedBillPayment::query()->sole();

        app(FixedBillService::class)->pay($v, null, null, $this->usuario->id);

        $this->assertSame(1, ExpenseRecord::query()->count());
        $parcela = $divida->installments()->where('number', 1)->first();
        $this->assertSame(DebtInstallmentStatus::Paid, $parcela->status);
        $despesa = ExpenseRecord::query()->sole();
        $this->assertSame($despesa->id, $parcela->expense_id);
        $this->assertSame('650.00', $despesa->amount);
        // O saldo cai uma vez só.
        $this->assertSame('350.00', $conta->refresh()->current_balance);

        $v->refresh();
        $this->assertSame(FixedBillPaymentStatus::Paid, $v->status);
        $this->assertSame($despesa->id, $v->expense_id);
        $this->assertSame('650.00', $v->amount_paid);
    }

    public function test_pagar_a_parcela_na_divida_baixa_o_vencimento_da_conta_fixa(): void
    {
        $divida = $this->emprestimo();

        $this->servico()->pay($divida->installments()->where('number', 1)->first(), CarbonImmutable::parse('2026-10-21'), null, null, $this->usuario->id, true);

        $v = FixedBillPayment::query()->sole();
        $this->assertSame(FixedBillPaymentStatus::Paid, $v->status);
        $this->assertSame(ExpenseRecord::query()->sole()->id, $v->expense_id);
        $this->assertSame('2026-10-21', $v->paid_at->toDateString());
    }

    public function test_desfazer_o_pagamento_da_parcela_reabre_o_vencimento(): void
    {
        $divida = $this->emprestimo();
        $parcela = $divida->installments()->where('number', 1)->first();
        $this->servico()->pay($parcela, CarbonImmutable::parse('2026-10-21'), null, null, $this->usuario->id, true);

        $this->servico()->unlink($parcela->refresh());

        $v = FixedBillPayment::query()->sole();
        $this->assertSame(FixedBillPaymentStatus::Pending, $v->status);
        $this->assertNull($v->expense_id);
        $this->assertNull($v->amount_paid);
    }

    public function test_conta_fixa_sai_da_lista_quando_a_ultima_parcela_e_paga_e_volta_se_o_pagamento_for_desfeito(): void
    {
        $divida = $this->emprestimo(['term' => 1]);
        $parcela = $divida->installments()->sole();

        $this->servico()->pay($parcela, CarbonImmutable::parse('2026-10-21'), null, null, $this->usuario->id, true);

        $conta = $this->contaDe($divida);
        $this->assertFalse($conta->is_active);
        $this->assertNotNull($conta->auto_ended_at);

        $this->servico()->unlink($parcela->refresh());

        $conta->refresh();
        $this->assertTrue($conta->is_active);
        $this->assertNull($conta->auto_ended_at);
        $this->assertSame(FixedBillPaymentStatus::Pending, FixedBillPayment::query()->sole()->status);
    }

    public function test_a_conta_encerrada_nao_gera_vencimento_nos_meses_seguintes(): void
    {
        $divida = $this->emprestimo(['term' => 2]);

        foreach ($divida->installments as $p) {
            $this->servico()->pay($p, CarbonImmutable::parse('2026-10-21'), null, null, $this->usuario->id, true);
        }

        $this->assertSame(0, app(FixedBillService::class)->generateForMonth(2026, 12));
        $this->assertFalse($this->contaDe($divida)->is_active);
    }

    public function test_conta_tirada_da_lista_pela_pessoa_nao_volta(): void
    {
        $divida = $this->emprestimo();
        app(FixedBillService::class)->deactivate($this->contaDe($divida));

        $r = app(DebtFixedBillSync::class)->sync($divida->refresh());

        $this->assertSame('removida', $r['acao']);
        $this->assertFalse($this->contaDe($divida)->is_active);
        $this->assertSame(1, FixedBill::query()->count());
    }

    public function test_sincronizar_de_novo_nao_duplica_conta_nem_vencimento(): void
    {
        $divida = $this->emprestimo();

        app(DebtFixedBillSync::class)->syncAll();
        app(DebtFixedBillSync::class)->syncAll();

        $this->assertSame(1, FixedBill::query()->count());
        $this->assertSame(1, FixedBillPayment::query()->count());
        $this->assertSame(1, FixedBill::query()->where('debt_id', $divida->id)->count());
    }

    public function test_consorcio_ganha_a_conta_fixa_e_a_parcela_e_o_valor_da_parcela_real(): void
    {
        $divida = $this->consorcio();
        $conta = $this->contaDe($divida);

        $this->assertSame('Consórcio do apartamento', $conta->name);
        $this->assertSame('1000.00', $conta->amount);
        $this->assertSame(10, $conta->due_day);
        $this->assertSame($divida->necessity, $conta->necessity);

        $v = FixedBillPayment::query()->sole();
        $this->assertSame('2026-10-10', $v->due_date->toDateString());
        // O vencimento já passou: nasce como atrasado, igual à parcela.
        $this->assertSame(FixedBillPaymentStatus::Overdue, $v->status);
    }

    public function test_consorcio_vincula_a_conta_fixa_que_ja_existia(): void
    {
        $existente = FixedBill::factory()->create(['profile_id' => $this->perfil->id, 'name' => 'Consórcio Ademicon', 'amount' => '980.00', 'due_day' => 10]);

        $divida = $this->consorcio();

        $this->assertSame(1, FixedBill::query()->count());
        $this->assertSame($existente->id, $this->contaDe($divida)->id);
    }

    public function test_reajuste_do_consorcio_muda_o_valor_previsto_do_vencimento(): void
    {
        $divida = $this->consorcio();

        $this->servico()->registerAdjustment($divida, '10', CarbonImmutable::parse('2026-10-20'));

        $this->assertSame('1100.00', FixedBillPayment::query()->sole()->expected_amount);
        $this->assertSame('1100.00', $this->contaDe($divida)->amount);
    }

    public function test_consorcio_com_parcela_pulada_nao_gera_vencimento_para_ela(): void
    {
        $divida = $this->consorcio();
        $primeira = $divida->installments()->where('number', 1)->first();

        $this->servico()->markDiluted($primeira);

        $this->assertSame(FixedBillPaymentStatus::Skipped, FixedBillPayment::query()->sole()->status);
    }

    public function test_divida_oculta_gera_conta_fixa_oculta_do_conjuge(): void
    {
        $divida = $this->emprestimo(['member_id' => $this->membro->id, 'is_private' => true]);

        $conta = $this->contaDe($divida);
        $this->assertTrue($conta->is_private);
        $this->assertSame($this->membro->id, $conta->member_id);

        // Tirar a marca da dívida tira da conta: ela segue a dívida.
        $this->servico()->update($divida, ['is_private' => false, 'member_id' => $this->membro->id]);
        $this->assertFalse($conta->refresh()->is_private);
    }

    public function test_excluir_a_divida_encerra_a_conta_fixa_e_pula_o_que_estava_em_aberto(): void
    {
        $divida = $this->emprestimo();
        $contaId = $this->contaDe($divida)->id;

        $this->servico()->delete($divida);

        $conta = FixedBill::query()->findOrFail($contaId);
        $this->assertFalse($conta->is_active);
        $this->assertNull($conta->debt_id);
        $this->assertSame(FixedBillPaymentStatus::Skipped, FixedBillPayment::query()->sole()->status);
    }

    public function test_parcela_de_divida_nao_gera_aviso_de_conta_fixa_porque_a_divida_ja_avisa(): void
    {
        Notification::fake();
        $divida = $this->emprestimo(['first_due_date' => '2026-10-23']);

        app(FixedBillService::class)->notifyUpcomingDueDates(CarbonImmutable::parse('2026-10-20'), 3);

        Notification::assertNotSentTo($this->usuario, FixedBillDueSoon::class);
        $this->assertSame(1, FixedBillPayment::query()->count());
        $this->assertNotNull($divida->id);
    }

    public function test_o_conciliar_do_extrato_nao_oferece_vencimento_de_divida(): void
    {
        $divida = $this->emprestimo(['name' => 'Empréstimo pessoal']);
        ExpenseRecord::factory()->create([
            'profile_id' => $this->perfil->id, 'description' => 'EMPRESTIMO PESSOAL', 'amount' => '650.00', 'expense_date' => '2026-10-24',
            'category_id' => $divida->category_id,
        ]);

        $this->assertSame([], app(FixedBillReconciliationService::class)->suggestions(2026, 10));
    }

    public function test_o_pagar_da_tela_de_contas_fixas_paga_a_parcela(): void
    {
        $divida = $this->emprestimo();
        $v = FixedBillPayment::query()->sole();

        Livewire::test(FixedBillsIndex::class)->call('pay', $v->id);

        $this->assertSame(DebtInstallmentStatus::Paid, $divida->installments()->where('number', 1)->first()->status);
        $this->assertSame(1, ExpenseRecord::query()->count());
    }

    public function test_conta_de_divida_nao_se_edita_na_tela_de_contas_fixas(): void
    {
        $divida = $this->emprestimo();

        Livewire::test(FixedBillsIndex::class)
            ->call('editBill', $this->contaDe($divida)->id)
            ->assertSet('editingBillId', null)
            ->assertSet('showBillForm', false);
    }

    public function test_o_comando_simula_sem_gravar_e_com_apply_grava(): void
    {
        $divida = $this->emprestimo();
        // Dívida de antes desta mudança: sem conta fixa.
        FixedBillPayment::withoutProfileScope()->delete();
        FixedBill::withoutProfileScope()->delete();

        $this->artisan('cerne:sync-debt-bills')->expectsOutputToContain('Simulação')->assertExitCode(0);
        $this->assertSame(0, FixedBill::withoutProfileScope()->count());

        $this->artisan('cerne:sync-debt-bills', ['--apply' => true])->expectsOutputToContain('Gravado')->assertExitCode(0);
        $this->assertSame(1, FixedBill::withoutProfileScope()->where('debt_id', $divida->id)->count());
    }

    public function test_o_aviso_ao_salvar_diz_que_a_conta_fixa_nasce_e_ate_quando(): void
    {
        Livewire::test(DebtsIndex::class)->call('openForm')
            ->set('kind', 'loan')->set('name', 'Empréstimo pessoal')->set('installmentAmount', '650,00')->set('term', '6')->set('firstDueDate', '2026-10-25')
            ->assertSee('Contas fixas: esta dívida aparece lá sozinha, vencendo todo dia 25, até a última parcela (03/2027).')
            ->call('save')->assertHasNoErrors();

        $this->assertSame(1, FixedBill::query()->count());
    }

    public function test_o_aviso_ao_salvar_diz_qual_conta_existente_sera_vinculada(): void
    {
        FixedBill::factory()->create(['profile_id' => $this->perfil->id, 'name' => 'Empréstimo pessoal', 'amount' => '640.00', 'due_day' => 25]);

        Livewire::test(DebtsIndex::class)->call('openForm')
            ->set('kind', 'loan')->set('name', 'Empréstimo pessoal')->set('installmentAmount', '650,00')->set('term', '6')->set('firstDueDate', '2026-10-25')
            ->assertSee('a conta "Empréstimo pessoal", que já existe, será vinculada a esta dívida')
            ->call('save')->assertHasNoErrors();

        $this->assertSame(1, FixedBill::query()->count());
    }

    public function test_a_rotina_agendada_das_contas_fixas_poe_a_divida_antiga_na_lista(): void
    {
        // Roda o fechamento de routes/console.php, não só o serviço: nome de classe errado ali derruba a rotina todo dia sem teste ver.
        $divida = $this->emprestimo();
        FixedBillPayment::withoutProfileScope()->delete();
        FixedBill::withoutProfileScope()->delete();

        $evento = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn ($e) => $e->description === 'contas-fixas');
        $this->assertNotNull($evento, 'a rotina precisa estar agendada');

        $evento->run($this->app);

        $this->assertSame(1, FixedBill::withoutProfileScope()->where('debt_id', $divida->id)->count());
        $this->assertSame(1, FixedBillPayment::withoutProfileScope()->count());
    }

    public function test_acordo_de_divida_em_aberto_gera_a_conta_fixa_so_depois_do_acordo(): void
    {
        $divida = $this->servico()->createOpenDebt(['name' => 'Cartão em atraso', 'current_balance' => '3000.00', 'status' => 'open']);
        $this->assertSame(0, FixedBill::query()->count());

        $this->servico()->registerAgreement($divida, array_merge(['total' => '2400.00', 'installments' => 4, 'first_due_date' => '2026-11-10'], $this->classificacao()));

        $conta = $this->contaDe($divida);
        $this->assertSame('600.00', $conta->amount);
        $this->assertSame(10, $conta->due_day);
    }
}
