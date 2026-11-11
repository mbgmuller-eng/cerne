<?php

namespace Tests\Feature;

use App\Enums\DebtInstallmentStatus;
use App\Enums\DebtKind;
use App\Enums\MemberRole;
use App\Livewire\Debts\DebtShow;
use App\Livewire\Debts\DebtsIndex;
use App\Models\BankAccount;
use App\Models\Debt;
use App\Models\DebtInstallment;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\DebtService;
use App\Support\Debts\DebtClassification;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Dívida: o quadro "Ao salvar" do cadastro (parcelas, as já pagas, consórcio, dívida em aberto), as dicas dos campos, a mensagem de regra de negócio
 * no formulário (não mais no campo "Nome") e o aviso de Pagar parcela. O texto sai da mesma conta que grava, e cada teste confere o que é gravado.
 */
class DebtFormConsequenceTest extends TestCase
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

    public function test_financiamento_sac_diz_a_primeira_e_a_ultima_parcela_que_o_cronograma_grava(): void
    {
        Livewire::test(DebtsIndex::class)->call('openForm')
            ->set('name', 'Apartamento')->set('principal', '120000,00')->set('rate', '12')->set('monthlyFees', '50,00')
            ->set('term', '12')->set('firstDueDate', '2026-11-05')
            ->assertSee('Serão criadas 12 parcelas, de 05/11/2026 a 05/10/2027, de R$ 11.250,00 (a 1ª) a R$ 10.150,00 (a última).')
            ->assertSee('É uma estimativa pelo contrato')
            ->call('save')->assertHasNoErrors();

        // O aviso é o que o cronograma gravou: a mesma conta, não uma cópia dela.
        $divida = Debt::query()->sole();
        $this->assertSame('11250.00', $divida->installments()->where('number', 1)->value('amount'));
        $this->assertSame('10150.00', $divida->installments()->where('number', 12)->value('amount'));
    }

    public function test_parcelas_informadas_ja_pagas_nascem_pagas_sem_despesa(): void
    {
        BankAccount::factory()->for($this->perfil, 'profile')->for($this->membro, 'member')->create(['current_balance' => '1000.00']);

        Livewire::test(DebtsIndex::class)->call('openForm')
            ->set('kind', 'loan')
            ->set('name', 'Empréstimo pessoal')->set('installmentAmount', '650,00')->set('term', '6')->set('firstDueDate', '2026-10-25')->set('paidBefore', '2')
            ->assertSee('Serão criadas 6 parcelas de R$ 650,00, de 25/10/2026 a 25/03/2027.')
            ->assertSee('As 2 primeiras parcelas entram como pagas, sem criar despesa nem mexer no saldo de conta.')
            ->assertSee('A 1ª em aberto é a 3, com vencimento em 25/12/2026.')
            ->call('save')->assertHasNoErrors();

        $divida = Debt::query()->sole();
        $this->assertSame(2, $divida->installments()->where('status', DebtInstallmentStatus::Paid)->count());
        $this->assertSame(0, ExpenseRecord::withoutProfileScope()->count());
        $this->assertSame('2026-12-25', $divida->installments()->where('status', DebtInstallmentStatus::Open)->orderBy('number')->first()->due_date->toDateString());
        $this->assertSame('1000.00', BankAccount::withoutProfileScope()->sole()->current_balance);
    }

    public function test_consorcio_diz_as_puladas_a_primeira_em_aberto_e_a_data_do_primeiro_reajuste(): void
    {
        Livewire::test(DebtsIndex::class)->call('openForm')
            ->set('kind', 'consortium')
            ->set('creditAmount', '80000,00')->set('installmentAmount', '900,00')->set('term', '80')->set('firstDueDate', '2026-11-10')
            ->set('paidBefore', '2')->set('skippedInstallments', '3')
            ->set('adjustmentIndex', 'ipca')->set('adjustmentEvery', '12')->set('adjustmentFirstAfter', '6')
            ->assertSee('Serão criadas 80 parcelas de R$ 900,00, de 10/11/2026 a 10/06/2033.')
            ->assertSee('A parcela pulada fica fora do que está em aberto e do que foi pago.')
            ->assertSee('As 2 parcelas já pagas entram como pagas, sem criar despesa nem mexer no saldo de conta.')
            ->assertSee('A 1ª parcela em aberto é a 4, com vencimento em 10/02/2027.')
            ->assertSee('vira despesa de investimento (Consórcio)')
            ->assertSee('O Cerne avisa do primeiro reajuste da carta em 10/05/2027.')
            ->call('save')->assertHasNoErrors();

        $divida = Debt::query()->sole();
        $this->assertSame('2027-05-10', $divida->next_adjustment_on->toDateString());
        $this->assertSame(DebtInstallmentStatus::Diluted, $divida->installments()->where('number', 3)->first()->status);
        $this->assertSame(4, $divida->installments()->where('status', DebtInstallmentStatus::Open)->orderBy('number')->first()->number);
    }

    public function test_divida_em_aberto_diz_que_nao_gera_parcela_nem_despesa(): void
    {
        Livewire::test(DebtsIndex::class)->call('openForm')
            ->set('kind', 'debt')->set('name', 'Cartão atrasado')->set('currentBalance', '4250,00')
            ->assertSee('Entra no total devedor com saldo de R$ 4.250,00, informado em 20/10/2026.')
            ->assertSee('Não gera parcelas nem despesas até você registrar um acordo.')
            ->call('save')->assertHasNoErrors();

        $divida = Debt::query()->sole();
        $this->assertSame('4250.00', $divida->current_balance);
        $this->assertSame(0, DebtInstallment::withoutProfileScope()->count());
        $this->assertSame(0, ExpenseRecord::withoutProfileScope()->count());
    }

    public function test_regra_de_negocio_recusada_aparece_no_formulario_e_nao_no_campo_nome(): void
    {
        Livewire::test(DebtsIndex::class)->call('openForm')
            ->set('kind', 'consortium')
            ->set('creditAmount', '80000,00')->set('installmentAmount', '900,00')->set('term', '80')->set('firstDueDate', '2026-11-10')
            ->set('paidBefore', '0')->set('skippedInstallments', '9')
            ->call('save')
            ->assertHasErrors('form')->assertHasNoErrors('name')
            ->assertSee('As parcelas puladas precisam estar entre as que já venceram');

        $this->assertSame(0, Debt::query()->count());
    }

    public function test_valor_com_letra_e_recusado_em_portugues(): void
    {
        Livewire::test(DebtsIndex::class)->call('openForm')
            ->set('name', 'Apartamento')->set('principal', 'abc')->set('rate', '12')->set('term', '12')->set('firstDueDate', '2026-11-05')
            ->call('save')
            ->assertHasErrors(['principal' => 'regex'])
            ->assertSee('Informe só números, como 1250,00.');

        $this->assertSame(0, Debt::query()->count());
    }

    public function test_as_dicas_do_cadastro_explicam_o_que_cada_campo_faz(): void
    {
        Livewire::test(DebtsIndex::class)->call('openForm')
            ->assertSee('Sem a data, o relatório de imposto de renda estima um mês antes do 1º vencimento.')
            ->assertSee('Somado ao valor de cada parcela.')
            ->assertSee('Entram como pagas, sem criar despesa nem mexer no saldo de nenhuma conta.')
            ->assertSee('Cada parcela que você pagar vira uma despesa nesta categoria. Mudar depois vale só para as próximas.')
            ->assertSee('Ao pagar uma parcela, o valor sai do saldo desta conta. Sem conta, só a despesa é criada.')
            ->set('amortization', 'fixed')
            ->assertSee('Com ele o Cerne calcula a taxa que você paga de fato, para comparar com outras dívidas.')
            ->set('kind', 'debt')
            ->assertSee('Entra no total devedor. Atualize depois, na tela da dívida, quando o valor mudar.');
    }

    private function emprestimo(?BankAccount $conta = null): Debt
    {
        $classificacao = DebtClassification::suggest(DebtKind::Loan, null);

        return app(DebtService::class)->create([
            'kind' => 'loan', 'name' => 'Empréstimo pessoal', 'amortization' => 'fixed', 'installment_amount' => '650.00', 'term' => 6,
            'first_due_date' => '2026-10-25', 'paid_before' => 0, 'member_id' => $this->membro->id,
            'category_id' => $classificacao['category_id'], 'subcategory_id' => $classificacao['subcategory_id'],
            'bank_account_id' => $conta?->id,
        ]);
    }

    public function test_pagar_parcela_diz_a_despesa_que_nasce_e_a_conta_do_contrato_e_a_usada_mesmo_sem_escolher(): void
    {
        $conta = BankAccount::factory()->for($this->perfil, 'profile')->for($this->membro, 'member')->create(['bank_name' => 'Banco Azul', 'current_balance' => '1000.00']);
        $divida = $this->emprestimo($conta);
        $parcela = $divida->installments[0];
        $categoria = $divida->category->name;

        Livewire::test(DebtShow::class, ['debt' => $divida])
            ->call('openPay', $parcela->id)
            ->assertSee('A parcela prevê R$ 650,00.')
            ->assertSee('Será criada uma despesa de R$ 650,00 em 20/10/2026, na categoria '.$categoria.'.')
            ->assertSee('O saldo de Banco Azul cai R$ 650,00.')
            // "Nenhuma" (vazio) não tira a conta do contrato: o aviso diz o que de fato acontece.
            ->set('payAccountId', '')
            ->assertSee('O saldo de Banco Azul cai R$ 650,00.')
            ->call('pay')->assertHasNoErrors();

        $this->assertSame('350.00', $conta->refresh()->current_balance);
        $this->assertSame('650.00', ExpenseRecord::withoutProfileScope()->sole()->amount);
    }

    public function test_pagar_sem_conta_nenhuma_diz_que_so_a_despesa_e_criada_e_valor_com_letra_e_recusado(): void
    {
        $divida = $this->emprestimo();
        $parcela = $divida->installments[0];

        Livewire::test(DebtShow::class, ['debt' => $divida])
            ->call('openPay', $parcela->id)
            ->assertSee('Nenhuma conta é debitada.')
            ->set('payAmount', '700,50')->assertSee('Será criada uma despesa de R$ 700,50')
            ->set('payAmount', 'xx')->call('pay')
            ->assertHasErrors(['payAmount' => 'regex'])
            ->assertSee('Informe só números, como 1250,00.');

        $this->assertSame(0, ExpenseRecord::withoutProfileScope()->count());
    }
}
