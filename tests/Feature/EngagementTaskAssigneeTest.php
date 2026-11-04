<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\EngagementTaskOwner;
use App\Enums\EngagementTaskStatus;
use App\Enums\MemberRole;
use App\Livewire\Engagement\EngagementTasks;
use App\Models\ConsultantClient;
use App\Models\EngagementTask;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Notifications\EngagementNotice;
use App\Services\EngagementReminderService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Num casal, "Cliente" são duas pessoas: a tarefa do acompanhamento pode ser de uma delas (ou dos dois, como sempre foi). Só quem recebeu a tarefa é avisado
 * e lembrado; sozinho, o seletor continua com "Cliente" e "Consultor".
 */
class EngagementTaskAssigneeTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private User $bruno;

    private User $consultor;

    private FinancialProfile $perfil;

    private ProfileMember $membroAna;

    private ProfileMember $membroBruno;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 09:00:00'));

        $this->ana = User::factory()->create(['name' => 'Ana Silva']);
        $this->bruno = User::factory()->create(['name' => 'Bruno Silva']);
        $this->consultor = User::factory()->consultant()->create(['name' => 'Marcelo Consultor']);
        $this->perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $this->ana->id]);
        $this->membroAna = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->ana->id, 'role' => MemberRole::Primary, 'name' => 'Ana Silva']);
        $this->membroBruno = ProfileMember::factory()->secondary()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->bruno->id, 'name' => 'Bruno Silva']);
        ConsultantClient::factory()->create(['consultant_id' => $this->consultor->id, 'client_id' => $this->ana->id, 'status' => ConsultantClientStatus::Active, 'accepted_at' => now()]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function comoConsultor(): void
    {
        $this->actingAs($this->consultor);
        app(ProfileContext::class)->set($this->perfil, null, true);
    }

    private function comoCliente(User $usuario, ProfileMember $membro): void
    {
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($this->perfil, $membro);
    }

    public function test_o_casal_escolhe_os_dois_ou_cada_pessoa_e_o_consultor_fica_na_lista(): void
    {
        $this->comoConsultor();

        $tela = Livewire::test(EngagementTasks::class)->call('openForm');
        $opcoes = $tela->viewData('donos');

        $this->assertSame(['client' => 'Os dois', 'member:'.$this->membroAna->id => 'Ana Silva', 'member:'.$this->membroBruno->id => 'Bruno Silva', 'consultant' => 'Consultor'], $opcoes);
    }

    public function test_sozinho_o_seletor_continua_com_cliente_e_consultor(): void
    {
        $this->membroBruno->delete();
        $this->comoConsultor();

        $opcoes = Livewire::test(EngagementTasks::class)->call('openForm')->viewData('donos');

        $this->assertSame(['client' => 'Cliente', 'consultant' => 'Consultor'], $opcoes);
    }

    public function test_tarefa_de_uma_pessoa_guarda_quem_e_mostra_o_nome(): void
    {
        $this->comoConsultor();

        Livewire::test(EngagementTasks::class)
            ->call('openForm')->set('title', 'Enviar o informe de rendimentos')->set('owner', 'member:'.$this->membroBruno->id)->call('save')->assertHasNoErrors()
            ->assertSee('Bruno Silva');

        $tarefa = EngagementTask::query()->sole();
        $this->assertSame(EngagementTaskOwner::Client, $tarefa->owner, 'continua sendo do lado do cliente');
        $this->assertSame($this->membroBruno->id, $tarefa->assignee_member_id);
        $this->assertSame('Bruno Silva', $tarefa->responsibleLabel());
    }

    public function test_editar_para_os_dois_solta_a_pessoa(): void
    {
        $this->comoConsultor();
        $tela = Livewire::test(EngagementTasks::class)->call('openForm')->set('title', 'Reunir os extratos')->set('owner', 'member:'.$this->membroAna->id)->call('save');
        $id = EngagementTask::query()->sole()->id;

        $tela->call('openForm', $id)->assertSet('owner', 'member:'.$this->membroAna->id)->set('owner', 'client')->call('save');

        $this->assertNull(EngagementTask::query()->sole()->assignee_member_id);
        $this->assertSame('Cliente', EngagementTask::query()->sole()->responsibleLabel());
    }

    public function test_pessoa_de_outro_perfil_e_recusada(): void
    {
        $outro = FinancialProfile::factory()->create();
        $estranho = ProfileMember::factory()->create(['profile_id' => $outro->id, 'name' => 'Estranho']);
        $this->comoConsultor();

        Livewire::test(EngagementTasks::class)->call('openForm')->set('title', 'Tarefa')->set('owner', 'member:'.$estranho->id)->call('save')->assertHasErrors('owner');

        $this->assertSame(0, EngagementTask::query()->count());
    }

    public function test_so_quem_recebeu_a_tarefa_e_avisado(): void
    {
        Notification::fake();
        $this->comoConsultor();

        Livewire::test(EngagementTasks::class)->call('openForm')->set('title', 'Enviar a apólice')->set('owner', 'member:'.$this->membroBruno->id)->call('save');

        Notification::assertSentTo($this->bruno, EngagementNotice::class, fn (EngagementNotice $n) => $n->kind === 'task_assigned');
        Notification::assertNotSentTo($this->ana, EngagementNotice::class);
    }

    public function test_tarefa_dos_dois_avisa_os_dois(): void
    {
        Notification::fake();
        $this->comoConsultor();

        Livewire::test(EngagementTasks::class)->call('openForm')->set('title', 'Revisar o orçamento')->set('owner', 'client')->call('save');

        Notification::assertSentTo($this->ana, EngagementNotice::class);
        Notification::assertSentTo($this->bruno, EngagementNotice::class);
    }

    public function test_um_do_casal_da_tarefa_ao_outro_e_o_outro_e_avisado(): void
    {
        Notification::fake();
        $this->comoCliente($this->ana, $this->membroAna);

        Livewire::test(EngagementTasks::class)->call('openForm')->set('title', 'Pagar o IPVA')->set('owner', 'member:'.$this->membroBruno->id)->call('save');

        Notification::assertSentTo($this->bruno, EngagementNotice::class);
        Notification::assertNotSentTo($this->ana, EngagementNotice::class);
    }

    public function test_lembrete_de_prazo_vai_so_para_o_responsavel(): void
    {
        Notification::fake();
        EngagementTask::withoutGlobalScopes()->create([
            'profile_id' => $this->perfil->id, 'consultant_id' => $this->consultor->id, 'title' => 'Entregar o holerite', 'owner' => EngagementTaskOwner::Client,
            'assignee_member_id' => $this->membroBruno->id, 'due_date' => '2026-10-21', 'status' => EngagementTaskStatus::Todo,
        ]);

        app(EngagementReminderService::class)->run(CarbonImmutable::parse('2026-10-20'));

        Notification::assertSentTo($this->bruno, EngagementNotice::class, fn (EngagementNotice $n) => $n->kind === 'task_due_tomorrow');
        Notification::assertNotSentTo($this->ana, EngagementNotice::class);
    }
}
