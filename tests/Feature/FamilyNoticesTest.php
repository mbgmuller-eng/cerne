<?php

namespace Tests\Feature;

use App\Enums\CaregiverAccess;
use App\Enums\CaregiverStatus;
use App\Enums\FixedBillPaymentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\MemberRole;
use App\Enums\Necessity;
use App\Livewire\Health\HealthAppointmentIndex;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\FixedBill;
use App\Models\FixedBillPayment;
use App\Models\HealthAppointment;
use App\Models\ProfileCaregiver;
use App\Models\ProfileMember;
use App\Models\User;
use App\Notifications\FamilyFinanceNotice;
use App\Services\CareParticipants;
use App\Services\FamilyNotifier;
use App\Services\FixedBillService;
use App\Services\HealthAppointmentService;
use App\Services\InvoiceService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Avisos a quem acompanha as finanças (conta a vencer, em atraso, gasto alto e o resumo da semana) e o "quem leva" na saúde de um casal. Os avisos
 * seguem a regra do que o familiar enxerga: nada privado, e só as pessoas que ele acompanha.
 */
class FamilyNoticesTest extends TestCase
{
    use RefreshDatabase;

    private User $pai;

    private User $mae;

    private FinancialProfile $perfil;

    private ProfileMember $membroPai;

    private ProfileMember $membroMae;

    private User $filhoDoPai;

    private User $filhoDosDois;

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

        $this->acesso($this->filhoDoPai, $this->membroPai);
        $this->acesso($this->filhoDosDois, $this->membroPai);
        $this->acesso($this->filhoDosDois, $this->membroMae);

