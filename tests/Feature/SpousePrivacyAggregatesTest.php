<?php

namespace Tests\Feature;

use App\Enums\FixedBillPaymentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\MemberRole;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\FinancialProfile;
use App\Models\FixedBill;
use App\Models\DocumentUpload;
use App\Models\FixedBillPayment;
use App\Livewire\FixedBills\FixedBillsIndex;
use App\Models\ProfileMember;
use App\Models\RecurringIncome;
use App\Models\RecurringIncomeOccurrence;
use App\Models\User;
use App\Services\DashboardService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use App\Enums\RecurringIncomeStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fatura de cartão e vencimento de conta fixa herdam a privacidade do pai (o cartão, a conta), mas o painel os soma e lista direto. Um cartão ou uma
 * conta que um cônjuge marcou como privado não pode aparecer, nem somado, para o outro. Quem é dono do dado e o consultor seguem vendo tudo.
 */
class SpousePrivacyAggregatesTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private User $bruno;

    private FinancialProfile $perfil;

    private ProfileMember $membroAna;

    private ProfileMember $membroBruno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ana = User::factory()->create();
        $this->bruno = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $this->ana->id]);
        $this->membroAna = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->ana->id, 'role' => MemberRole::Primary, 'name' => 'Ana']);
        $this->membroBruno = ProfileMember::factory()->secondary()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->bruno->id, 'name' => 'Bruno']);

        $this->entrar($this->ana, $this->membroAna);
        $hoje = CarbonImmutable::now();

        foreach ([['Cartão aberto da Ana', true, '100.00'], ['Cartão secreto da Ana', false, '400.00']] as [$nome, $aberto, $total]) {
            $cartao = CreditCard::factory()->for($this->perfil, 'profile')->create(['member_id' => $this->membroAna->id, 'card_name' => $nome, 'visible_to_partner' => $aberto, 'is_joint' => false, 'is_active' => true]);
            CreditCardInvoice::query()->create([
                'profile_id' => $this->perfil->id, 'credit_card_id' => $cartao->id, 'year' => $hoje->year, 'month' => $hoje->month, 'closing_date' => $hoje->addDay()->toDateString(),
                'due_date' => $hoje->addDays(5)->toDateString(), 'total_amount' => $total, 'status' => InvoiceStatus::Open,
            ]);
        }

        foreach ([['Aluguel da casa', false, 2000], ['Conta escondida da Ana', true, 1500]] as [$nome, $privada, $valor]) {
            $conta = FixedBill::factory()->for($this->perfil, 'profile')->create(['member_id' => $this->membroAna->id, 'name' => $nome, 'amount' => $valor, 'is_private' => $privada, 'is_active' => true]);
            FixedBillPayment::query()->create([
                'profile_id' => $this->perfil->id, 'fixed_bill_id' => $conta->id, 'year' => $hoje->year, 'month' => $hoje->month, 'due_date' => $hoje->addDays(3)->toDateString(),
                'status' => FixedBillPaymentStatus::Pending,
            ]);
        }
    }

    private function entrar(User $usuario, ProfileMember $membro): void
    {
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($this->perfil, $membro);
    }

    public function test_o_conjuge_nao_ve_a_fatura_nem_o_vencimento_do_que_o_outro_escondeu(): void
    {
        $this->entrar($this->bruno, $this->membroBruno);

        $this->assertSame(['100.00'], CreditCardInvoice::query()->pluck('total_amount')->map(fn ($v) => (string) $v)->all());
        $this->assertSame(1, FixedBillPayment::query()->count());
        $this->assertSame('Aluguel da casa', FixedBillPayment::query()->with('fixedBill')->sole()->fixedBill->name);
    }

    public function test_o_painel_do_conjuge_soma_e_lista_so_o_que_ele_enxerga(): void
    {
        // A Ana pergunta primeiro: o painel dela (com tudo) entra no cache dela, não no do Bruno.
        $this->assertSame('500.00', app(DashboardService::class)->netWorth()['faturas']);

        $this->entrar($this->bruno, $this->membroBruno);
        $painel = app(DashboardService::class);

        $this->assertSame('100.00', $painel->netWorth()['faturas'], 'sem a fatura do cartão secreto da Ana');
        $nomes = collect($painel->upcomingBills()['itens'])->pluck('nome')->all();
        $this->assertContains('Aluguel da casa', $nomes);
        $this->assertContains('Fatura Cartão aberto da Ana', $nomes);
        $this->assertNotContains('Conta escondida da Ana', $nomes);
        $this->assertNotContains('Fatura Cartão secreto da Ana', $nomes);
        $this->assertSame('2100.00', $painel->upcomingBills()['total']);
    }

    public function test_quem_e_dono_do_dado_continua_vendo_tudo_e_o_consultor_tambem(): void
    {
        $this->assertSame(2, CreditCardInvoice::query()->count());
        $this->assertSame(2, FixedBillPayment::query()->count());

        $consultor = User::factory()->consultant()->create();
        $this->actingAs($consultor);
        app(ProfileContext::class)->set($this->perfil, null, true);

        $this->assertSame(2, CreditCardInvoice::query()->count());
        $this->assertSame(2, FixedBillPayment::query()->count());
    }

    public function test_o_conjuge_nao_ve_a_ocorrencia_nem_soma_a_receita_recorrente_que_o_outro_escondeu(): void
    {
        $this->entrar($this->ana, $this->membroAna);
        $hoje = CarbonImmutable::now();
        // A tela gera a ocorrência do mês no dia de vencimento da receita: o dado de teste já nasce nesse dia, para não duplicar.
        $dia = min($hoje->day, 28);
        $vencimento = $hoje->setDay($dia)->toDateString();

        foreach ([['Salário da Ana', false, '3000.00'], ['Bico escondido da Ana', true, '800.00']] as [$nome, $privada, $valor]) {
            $receita = RecurringIncome::factory()->for($this->perfil, 'profile')->create(['member_id' => $this->membroAna->id, 'name' => $nome, 'amount' => $valor, 'is_private' => $privada, 'is_active' => true, 'due_day' => $dia]);
            RecurringIncomeOccurrence::query()->create([
                'profile_id' => $this->perfil->id, 'recurring_income_id' => $receita->id, 'year' => $hoje->year, 'month' => $hoje->month, 'due_date' => $vencimento,
                'status' => RecurringIncomeStatus::Pending,
            ]);
        }

        $this->assertSame(2, RecurringIncomeOccurrence::query()->count(), 'a Ana vê as duas');

        $this->entrar($this->bruno, $this->membroBruno);

        $this->assertSame(1, RecurringIncomeOccurrence::query()->count());

        $tela = Livewire::test(FixedBillsIndex::class)->call('setTab', 'receitas')->set('viewAs', $this->membroAna->id)->assertSee('Salário da Ana')->assertDontSee('Bico escondido');
        $this->assertSame('3000.00', $tela->instance()->incomeTotal, 'o total do mês não soma o que o Bruno não enxerga');
        $this->assertSame('3000.00', $tela->instance()->incomeOutstanding);
    }

    public function test_o_conjuge_nao_ve_a_importacao_do_extrato_de_conta_ou_cartao_que_o_outro_escondeu(): void
    {
        $this->entrar($this->ana, $this->membroAna);
        $aberta = \App\Models\BankAccount::factory()->for($this->perfil, 'profile')->create(['member_id' => $this->membroAna->id, 'visible_to_partner' => true, 'is_joint' => false]);
        $secreta = \App\Models\BankAccount::factory()->for($this->perfil, 'profile')->create(['member_id' => $this->membroAna->id, 'visible_to_partner' => false, 'is_joint' => false]);
        $cartaoSecreto = CreditCard::query()->where('card_name', 'Cartão secreto da Ana')->sole();

        $envio = fn (string $nome, array $alvo) => DocumentUpload::query()->create([
            'profile_id' => $this->perfil->id, 'uploaded_by_user_id' => $this->ana->id, 'member_id' => $this->membroAna->id, 'document_type' => 'bank_statement',
            'original_filename' => $nome, 'storage_path' => 'importacoes/'.$nome, 'processing_status' => 'completed',
        ] + $alvo);
        $envio('extrato-aberto.pdf', ['bank_account_id' => $aberta->id]);
        $envio('extrato-secreto.pdf', ['bank_account_id' => $secreta->id]);
        $envio('fatura-secreta.pdf', ['credit_card_id' => $cartaoSecreto->id]);
        $envio('relatorio-do-perfil.pdf', []);

        $this->assertSame(4, DocumentUpload::query()->count(), 'quem enviou vê todas as suas');

        $this->entrar($this->bruno, $this->membroBruno);
        $this->assertEqualsCanonicalizing(['extrato-aberto.pdf', 'relatorio-do-perfil.pdf'], DocumentUpload::query()->pluck('original_filename')->all());

        // E o processamento em fila, sem perfil ativo, continua achando todas.
        app(ProfileContext::class)->clear();
        $this->assertSame(4, DocumentUpload::withoutProfileScope()->count());
    }

    public function test_as_rotinas_sem_perfil_ativo_continuam_atravessando_os_perfis(): void
    {
        app(ProfileContext::class)->clear();

        $this->assertSame(2, CreditCardInvoice::withoutProfileScope()->count());
        $this->assertSame(2, FixedBillPayment::withoutProfileScope()->count());
    }
}
