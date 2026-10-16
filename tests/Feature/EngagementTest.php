<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\EngagementMeetingStatus;
use App\Enums\EngagementRequestStatus;
use App\Enums\EngagementTaskOwner;
use App\Enums\EngagementTaskStatus;
use App\Enums\MemberRole;
use App\Livewire\Consultant\EngagementWeek;
use App\Livewire\Engagement\EngagementHome;
use App\Livewire\Engagement\EngagementMeetings;
use App\Livewire\Engagement\EngagementNotes;
use App\Livewire\Engagement\EngagementRequests;
use App\Livewire\Engagement\EngagementTasks;
use App\Livewire\Profile\MyAccount;
use App\Mail\EngagementPlanMail;
use App\Models\ConsultantClient;
use App\Models\EngagementMeeting;
use App\Models\EngagementNote;
use App\Models\EngagementRequest;
use App\Models\EngagementTask;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Notifications\EngagementNotice;
use App\Services\EngagementAccess;
use App\Services\EngagementPlanReport;
use App\Services\EngagementReminderService;
use App\Services\EngagementService;
use App\Services\EngagementWeekService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Acompanhamento do consultor financeiro com o cliente: quem enxerga o quê, o histórico que sobrevive ao fim do vínculo,
 * as regras de reuniões, tarefas, demandas e notas, os lembretes e o plano em PDF.
 */
class EngagementTest extends TestCase
{
    use RefreshDatabase;

    private User $cliente;

    private FinancialProfile $perfil;

    private ProfileMember $membro;

    private User $consultor;

    private ConsultantClient $vinculo;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 09:00:00'));

