<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\DebtInstallmentStatus;
use App\Enums\DebtStatus;
use App\Enums\MemberRole;
use App\Models\ConsultantClient;
use App\Models\Debt;
use App\Models\ExpenseCategory;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Notifications\DebtInstallmentOverdue;
use App\Services\DebtReminderService;
use App\Services\DebtService;
use App\Support\NotificationPresenter;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Parcela em atraso: aviso a quem deve (1º dia e uma semana depois) e ao consultor do cliente (uma semana), sem repetir.
 */
class DebtOverdueReminderTest extends TestCase
{
    use RefreshDatabase;

    private User $cliente;

    private User $consultor;

    private FinancialProfile $perfil;

    private ProfileMember $membro;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 09:00:00'));
        $this->seed(TaxonomySeeder::class);

        $this->cliente = User::factory()->create(['name' => 'Ana Silva']);
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->cliente->id]);
        $this->membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->cliente->id, 'role' => MemberRole::Primary, 'name' => 'Ana Silva']);
        $this->consultor = User::factory()->consultant()->create();
        ConsultantClient::factory()->create(['consultant_id' => $this->consultor->id, 'client_id' => $this->cliente->id, 'status' => ConsultantClientStatus::Active]);

        $this->actingAs($this->cliente);
        app(ProfileContext::class)->set($this->perfil, $this->membro);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** Empréstimo de 1 parcela só, vencida há `$diasDeAtraso` dias. */
    private function atrasada(int $diasDeAtraso, array $campos = []): Debt
    {
        $cat = ExpenseCategory::available()->where('name', 'Financeiros')->firstOrFail();

        return app(DebtService::class)->create(array_merge([
            'kind' => 'loan', 'name' => 'Empréstimo', 'amortization' => 'fixed', 'installment_amount' => '650.00', 'term' => 1, 'paid_before' => 0,
            'first_due_date' => CarbonImmutable::now()->subDays($diasDeAtraso)->toDateString(), 'category_id' => $cat->id,
        ], $campos));
    }

    private function avisar(int $diasAFrente = 0): int
    {
        return app(DebtReminderService::class)->notifyOverdue(CarbonImmutable::now()->addDays($diasAFrente));
    }

    public function test_avisa_quem_deve_no_primeiro_dia_de_atraso_e_nao_repete(): void
    {
        Notification::fake();
        $this->atrasada(1);

        self::assertSame(1, $this->avisar());
        self::assertSame(0, $this->avisar(), 'idempotente');

        Notification::assertSentToTimes($this->cliente, DebtInstallmentOverdue::class, 1);
        Notification::assertNotSentTo($this->consultor, DebtInstallmentOverdue::class);
    }

    public function test_uma_semana_depois_lembra_quem_deve_e_avisa_o_consultor_com_o_nome_do_cliente(): void
    {
        Notification::fake();
        $this->atrasada(1);
        $this->avisar();                       // dia 1: só o cliente

        self::assertSame(2, $this->avisar(6), 'dia 7: lembrete ao cliente e aviso ao consultor');
        self::assertSame(0, $this->avisar(7), 'dia 8: nada de novo');

        Notification::assertSentToTimes($this->cliente, DebtInstallmentOverdue::class, 2);
        Notification::assertSentTo($this->consultor, DebtInstallmentOverdue::class, function (DebtInstallmentOverdue $n) {
            return $n->isForConsultant() && $n->clientName === 'Ana Silva' && $n->daysLate === 7 && str_contains($n->message(), 'Ana Silva: Empréstimo · parcela 1/1 está atrasada há 7 dias');
        });
    }

    public function test_quem_so_ve_a_parcela_depois_de_uma_semana_recebe_um_aviso_e_nao_dois(): void
    {
        Notification::fake();
        $this->atrasada(10);

        self::assertSame(2, $this->avisar(), 'um para o cliente e um para o consultor');
        self::assertSame(0, $this->avisar());

        Notification::assertSentToTimes($this->cliente, DebtInstallmentOverdue::class, 1);
        Notification::assertSentToTimes($this->consultor, DebtInstallmentOverdue::class, 1);
    }

    public function test_nao_avisa_no_dia_do_vencimento_nem_depois_de_45_dias(): void
    {
        Notification::fake();
        $this->atrasada(0);
        self::assertSame(0, $this->avisar(), 'vence hoje: ainda não está atrasada');

        $this->atrasada(46, ['name' => 'Muito antiga']);
        self::assertSame(0, $this->avisar());

        $this->atrasada(45, ['name' => 'No limite']);
        self::assertSame(2, $this->avisar());

        Notification::assertSentToTimes($this->cliente, DebtInstallmentOverdue::class, 1);
    }

    public function test_parcela_paga_pulada_ou_de_divida_quitada_nao_avisa(): void
    {
        Notification::fake();
        $paga = $this->atrasada(3, ['name' => 'Paga']);
        app(DebtService::class)->pay($paga->installments[0], CarbonImmutable::now());

        $pulada = $this->atrasada(3, ['name' => 'Pulada']);
        $pulada->installments()->update(['status' => DebtInstallmentStatus::Diluted]);

        $quitada = $this->atrasada(3, ['name' => 'Quitada']);
        $quitada->update(['status' => DebtStatus::PaidOff]);

        self::assertSame(0, $this->avisar());
        Notification::assertNothingSent();
    }

    public function test_sem_vinculo_ativo_o_consultor_nao_e_avisado(): void
    {
        Notification::fake();
        ConsultantClient::query()->update(['status' => ConsultantClientStatus::Inactive]);
        $this->atrasada(10);

        self::assertSame(1, $this->avisar());

        Notification::assertNotSentTo($this->consultor, DebtInstallmentOverdue::class);
    }

    public function test_divida_privada_do_conjuge_avisa_so_o_dono_e_o_consultor(): void
    {
        Notification::fake();
        $conjuge = User::factory()->create();
        ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $conjuge->id, 'role' => MemberRole::Secondary, 'name' => 'Helen']);
        $this->atrasada(10, ['member_id' => $this->membro->id, 'is_private' => true]);

        $this->avisar();

        Notification::assertSentTo($this->cliente, DebtInstallmentOverdue::class);
        Notification::assertNotSentTo($conjuge, DebtInstallmentOverdue::class);
        Notification::assertSentTo($this->consultor, DebtInstallmentOverdue::class);
    }

    public function test_dividas_da_familia_avisam_os_dois_do_casal(): void
    {
        Notification::fake();
        $conjuge = User::factory()->create();
        ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $conjuge->id, 'role' => MemberRole::Secondary, 'name' => 'Helen']);
        $this->atrasada(2);

        $this->avisar();

        Notification::assertSentTo($this->cliente, DebtInstallmentOverdue::class);
        Notification::assertSentTo($conjuge, DebtInstallmentOverdue::class);
    }

    public function test_parcela_de_consorcio_atrasada_tambem_avisa(): void
    {
        Notification::fake();
        app(DebtService::class)->createConsortium([
            'consortium_type' => 'property', 'credit_amount' => '100000.00', 'installment_amount' => '1000.00', 'term' => 10,
            'first_due_date' => CarbonImmutable::now()->subDays(2)->toDateString(),
        ]);

        self::assertSame(1, $this->avisar());
    }

    public function test_conteudo_do_aviso_e_o_que_a_central_de_avisos_mostra(): void
    {
        $this->atrasada(3);
        $parcela = Debt::query()->sole()->installments()->first();

        $aviso = DebtInstallmentOverdue::forInstallment($parcela, 3);
        $dados = $aviso->toArray($this->cliente);

        self::assertSame('debt_installment_overdue', $dados['kind']);
        self::assertSame($this->perfil->id, $dados['profile_id']);
        self::assertStringContainsString('está atrasada há 3 dias', $dados['message']);
        self::assertSame(['database'], array_values(array_diff($aviso->via($this->cliente), ['mail', \NotificationChannels\WebPush\WebPushChannel::class])));

        $paraConsultor = DebtInstallmentOverdue::forInstallment($parcela, 8, 'Ana Silva')->toArray($this->consultor);
        self::assertSame('debt_installment_overdue_consultant', $paraConsultor['kind']);

        $this->cliente->notify($aviso);
        $linha = $this->cliente->notifications()->first();
        $apresentado = NotificationPresenter::present($linha);
        self::assertSame('Parcela atrasada', $apresentado['heading']);
        self::assertStringContainsString('atrasada há 3 dias', $apresentado['message']);
    }
}
