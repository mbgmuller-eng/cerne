<?php

namespace Tests\Feature;

use App\Enums\AgendaLayout;
use App\Enums\CaregiverAccess;
use App\Enums\CaregiverStatus;
use App\Enums\HealthAppointmentStatus;
use App\Livewire\Health\HealthAppointmentIndex;
use App\Models\FinancialProfile;
use App\Models\HealthAppointment;
use App\Models\ProfileCaregiver;
use App\Models\ProfileMember;
use App\Models\User;
use App\Notifications\HealthAppointmentUpcoming;
use App\Services\HealthAppointmentService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Agenda de Saúde: depois da data, confirma-se se a consulta foi realizada; a que não aconteceu fica clara (cancelada ou não realizada) e o
 * histórico fica sempre no fim da tela.
 */
class HealthAppointmentStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private FinancialProfile $perfil;

    private ProfileMember $membro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = User::factory()->create(['name' => 'Marcelo']);
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->usuario->id]);
        $this->membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuario->id, 'name' => 'Marcelo']);

        $this->actingAs($this->usuario);
        app(ProfileContext::class)->set($this->perfil, $this->membro);
    }

    public function test_nasce_agendada_e_a_que_passou_sem_confirmar_espera_confirmacao(): void
    {
        $futura = $this->consulta('Futura', now()->addDays(3));
        $passada = $this->consulta('Passada', now()->subDays(2));

        $this->assertSame(HealthAppointmentStatus::Scheduled, $futura->status);
        $this->assertFalse($futura->awaitsConfirmation());
        $this->assertTrue($passada->awaitsConfirmation());

        $this->assertSame(['Futura'], HealthAppointment::query()->upcoming()->pluck('title')->all());
        $this->assertSame(['Passada'], HealthAppointment::query()->past()->pluck('title')->all());
    }

    public function test_so_depois_da_data_confirma_se_foi_realizada_e_cancelar_vale_a_qualquer_hora(): void
    {
        $service = app(HealthAppointmentService::class);
        $futura = $this->consulta('Futura', now()->addDays(3));

        try {
            $service->setStatus($futura, HealthAppointmentStatus::Done, $this->usuario);
            $this->fail('Não dá para confirmar uma consulta que ainda não aconteceu.');
        } catch (DomainException) {
        }

        $service->setStatus($futura, HealthAppointmentStatus::Cancelled, $this->usuario);
        $futura = $futura->fresh();

        $this->assertSame(HealthAppointmentStatus::Cancelled, $futura->status);
        $this->assertSame($this->usuario->id, $futura->status_by_user_id);
        $this->assertNotNull($futura->status_at);

        // Cancelada deixa de ser "próxima" e vai para o histórico, mesmo antes da data.
        $this->assertSame([], HealthAppointment::query()->upcoming()->pluck('title')->all());
        $this->assertSame(['Futura'], HealthAppointment::query()->past()->pluck('title')->all());

        // Voltar para agendada corrige um engano e limpa quem confirmou.
        $service->setStatus($futura, HealthAppointmentStatus::Scheduled, $this->usuario);
        $this->assertNull($futura->fresh()->status_by_user_id);
        $this->assertSame(['Futura'], HealthAppointment::query()->upcoming()->pluck('title')->all());
    }

    public function test_a_tela_mostra_cada_situacao_com_clareza_e_o_historico_no_fim(): void
    {
        $this->consulta('Consulta que veio', now()->subDays(5), HealthAppointmentStatus::Done);
        $this->consulta('Consulta cancelada', now()->subDays(4), HealthAppointmentStatus::Cancelled);
        $this->consulta('Consulta faltada', now()->subDays(3), HealthAppointmentStatus::NotDone);
        $this->consulta('Consulta sem resposta', now()->subDays(2));
        $this->consulta('Consulta futura', now()->addDays(2));

        $ordens = [
            AgendaLayout::List->value => ['Próximas', 'Consulta ou exame', 'Histórico'],
            AgendaLayout::Calendar->value => ['Consulta ou exame', 'Próximas', 'Histórico'],
        ];

        // O histórico fica no fim nas duas ordens que a pessoa pode escolher (lista ou calendário primeiro); a legenda é do calendário.
        foreach ([AgendaLayout::List, AgendaLayout::Calendar] as $layout) {
            $this->usuario->update(['agenda_layout' => $layout]);

            $this->get(route('health.appointments.index'))->assertOk()
                ->assertSee('Realizada')->assertSee('Cancelada')->assertSee('Não realizada')->assertSee('Aguardando confirmação')->assertSee('Agendada')
                ->assertSee('1 consulta já passou e ainda não foi confirmada')
                ->assertSeeInOrder($ordens[$layout->value], false);
        }
    }

    public function test_confirma_e_remove_pela_tela_inclusive_a_que_ja_passou(): void
    {
        $passada = $this->consulta('Passada', now()->subDays(2));

        Livewire::test(HealthAppointmentIndex::class)
            ->call('markStatus', $passada->id, 'done')
            ->assertHasNoErrors();
        $this->assertSame(HealthAppointmentStatus::Done, $passada->fresh()->status);

        Livewire::test(HealthAppointmentIndex::class)->call('delete', $passada->id);
        $this->assertNull(HealthAppointment::query()->find($passada->id));
    }

    public function test_confirmar_realizada_antes_da_data_mostra_o_erro_e_nao_muda_nada(): void
    {
        $futura = $this->consulta('Futura', now()->addDays(3));

        Livewire::test(HealthAppointmentIndex::class)->call('markStatus', $futura->id, 'done')->assertHasErrors('status');
        $this->assertSame(HealthAppointmentStatus::Scheduled, $futura->fresh()->status);
    }

    public function test_cancelada_e_nao_realizada_nao_geram_lembrete(): void
    {
        Notification::fake();
        $amanha = CarbonImmutable::now()->addDay()->setTime(10, 0);
        $normal = $this->consulta('Normal', $amanha);
        $cancelada = $this->consulta('Cancelada', $amanha->addHour(), HealthAppointmentStatus::Cancelled);
        app(ProfileContext::class)->clear();

        $enviados = app(HealthAppointmentService::class)->notifyUpcoming(CarbonImmutable::now(), 1);

        $this->assertSame(1, $enviados);
        Notification::assertSentTo($this->usuario, HealthAppointmentUpcoming::class, fn ($n) => $n->title === 'Normal');
        $this->assertNotNull($cancelada);
        $this->assertNotNull($normal);
    }

    public function test_cuidador_de_leitura_ve_a_situacao_mas_nao_confirma(): void
    {
        $passada = $this->consulta('Passada', now()->subDays(2), HealthAppointmentStatus::Cancelled);
        $leitor = User::factory()->create();
        ProfileCaregiver::query()->create([
            'profile_id' => $this->perfil->id, 'member_id' => $this->membro->id, 'user_id' => $leitor->id, 'invited_email' => $leitor->email, 'invited_name' => 'Leitor',
            'access_level' => CaregiverAccess::Read, 'status' => CaregiverStatus::Active, 'accepted_at' => now(),
        ]);

        // Entra como o cuidador faria: escolhe a pessoa e abre a agenda.
        $this->actingAs($leitor)->post(route('care.open', $this->perfil->id));

        $this->get(route('health.appointments.index'))->assertOk()->assertSee('Cancelada');

        Livewire::test(HealthAppointmentIndex::class)->call('markStatus', $passada->id, 'done')->assertStatus(403);
        $this->assertSame(HealthAppointmentStatus::Cancelled, $passada->fresh()->status);
    }

    // ----------------------------------------------------------------------

    private function consulta(string $titulo, \DateTimeInterface $quando, HealthAppointmentStatus $status = HealthAppointmentStatus::Scheduled): HealthAppointment
    {
        $consulta = app(HealthAppointmentService::class)->create($this->membro, ['kind' => 'consultation', 'title' => $titulo, 'scheduled_at' => $quando], $this->membro);

        if ($status !== HealthAppointmentStatus::Scheduled) {
            $consulta->update(['status' => $status, 'status_at' => now(), 'status_by_user_id' => $this->usuario->id]);
        }

        return $consulta->fresh();
    }
}