        $this->cliente = User::factory()->create(['name' => 'Helen Cliente']);
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->cliente->id, 'profile_name' => 'Família Ferry']);
        $this->membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->cliente->id, 'role' => MemberRole::Primary, 'name' => 'Helen Cliente']);
        $this->consultor = User::factory()->consultant()->create(['name' => 'Marcelo Consultor']);
        $this->vinculo = $this->vincular($this->consultor);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function vincular(User $consultor): ConsultantClient
    {
        return ConsultantClient::factory()->create(['consultant_id' => $consultor->id, 'client_id' => $this->cliente->id, 'status' => ConsultantClientStatus::Active, 'accepted_at' => now()]);
    }

    /** Entra como o cliente (dono do perfil). */
    private function comoCliente(): void
    {
        $this->actingAs($this->cliente);
        app(ProfileContext::class)->set($this->perfil, $this->membro);
    }

    /** Entra como o consultor, dentro do perfil do cliente. */
    private function comoConsultor(?User $consultor = null): void
    {
        $this->actingAs($consultor ?? $this->consultor);
        app(ProfileContext::class)->set($this->perfil, null, true);
    }

    private function visitante()
    {
        return app(EngagementAccess::class)->viewer();
    }

    private function reuniao(array $campos = [], ?User $consultor = null): EngagementMeeting
    {
        return EngagementMeeting::withoutGlobalScopes()->create(array_merge([
            'profile_id' => $this->perfil->id, 'consultant_id' => ($consultor ?? $this->consultor)->id,
            'scheduled_at' => now()->addDays(2)->setTime(19, 0), 'status' => EngagementMeetingStatus::Scheduled,
        ], $campos));
    }

    private function tarefa(array $campos = [], ?User $consultor = null): EngagementTask
    {
        return EngagementTask::withoutGlobalScopes()->create(array_merge([
            'profile_id' => $this->perfil->id, 'consultant_id' => ($consultor ?? $this->consultor)->id,
            'title' => 'Tarefa', 'owner' => EngagementTaskOwner::Client, 'status' => EngagementTaskStatus::Todo, 'created_by_user_id' => $this->consultor->id,
        ], $campos));
    }

    // ------------------------------------------------------------------ quem tem a área

    public function test_cliente_sem_consultor_nao_tem_a_area(): void
    {
        $this->vinculo->update(['status' => ConsultantClientStatus::Inactive]);
        $this->comoCliente();

        self::assertNull($this->visitante());
        Livewire::test(EngagementHome::class)->assertStatus(404);
        $this->get(route('engagement.index'))->assertStatus(404);
    }

    public function test_cliente_com_consultor_ativo_tem_a_area_e_o_consultor_tambem(): void
    {
        $this->comoCliente();
        self::assertSame('client', $this->visitante()->role);
        self::assertSame([$this->consultor->id], $this->visitante()->consultantIds);
        Livewire::test(EngagementHome::class)->assertOk()->assertSee('Marcelo Consultor')->assertDontSee('Baixar plano em PDF');

        $this->comoConsultor();
        self::assertSame('consultant', $this->visitante()->role);
        Livewire::test(EngagementHome::class)->assertOk()->assertSee('Baixar plano em PDF');
    }

    public function test_conjuge_com_login_tambem_tem_a_area(): void
    {
        $conjuge = User::factory()->create();
        $membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $conjuge->id, 'role' => MemberRole::Secondary, 'name' => 'Cônjuge']);

        $this->actingAs($conjuge);
        app(ProfileContext::class)->set($this->perfil, $membro);

        self::assertSame('client', $this->visitante()->role);
    }

    public function test_corretor_e_consultor_nao_vinculado_nao_entram(): void
    {
        $corretor = User::factory()->broker()->create();
        ConsultantClient::factory()->create(['consultant_id' => $corretor->id, 'client_id' => $this->cliente->id, 'status' => ConsultantClientStatus::Active]);
        $this->comoConsultor($corretor);
        self::assertNull($this->visitante());

        $outro = User::factory()->consultant()->create();
        $this->comoConsultor($outro);
        self::assertNull($this->visitante());
    }

    public function test_cliente_de_outro_perfil_nao_enxerga(): void
    {
        $estranho = User::factory()->create();
        $perfilDele = FinancialProfile::factory()->create(['owner_user_id' => $estranho->id]);
        $membroDele = ProfileMember::factory()->create(['profile_id' => $perfilDele->id, 'user_id' => $estranho->id, 'role' => MemberRole::Primary]);
        $this->reuniao();

        $this->actingAs($estranho);
        app(ProfileContext::class)->set($perfilDele, $membroDele);
        self::assertNull($this->visitante());
        self::assertSame(0, EngagementMeeting::query()->count());

        // Mesmo com o contexto apontando para o perfil alheio, o escopo não deixa passar.
        app(ProfileContext::class)->set($this->perfil, $membroDele);
        self::assertSame(0, EngagementMeeting::query()->count());
    }

    // ------------------------------------------------------------------ histórico por relação

    public function test_historico_sobrevive_ao_fim_do_vinculo_e_volta_com_o_mesmo_consultor(): void
    {
        $this->tarefa(['title' => 'Enviar apólice']);
        $this->comoConsultor();
        self::assertSame(1, EngagementTask::query()->count());

        $this->vinculo->update(['status' => ConsultantClientStatus::Inactive]);
        $this->comoCliente();
        self::assertSame(0, EngagementTask::query()->count(), 'sem vínculo, o cliente não tem a área');
        self::assertSame(1, EngagementTask::withoutGlobalScopes()->count(), 'mas nada foi apagado');

        $this->vinculo->update(['status' => ConsultantClientStatus::Active]);
        $this->comoCliente();
        self::assertSame(['Enviar apólice'], EngagementTask::query()->pluck('title')->all());
    }

    public function test_consultor_novo_comeca_sem_historico(): void
    {
        $this->tarefa(['title' => 'Do consultor antigo']);
        $this->reuniao();
        $this->vinculo->update(['status' => ConsultantClientStatus::Inactive]);

        $novo = User::factory()->consultant()->create(['name' => 'Novo Consultor']);
        $this->vincular($novo);

        $this->comoCliente();
        self::assertSame([$novo->id], $this->visitante()->consultantIds);
        self::assertSame(0, EngagementTask::query()->count());
        self::assertSame(0, EngagementMeeting::query()->count());

        $this->comoConsultor($novo);
        self::assertSame(0, EngagementTask::query()->count());
    }

    public function test_consultor_nao_ve_a_relacao_de_outro_consultor_com_o_mesmo_cliente(): void
    {
        $outro = User::factory()->consultant()->create();
        $this->vincular($outro);
        $this->tarefa(['title' => 'Do primeiro']);
        $this->tarefa(['title' => 'Do segundo'], $outro);

        $this->comoConsultor();
        self::assertSame(['Do primeiro'], EngagementTask::query()->pluck('title')->all());

        $this->comoConsultor($outro);
        self::assertSame(['Do segundo'], EngagementTask::query()->pluck('title')->all());
    }

    // ------------------------------------------------------------------ notas

    public function test_nota_privada_e_so_do_consultor_que_a_escreveu(): void
    {
        $this->comoConsultor();
        app(EngagementService::class)->addNote($this->visitante(), 'Prefere vídeo à noite', true);
        app(EngagementService::class)->addNote($this->visitante(), 'Combinado: revisar reserva', false);

        self::assertSame(2, EngagementNote::query()->count());

        $this->comoCliente();
        self::assertSame(['Combinado: revisar reserva'], EngagementNote::query()->pluck('body')->all());

        $outro = User::factory()->consultant()->create();
        $this->vincular($outro);
        $this->comoConsultor($outro);
        self::assertSame(0, EngagementNote::query()->count());
    }

    public function test_cliente_nao_escreve_nota_privada_e_so_apaga_a_propria(): void
    {
        $this->comoConsultor();
        $deleConsultor = app(EngagementService::class)->addNote($this->visitante(), 'Do consultor', false);

        $this->comoCliente();
        $service = app(EngagementService::class);

        try {
            $service->addNote($this->visitante(), 'Tentativa', true);
            self::fail('o cliente não pode escrever nota privada');
        } catch (AuthorizationException) {
        }

        $propria = $service->addNote($this->visitante(), 'Minha nota', false);

        try {
            $service->deleteNote($this->visitante(), $deleConsultor);
            self::fail('só quem escreveu apaga');
        } catch (AuthorizationException) {
        }

        $service->deleteNote($this->visitante(), $propria);
        self::assertSame(1, EngagementNote::query()->count());
    }

    public function test_componente_de_notas_ignora_a_marca_privada_do_cliente(): void
    {
        $this->comoCliente();

        Livewire::test(EngagementNotes::class)->set('body', 'Quero falar de seguros')->set('private', true)->call('save');

        self::assertFalse(EngagementNote::query()->sole()->is_private);
    }

    // ------------------------------------------------------------------ reuniões

    public function test_so_o_consultor_marca_reuniao_e_o_cliente_e_avisado(): void
    {
        Notification::fake();
        $service = app(EngagementService::class);

        $this->comoCliente();
        try {
            $service->saveMeeting($this->visitante(), null, ['scheduled_at' => now()->addDays(3)]);
            self::fail('o cliente não marca reunião');
        } catch (AuthorizationException) {
        }

        $this->comoConsultor();
        $reuniao = $service->saveMeeting($this->visitante(), null, ['scheduled_at' => CarbonImmutable::parse('2026-10-22 19:00'), 'agenda' => "Reserva\nSeguros"]);

        self::assertSame(['Reserva', 'Seguros'], $reuniao->agendaItems());
        Notification::assertSentTo($this->cliente, EngagementNotice::class, fn (EngagementNotice $n) => $n->kind === 'meeting_scheduled');
        Notification::assertNotSentTo($this->consultor, EngagementNotice::class);

        $service->saveMeeting($this->visitante(), $reuniao, ['scheduled_at' => CarbonImmutable::parse('2026-10-23 10:00')]);
        Notification::assertSentTo($this->cliente, EngagementNotice::class, fn (EngagementNotice $n) => $n->kind === 'meeting_rescheduled');
    }

    public function test_os_dois_lados_editam_a_pauta_mas_so_antes_da_reuniao(): void
    {
        $reuniao = $this->reuniao();
        $service = app(EngagementService::class);

        $this->comoCliente();
        $service->updateAgenda($this->visitante(), $reuniao, 'Dúvida sobre o IR', 'Informe de rendimentos');
        self::assertSame('Dúvida sobre o IR', $reuniao->fresh()->agenda);

        $this->comoConsultor();
        $service->completeMeeting($this->visitante(), $reuniao->fresh(), 'Decidimos aportar R$ 500 por mês.');
        self::assertSame(EngagementMeetingStatus::Done, $reuniao->fresh()->status);

        $this->comoCliente();
        $this->expectException(AuthorizationException::class);
        $service->updateAgenda($this->visitante(), $reuniao->fresh(), 'Tarde demais', null);
    }

    public function test_componente_de_reunioes_marca_e_o_cliente_nao_consegue_abrir_o_formulario(): void
    {
        $this->comoConsultor();

        Livewire::test(EngagementMeetings::class)
            ->call('openForm')
            ->set('date', '2026-10-27')->set('time', '18:30')->set('duration', 60)->set('format', 'phone')->set('agenda', 'Reserva')
            ->call('save')
            ->assertHasNoErrors();

        $reuniao = EngagementMeeting::query()->sole();
        self::assertSame('2026-10-27 18:30', $reuniao->scheduled_at->format('Y-m-d H:i'));
        self::assertSame(60, $reuniao->duration_minutes);

        $this->comoCliente();
        Livewire::test(EngagementMeetings::class)->call('openForm')->assertForbidden();
        Livewire::test(EngagementMeetings::class)->assertSee('27/10 às 18:30', false);
    }

    public function test_formulario_de_reuniao_valida_a_data(): void
    {
        $this->comoConsultor();

        Livewire::test(EngagementMeetings::class)->call('openForm')->set('date', '')->call('save')->assertHasErrors(['date']);
        self::assertSame(0, EngagementMeeting::query()->count());
    }

    // ------------------------------------------------------------------ tarefas

    public function test_os_dois_criam_tarefas_e_quem_recebe_e_avisado(): void
    {
        Notification::fake();
        $service = app(EngagementService::class);

        $this->comoConsultor();
        $paraCliente = $service->saveTask($this->visitante(), null, ['title' => 'Enviar a apólice', 'owner' => 'client', 'due_date' => CarbonImmutable::parse('2026-10-30')]);
        Notification::assertSentTo($this->cliente, EngagementNotice::class, fn (EngagementNotice $n) => $n->kind === 'task_assigned' && str_contains($n->message, '30/10'));

        $this->comoCliente();
        $propria = $service->saveTask($this->visitante(), null, ['title' => 'Ler o material', 'owner' => 'client']);
        $paraConsultor = $service->saveTask($this->visitante(), null, ['title' => 'Montar cotação', 'owner' => 'consultant']);

        Notification::assertSentTo($this->consultor, EngagementNotice::class, fn (EngagementNotice $n) => $n->kind === 'task_assigned');
        self::assertSame(3, EngagementTask::query()->count());
        self::assertSame($this->cliente->id, $propria->created_by_user_id);
        self::assertSame(EngagementTaskOwner::Consultant, $paraConsultor->owner);
        self::assertSame(EngagementTaskOwner::Client, $paraCliente->owner);
    }

    public function test_cliente_muda_a_situacao_da_tarefa_dele_mas_nao_edita_nem_apaga_a_do_consultor(): void
    {
        $doCliente = $this->tarefa(['title' => 'Dele', 'owner' => EngagementTaskOwner::Client]);
        $doConsultor = $this->tarefa(['title' => 'Do consultor', 'owner' => EngagementTaskOwner::Consultant]);
        $service = app(EngagementService::class);

        $this->comoCliente();
        $service->setTaskStatus($this->visitante(), $doCliente, EngagementTaskStatus::Done);
        self::assertNotNull($doCliente->fresh()->completed_at);
        $service->setTaskStatus($this->visitante(), $doCliente, EngagementTaskStatus::Todo);
        self::assertNull($doCliente->fresh()->completed_at, 'reabrir limpa a data de conclusão');

        foreach ([fn () => $service->setTaskStatus($this->visitante(), $doConsultor, EngagementTaskStatus::Done), fn () => $service->deleteTask($this->visitante(), $doConsultor), fn () => $service->saveTask($this->visitante(), $doConsultor, ['title' => 'x', 'owner' => 'client'])] as $acao) {
            try {
                $acao();
                self::fail('o cliente não mexe na tarefa do consultor');
            } catch (AuthorizationException) {
            }
        }

        $this->comoConsultor();
        $service->deleteTask($this->visitante(), $doCliente);
        self::assertSame(1, EngagementTask::query()->count());
    }

    public function test_tarefa_nao_aceita_reuniao_de_outra_relacao(): void
    {
        $outro = User::factory()->consultant()->create();
        $this->vincular($outro);
        $deOutro = $this->reuniao([], $outro);

        $this->comoConsultor();

        $this->expectException(AuthorizationException::class);
        app(EngagementService::class)->saveTask($this->visitante(), null, ['title' => 'X', 'owner' => 'client', 'meeting_id' => $deOutro->id]);
    }

    public function test_componente_de_tarefas_cria_e_filtra(): void
    {
        $this->comoConsultor();

        Livewire::test(EngagementTasks::class)
            ->call('openForm')->set('title', 'Revisar orçamento')->set('owner', 'client')->set('dueDate', '2026-10-25')->call('save')->assertHasNoErrors()
            ->call('openForm')->set('title', 'Preparar cotação')->set('owner', 'consultant')->call('save')
            ->set('filtro', 'consultor')->assertSee('Preparar cotação')->assertDontSee('Revisar orçamento');

        self::assertSame(2, EngagementTask::query()->count());
    }

    // ------------------------------------------------------------------ demandas

    public function test_demanda_fluxo_completo(): void
    {
        Notification::fake();
        $service = app(EngagementService::class);

        $this->comoConsultor();
        try {
            $service->openRequest($this->visitante(), 'x', 'y');
            self::fail('só o cliente abre demanda');
        } catch (AuthorizationException) {
        }

        $this->comoCliente();
        $demanda = $service->openRequest($this->visitante(), 'IR dos FIIs', 'Como declaro os rendimentos?');
        Notification::assertSentTo($this->consultor, EngagementNotice::class, fn (EngagementNotice $n) => $n->kind === 'request_opened');
        self::assertSame(EngagementRequestStatus::Open, $demanda->status);

        $this->comoConsultor();
        $service->commentRequest($this->visitante(), $demanda->fresh(), 'Vão em rendimentos isentos.');
        self::assertSame(EngagementRequestStatus::Answered, $demanda->fresh()->status);
        Notification::assertSentTo($this->cliente, EngagementNotice::class, fn (EngagementNotice $n) => $n->kind === 'request_comment');

        $this->comoCliente();
        $service->commentRequest($this->visitante(), $demanda->fresh(), 'E o imposto retido?');
        self::assertSame(EngagementRequestStatus::Open, $demanda->fresh()->status, 'o cliente voltando a escrever reabre');

        $this->comoConsultor();
        $tarefa = $service->convertRequestToTask($this->visitante(), $demanda->fresh(), 'consultant');
        self::assertSame('IR dos FIIs', $tarefa->title);
        self::assertSame($tarefa->id, $demanda->fresh()->task_id);

        $service->resolveRequest($this->visitante(), $demanda->fresh());
        self::assertSame(EngagementRequestStatus::Resolved, $demanda->fresh()->status);
        self::assertNotNull($demanda->fresh()->resolved_at);
        self::assertSame(2, $demanda->fresh()->comments()->count());
    }

    public function test_componente_de_demandas_cliente_abre_e_consultor_responde(): void
    {
        $this->comoCliente();
        Livewire::test(EngagementRequests::class)
            ->call('openForm')->set('title', 'Posso antecipar o financiamento?')->set('body', 'Tenho um dinheiro parado.')->call('save')->assertHasNoErrors();

        $demanda = EngagementRequest::query()->sole();

        $this->comoConsultor();
        Livewire::test(EngagementRequests::class)
            ->call('toggle', $demanda->id)->assertSee('Tenho um dinheiro parado.')
            ->set('reply', 'Vale analisar a taxa.')->call('sendReply')->assertHasNoErrors()
            ->call('convertToTask', $demanda->id);

        self::assertSame(EngagementRequestStatus::Answered, $demanda->fresh()->status);
        self::assertSame(1, EngagementTask::query()->count());
    }

    // ------------------------------------------------------------------ lembretes

    public function test_lembretes_sao_enviados_uma_unica_vez(): void
    {
        Notification::fake();
        $this->reuniao(['scheduled_at' => CarbonImmutable::parse('2026-10-21 19:00')]);
        $this->tarefa(['title' => 'Vence amanhã', 'due_date' => '2026-10-21', 'owner' => EngagementTaskOwner::Client]);
        $this->tarefa(['title' => 'Venceu ontem', 'due_date' => '2026-10-19', 'owner' => EngagementTaskOwner::Consultant]);
        $this->tarefa(['title' => 'Já concluída', 'due_date' => '2026-10-21', 'status' => EngagementTaskStatus::Done]);

        $servico = app(EngagementReminderService::class);
        $primeira = $servico->run(CarbonImmutable::parse('2026-10-20'));
        $segunda = $servico->run(CarbonImmutable::parse('2026-10-20'));

        // Reunião: cliente + consultor (2); tarefa do cliente amanhã: cliente (1); tarefa do consultor atrasada: consultor (1).
        self::assertSame(4, $primeira);
        self::assertSame(0, $segunda);
        Notification::assertSentToTimes($this->cliente, EngagementNotice::class, 2);
        Notification::assertSentToTimes($this->consultor, EngagementNotice::class, 2);
        Notification::assertSentTo($this->consultor, EngagementNotice::class, fn (EngagementNotice $n) => $n->kind === 'task_overdue' && str_contains($n->message, '19/10'));
    }

    public function test_sem_vinculo_ativo_nao_ha_lembrete(): void
    {
        Notification::fake();
        $this->reuniao(['scheduled_at' => CarbonImmutable::parse('2026-10-21 19:00')]);
        $this->vinculo->update(['status' => ConsultantClientStatus::Inactive]);

        self::assertSame(0, app(EngagementReminderService::class)->run(CarbonImmutable::parse('2026-10-20')));
        Notification::assertNothingSent();
    }

    // ------------------------------------------------------------------ plano em PDF e fim do vínculo

    public function test_plano_em_pdf_nao_leva_nota_privada(): void
    {
        $this->comoConsultor();
        app(EngagementService::class)->addNote($this->visitante(), 'Segredo do consultor', true);
        app(EngagementService::class)->addNote($this->visitante(), 'Nota combinada', false);
        $this->tarefa(['title' => 'Montar plano']);

        $dados = app(EngagementPlanReport::class)->data($this->perfil->id, $this->consultor->id);

        self::assertSame(['Nota combinada'], $dados['notes']->pluck('body')->all());
        self::assertStringStartsWith('%PDF', app(EngagementPlanReport::class)->pdf($this->perfil->id, $this->consultor->id));
        self::assertStringNotContainsString('Segredo do consultor', view('pdf.engagement-plan', $dados)->render());
    }

    public function test_so_o_consultor_baixa_o_plano(): void
    {
        $this->comoCliente();
        Livewire::test(EngagementHome::class)->call('baixarPlano')->assertForbidden();

        $this->comoConsultor();
        Livewire::test(EngagementHome::class)->call('baixarPlano')->assertFileDownloaded('plano-de-acompanhamento-familia-ferry.pdf');
    }

    public function test_encerrar_vinculo_com_plano_pergunta_e_envia_ao_email_do_cliente(): void
    {
        Mail::fake();
        $this->tarefa();
        $this->actingAs($this->consultor);

        Livewire::test(MyAccount::class)
            ->call('removerCliente', $this->vinculo->id)
            ->assertSet('encerrandoVinculoId', $this->vinculo->id)
            ->assertSee('Enviar o plano e encerrar');

        self::assertSame(ConsultantClientStatus::Active, $this->vinculo->fresh()->status, 'só encerra depois da resposta');

        Livewire::test(MyAccount::class)->set('encerrandoVinculoId', $this->vinculo->id)->call('encerrarVinculo', true)->assertSet('encerrandoVinculoId', null);

        $vinculo = $this->vinculo->fresh();
        self::assertSame(ConsultantClientStatus::Inactive, $vinculo->status);
        self::assertSame('sent', $vinculo->plan_delivery);
        self::assertNotNull($vinculo->ended_at);
        Mail::assertQueued(EngagementPlanMail::class, fn (EngagementPlanMail $m) => $m->hasTo($this->cliente->email) && $m->profileId === $this->perfil->id);
        self::assertSame(1, EngagementTask::withoutGlobalScopes()->count(), 'o histórico fica guardado');
    }

    public function test_encerrar_vinculo_sem_enviar_guarda_o_plano_e_nao_manda_email(): void
    {
        Mail::fake();
        $this->tarefa();
        $this->actingAs($this->consultor);

        Livewire::test(MyAccount::class)->call('removerCliente', $this->vinculo->id)->call('encerrarVinculo', false);

        self::assertSame('declined', $this->vinculo->fresh()->plan_delivery);
        Mail::assertNothingQueued();
        self::assertSame(1, EngagementTask::withoutGlobalScopes()->count());
    }

    public function test_encerrar_vinculo_sem_nenhum_plano_nao_pergunta(): void
    {
        Mail::fake();
        $this->actingAs($this->consultor);

        Livewire::test(MyAccount::class)->call('removerCliente', $this->vinculo->id)->assertSet('encerrandoVinculoId', null);

        self::assertSame(ConsultantClientStatus::Inactive, $this->vinculo->fresh()->status);
        self::assertSame('empty', $this->vinculo->fresh()->plan_delivery);
        Mail::assertNothingQueued();
    }

    public function test_o_email_do_plano_leva_o_pdf_em_anexo(): void
    {
        $this->tarefa(['title' => 'Montar plano']);

        $mail = new EngagementPlanMail($this->perfil->id, $this->consultor->id, $this->consultor->name, $this->perfil->profile_name);

        self::assertStringContainsString('Marcelo Consultor', $mail->envelope()->subject);
        self::assertCount(1, $mail->attachments());
        self::assertStringContainsString('encerrado', $mail->render());
    }

    // ------------------------------------------------------------------ minha semana

    public function test_minha_semana_resume_os_clientes_ativos(): void
    {
        $this->reuniao(['scheduled_at' => CarbonImmutable::parse('2026-10-22 19:00')]);
        $this->reuniao(['scheduled_at' => CarbonImmutable::parse('2026-10-15 19:00')]);
        $this->tarefa(['title' => 'Atrasada', 'due_date' => '2026-10-10']);
        EngagementRequest::withoutGlobalScopes()->create(['profile_id' => $this->perfil->id, 'consultant_id' => $this->consultor->id, 'title' => 'Dúvida', 'body' => 'x', 'status' => EngagementRequestStatus::Open, 'created_by_user_id' => $this->cliente->id]);

        // Um cliente que já saiu não conta.
        $saiu = User::factory()->create();
        $perfilSaiu = FinancialProfile::factory()->create(['owner_user_id' => $saiu->id]);
        ConsultantClient::factory()->create(['consultant_id' => $this->consultor->id, 'client_id' => $saiu->id, 'status' => ConsultantClientStatus::Inactive]);
        EngagementTask::withoutGlobalScopes()->create(['profile_id' => $perfilSaiu->id, 'consultant_id' => $this->consultor->id, 'title' => 'Fantasma', 'owner' => EngagementTaskOwner::Client, 'status' => EngagementTaskStatus::Todo, 'due_date' => '2026-10-01']);

        $semana = app(EngagementWeekService::class)->forConsultant($this->consultor, CarbonImmutable::parse('2026-10-20'));

        self::assertSame(['meetings' => 1, 'overdue' => 1, 'requests' => 1, 'without_meeting' => 0], $semana['counts']);
        self::assertCount(1, $semana['awaiting_summary']);
        self::assertSame('Família Ferry', $semana['meetings'][0]['client']);
    }

    public function test_pagina_minha_semana_e_so_do_consultor(): void
    {
        $this->actingAs($this->cliente);
        Livewire::test(EngagementWeek::class)->assertForbidden();

        $this->actingAs($this->consultor);
        Livewire::test(EngagementWeek::class)->assertOk()->assertSee('Minha semana');
    }

    public function test_clique_no_aviso_abre_o_perfil_do_cliente(): void
    {
        $this->consultor->notify(new EngagementNotice($this->perfil->id, 'request_opened', 'Nova demanda de cliente', 'IR dos FIIs'));

        $notificacao = $this->consultor->notifications()->sole();

        self::assertSame($this->perfil->id, \App\Support\NotificationPresenter::profileId($notificacao));
        self::assertSame(route('engagement.index'), \App\Support\NotificationPresenter::present($notificacao)['url']);
    }
}