        app(ProfileContext::class)->clear();
    }

    /** @param  array<string, mixed>  $extra */
    private function acesso(User $filho, ProfileMember $membro, array $extra = []): ProfileCaregiver
    {
        return ProfileCaregiver::query()->create($extra + [
            'profile_id' => $this->perfil->id, 'member_id' => $membro->id, 'user_id' => $filho->id, 'invited_email' => $filho->email, 'invited_name' => $filho->name,
            'access_level' => CaregiverAccess::Read, 'status' => CaregiverStatus::Active, 'accepted_at' => now(), 'can_view_finance' => true, 'can_view_health' => false,
        ]);
    }

    private function conta(ProfileMember $membro, string $nome, bool $privada, int $dias, FixedBillPaymentStatus $status = FixedBillPaymentStatus::Pending): FixedBillPayment
    {
        $hoje = CarbonImmutable::now();
        $bill = FixedBill::factory()->for($this->perfil, 'profile')->create(['member_id' => $membro->id, 'name' => $nome, 'amount' => '300.00', 'is_private' => $privada, 'is_active' => true]);

        return FixedBillPayment::query()->create([
            'profile_id' => $this->perfil->id, 'fixed_bill_id' => $bill->id, 'year' => $hoje->year, 'month' => $hoje->month,
            'due_date' => $hoje->addDays($dias)->toDateString(), 'status' => $status,
        ]);
    }

    private function cartaoComFatura(ProfileMember $membro, string $nome, bool $visivel, int $dias, InvoiceStatus $status = InvoiceStatus::Open): CreditCardInvoice
    {
        $hoje = CarbonImmutable::now();
        $cartao = CreditCard::factory()->for($this->perfil, 'profile')->create(['member_id' => $membro->id, 'card_name' => $nome, 'visible_to_partner' => $visivel, 'is_joint' => false, 'is_active' => true]);

        return CreditCardInvoice::query()->create([
            'profile_id' => $this->perfil->id, 'credit_card_id' => $cartao->id, 'year' => $hoje->year, 'month' => $hoje->month, 'closing_date' => $hoje->subDays(5)->toDateString(),
            'due_date' => $hoje->addDays($dias)->toDateString(), 'total_amount' => '800.00', 'status' => $status,
        ]);
    }

    private function avisos(string $kind): \Closure
    {
        return fn (FamilyFinanceNotice $n) => $n->kind === $kind;
    }

    // --- Quem recebe -------------------------------------------------------------------------------------------------------

    public function test_so_recebe_quem_acompanha_as_financas_de_quem_aquele_dado_e_com_o_aviso_ligado(): void
    {
        $semAviso = User::factory()->create();
        $this->acesso($semAviso, $this->membroPai, ['notify_finance' => false]);
        $soSaude = User::factory()->create();
        $this->acesso($soSaude, $this->membroPai, ['can_view_finance' => false, 'can_view_health' => true]);
        $convidado = User::factory()->create();
        $this->acesso($convidado, $this->membroPai, ['status' => CaregiverStatus::Invited, 'user_id' => null]);

        $paraPai = app(FamilyNotifier::class)->recipients($this->perfil->id, $this->membroPai->id, 'notify_finance')->pluck('id')->all();
        $paraMae = app(FamilyNotifier::class)->recipients($this->perfil->id, $this->membroMae->id, 'notify_finance')->pluck('id')->all();
        $daFamilia = app(FamilyNotifier::class)->recipients($this->perfil->id, null, 'notify_finance')->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$this->filhoDoPai->id, $this->filhoDosDois->id], $paraPai);
        $this->assertSame([$this->filhoDosDois->id], $paraMae, 'o filho do pai não acompanha a mãe');
        $this->assertEqualsCanonicalizing([$this->filhoDoPai->id, $this->filhoDosDois->id], $daFamilia);
    }

    // --- Conta e fatura a vencer ---------------------------------------------------------------------------------------------

    public function test_conta_a_vencer_avisa_a_familia_menos_o_que_e_privado(): void
    {
        Notification::fake();
        $this->conta($this->membroPai, 'Aluguel do pai', false, 0);
        $this->conta($this->membroPai, 'Conta escondida do pai', true, 0);
        $this->conta($this->membroMae, 'Plano da mãe', false, 0);

        app(FixedBillService::class)->notifyUpcomingDueDates(CarbonImmutable::now(), 0);

        // O filho do pai: só o aluguel. A filha dos dois: o aluguel e o plano da mãe. Nunca a conta escondida.
        Notification::assertSentToTimes($this->filhoDoPai, FamilyFinanceNotice::class, 1);
        Notification::assertSentTo($this->filhoDoPai, FamilyFinanceNotice::class, fn ($n) => $n->kind === 'bill_due_soon' && str_contains($n->heading, 'Aluguel do pai'));
        Notification::assertSentToTimes($this->filhoDosDois, FamilyFinanceNotice::class, 2);
        Notification::assertNotSentTo($this->filhoDosDois, FamilyFinanceNotice::class, fn ($n) => str_contains($n->heading, 'escondida'));
    }

    public function test_fatura_a_vencer_avisa_a_familia_menos_o_cartao_so_do_dono(): void
    {
        Notification::fake();
        $this->cartaoComFatura($this->membroPai, 'Cartão do pai', true, 0);
        $this->cartaoComFatura($this->membroPai, 'Cartão secreto do pai', false, 0);

        app(InvoiceService::class)->notifyUpcomingDueDates(CarbonImmutable::now(), 0);

        Notification::assertSentToTimes($this->filhoDoPai, FamilyFinanceNotice::class, 1);
        Notification::assertSentTo($this->filhoDoPai, FamilyFinanceNotice::class, fn ($n) => $n->kind === 'invoice_due_soon' && str_contains($n->heading, 'Cartão do pai'));
    }

    // --- Atrasos ---------------------------------------------------------------------------------------------------------------

    public function test_atraso_avisa_uma_vez_so_e_ignora_privado_e_muito_antigo(): void
    {
        $this->conta($this->membroPai, 'Luz do pai', false, -2, FixedBillPaymentStatus::Overdue);
        $this->conta($this->membroPai, 'Conta escondida do pai', true, -2, FixedBillPaymentStatus::Overdue);
        $this->conta($this->membroPai, 'Conta velha', false, -45, FixedBillPaymentStatus::Overdue);
        $this->cartaoComFatura($this->membroPai, 'Cartão do pai', true, -3, InvoiceStatus::Overdue);

        $this->assertSame(4, app(FamilyNotifier::class)->notifyOverdue(), 'luz e cartão, para os dois filhos');
        $tipos = $this->filhoDoPai->notifications()->get()->map(fn ($n) => $n->data['notice'])->sort()->values()->all();
        $this->assertSame(['bill_overdue', 'invoice_overdue'], $tipos);

        $this->assertSame(0, app(FamilyNotifier::class)->notifyOverdue(), 'não avisa de novo o que já avisou');
        $this->assertSame(2, $this->filhoDoPai->notifications()->count());
    }

    // --- Gasto alto ------------------------------------------------------------------------------------------------------------------

    private function gasto(ProfileMember $membro, string $valor, bool $privado = false, ?CarbonImmutable $data = null, array $extra = []): ExpenseRecord
    {
        return ExpenseRecord::factory()->for($this->perfil, 'profile')->create($extra + [
            'member_id' => $membro->id, 'description' => 'Compra grande', 'amount' => $valor, 'necessity' => Necessity::Essential,
            'expense_date' => ($data ?? CarbonImmutable::now())->toDateString(), 'is_private' => $privado, 'is_refund' => false,
        ]);
    }

    public function test_gasto_que_chega_ao_limite_avisa_a_familia_com_as_regras_de_sempre(): void
    {
        $this->perfil->update(['family_alert_threshold' => '1000.00']);
        Notification::fake();

        $this->gasto($this->membroPai, '999.99');
        Notification::assertNothingSent();

        $this->gasto($this->membroPai, '1500.00');
        Notification::assertSentToTimes($this->filhoDoPai, FamilyFinanceNotice::class, 1);
        Notification::assertSentTo($this->filhoDoPai, FamilyFinanceNotice::class, fn ($n) => $n->kind === 'high_expense' && str_contains($n->message, 'Compra grande'));

        // Privado, estorno, parcela que não é a primeira e lançamento antigo não avisam.
        Notification::fake();
        $this->gasto($this->membroPai, '5000.00', privado: true);
        $this->gasto($this->membroPai, '5000.00', extra: ['is_refund' => true]);
        $this->gasto($this->membroPai, '5000.00', extra: ['installment_number' => 3]);
        $this->gasto($this->membroPai, '5000.00', data: CarbonImmutable::now()->subDays(20));
        Notification::assertNothingSent();

        // O gasto da mãe não chega ao filho que só acompanha o pai.
        $this->gasto($this->membroMae, '2000.00');
        Notification::assertNotSentTo($this->filhoDoPai, FamilyFinanceNotice::class);
        Notification::assertSentTo($this->filhoDosDois, FamilyFinanceNotice::class, $this->avisos('high_expense'));
    }

    public function test_sem_limite_combinado_ou_com_o_aviso_desligado_nao_avisa(): void
    {
        Notification::fake();

        $this->gasto($this->membroPai, '9000.00');
        Notification::assertNothingSent();

        $this->perfil->update(['family_alert_threshold' => '1000.00']);
        ProfileCaregiver::query()->where('user_id', $this->filhoDoPai->id)->update(['notify_alerts' => false]);
        $this->gasto($this->membroPai, '9000.00');
        Notification::assertNotSentTo($this->filhoDoPai, FamilyFinanceNotice::class);
        Notification::assertSentTo($this->filhoDosDois, FamilyFinanceNotice::class);
    }

    // --- Resumo da semana -------------------------------------------------------------------------------------------------------

    public function test_resumo_da_semana_so_soma_o_que_o_familiar_enxerga_e_nao_repete(): void
    {
        $segunda = CarbonImmutable::parse('2026-11-09');
        Notification::fake();

        $this->gasto($this->membroPai, '50.00', data: $segunda->subDays(2));
        $this->gasto($this->membroPai, '100.00', privado: true, data: $segunda->subDays(2));
        $this->gasto($this->membroMae, '30.00', data: $segunda->subDays(2));
        $this->gasto($this->membroPai, '999.00', data: $segunda->subDays(20));

        $this->assertSame(2, app(FamilyNotifier::class)->sendWeeklySummaries($segunda), 'um por familiar');

        Notification::assertSentTo($this->filhoDoPai, FamilyFinanceNotice::class, fn ($n) => $n->kind === 'weekly_summary'
            && str_contains($n->message, '50,00') && ! str_contains($n->message, '150,00') && ! str_contains($n->message, '80,00') && str_contains($n->heading, 'Dilermando'));
        Notification::assertSentTo($this->filhoDosDois, FamilyFinanceNotice::class, fn ($n) => $n->kind === 'weekly_summary' && str_contains($n->message, '80,00'));

        // O contexto de cada familiar foi solto: nada fica aberto depois da rotina.
        $this->assertNull(app(ProfileContext::class)->profile());

        Notification::fake();
        $this->assertSame(0, app(FamilyNotifier::class)->sendWeeklySummaries($segunda), 'a mesma semana não envia de novo');
        $this->assertSame(2, app(FamilyNotifier::class)->sendWeeklySummaries($segunda->addWeek()), 'a semana seguinte envia');
    }

    public function test_resumo_respeita_o_aviso_desligado_e_acesso_encerrado(): void
    {
        Notification::fake();
        ProfileCaregiver::query()->where('user_id', $this->filhoDoPai->id)->update(['notify_weekly' => false]);
        ProfileCaregiver::query()->where('user_id', $this->filhoDosDois->id)->where('member_id', $this->membroMae->id)->update(['status' => CaregiverStatus::Revoked]);

        $this->assertSame(1, app(FamilyNotifier::class)->sendWeeklySummaries(CarbonImmutable::parse('2026-11-09')));
        Notification::assertNotSentTo($this->filhoDoPai, FamilyFinanceNotice::class);
    }

    public function test_a_rotina_do_resumo_esta_agendada_na_segunda_de_manha(): void
    {
        $evento = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn ($e) => $e->description === 'resumo-da-familia');

        $this->assertNotNull($evento);
        $this->assertSame('20 8 * * 1', $evento->expression);
    }

    // --- "Quem leva" na saúde de um casal ----------------------------------------------------------------------------------------

    private function consulta(ProfileMember $membro): HealthAppointment
    {
        app(ProfileContext::class)->set($this->perfil, $membro);
        $this->actingAs($membro->user);

        return app(HealthAppointmentService::class)->create($membro, ['kind' => 'consultation', 'title' => 'Cardiologista', 'scheduled_at' => now()->addDays(4)], $membro);
    }

    public function test_quem_leva_so_existe_para_a_pessoa_cuja_saude_alguem_de_fora_acompanha(): void
    {
        $consulta = $this->consulta($this->membroPai);
        $this->assertTrue(app(CareParticipants::class)->forAppointment($consulta)->isEmpty(), 'só quem acompanha as finanças: ninguém acompanha a saúde');

        $filha = User::factory()->create(['name' => 'Filha C']);
        $this->acesso($filha, $this->membroPai, ['can_view_health' => true, 'can_view_finance' => false]);

        $participantes = app(CareParticipants::class)->forAppointment($consulta)->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['Dilermando', 'Esposa', 'Filha C'], $participantes);

        // Para a consulta da mãe, a filha do pai não é participante.
        $daMae = $this->consulta($this->membroMae);
        $this->assertTrue(app(CareParticipants::class)->forAppointment($daMae)->isEmpty());
    }

    public function test_um_familiar_da_saude_se_oferece_para_levar_e_so_ele_pode_ser_escolhido(): void
    {
        $filha = User::factory()->create(['name' => 'Filha C']);
        $this->acesso($filha, $this->membroPai, ['can_view_health' => true, 'can_view_finance' => false]);
        $estranho = User::factory()->create();
        $consulta = $this->consulta($this->membroPai);

        app(HealthAppointmentService::class)->volunteerEscort($consulta, $filha);
        $this->assertSame($filha->id, $consulta->fresh()->escort_user_id);

        $this->expectException(AuthorizationException::class);
        app(HealthAppointmentService::class)->volunteerEscort($consulta, $estranho);
    }

    public function test_a_agenda_do_casal_mostra_o_quem_leva_quando_ha_familiar_acompanhando(): void
    {
        $consulta = $this->consulta($this->membroPai);
        Livewire::test(HealthAppointmentIndex::class)->assertDontSee('Quem leva');

        $filha = User::factory()->create(['name' => 'Filha C']);
        $this->acesso($filha, $this->membroPai, ['can_view_health' => true, 'can_view_finance' => false]);

        Livewire::test(HealthAppointmentIndex::class)->assertSee('Quem leva');

        // E quem acompanha só a saúde da mãe ou só as finanças não ganha a opção na consulta do pai.
        $this->assertNotNull($consulta);
    }
}
