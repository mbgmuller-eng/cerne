<?php

namespace Tests\Feature;

use App\Enums\FixedBillPaymentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\MemberRole;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\FinancialProfile;
use App\Models\FixedBill;
use App\Models\FixedBillPayment;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\DashboardService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_as_rotinas_sem_perfil_ativo_continuam_atravessando_os_perfis(): void
    {
        app(ProfileContext::class)->clear();

        $this->assertSame(2, CreditCardInvoice::withoutProfileScope()->count());
        $this->assertSame(2, FixedBillPayment::withoutProfileScope()->count());
    }
}
