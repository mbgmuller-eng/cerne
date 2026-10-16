<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\EngagementMeetingStatus;
use App\Enums\EngagementTaskOwner;
use App\Enums\EngagementTaskStatus;
use App\Enums\MemberRole;
use App\Http\Middleware\SetProfileContext;
use App\Livewire\Engagement\EngagementHome;
use App\Livewire\Engagement\EngagementMeetings;
use App\Livewire\Engagement\EngagementTasks;
use App\Mail\EngagementPlanMail;
use App\Models\ConsultantClient;
use App\Models\Document;
use App\Models\EngagementAttachment;
use App\Models\EngagementMeeting;
use App\Models\EngagementTask;
use App\Models\FinancialProfile;
use App\Models\InsurancePolicy;
use App\Models\ProfileMember;
use App\Models\User;
use App\Notifications\EngagementNotice;
use App\Services\EngagementAccess;
use App\Services\EngagementService;
use App\Support\Engagement\EngagementDocuments;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Acompanhamento, fase 3: reuniões recorrentes, agenda (.ics), anexos da área de Documentos e o plano enviado ao cliente.
 */
class EngagementPhase3Test extends TestCase
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

        $this->cliente = User::factory()->create(['name' => 'Helen Cliente', 'email' => 'helen@example.com']);
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->cliente->id, 'profile_name' => 'Família Ferry']);
        $this->membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->cliente->id, 'role' => MemberRole::Primary, 'name' => 'Helen Cliente']);
        $this->consultor = User::factory()->consultant()->create(['name' => 'Marcelo Consultor']);
        $this->vinculo = ConsultantClient::factory()->create(['consultant_id' => $this->consultor->id, 'client_id' => $this->cliente->id, 'status' => ConsultantClientStatus::Active, 'accepted_at' => now()]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function comoCliente(): void
    {
        $this->actingAs($this->cliente);
        app(ProfileContext::class)->set($this->perfil, $this->membro);
    }

    private function comoConsultor(?User $consultor = null): void
    {
        $this->actingAs($consultor ?? $this->consultor);
        app(ProfileContext::class)->set($this->perfil, null, true);
    }

    private function visitante()
    {
        return app(EngagementAccess::class)->viewer();
    }

    private function servico(): EngagementService
    {
        return app(EngagementService::class);
    }

    private function reuniao(array $campos = [], ?User $consultor = null): EngagementMeeting
    {
        return EngagementMeeting::withoutGlobalScopes()->create(array_merge([
            'profile_id' => $this->perfil->id, 'consultant_id' => ($consultor ?? $this->consultor)->id,
            'scheduled_at' => now()->addDays(2)->setTime(19, 0), 'status' => EngagementMeetingStatus::Scheduled,
        ], $campos));
    }

    private function tarefa(array $campos = []): EngagementTask
    {
        return EngagementTask::withoutGlobalScopes()->create(array_merge([
            'profile_id' => $this->perfil->id, 'consultant_id' => $this->consultor->id, 'title' => 'Enviar a apólice',
            'owner' => EngagementTaskOwner::Client, 'status' => EngagementTaskStatus::Todo, 'created_by_user_id' => $this->consultor->id,
        ], $campos));
    }

    private function documento(string $categoria, string $titulo, array $campos = []): Document
    {
        return Document::withoutGlobalScopes()->create(array_merge([
            'profile_id' => $this->perfil->id, 'member_id' => $this->membro->id, 'category' => $categoria, 'title' => $titulo,
            'original_filename' => 'x.pdf', 'storage_path' => 'x', 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'created_by_member_id' => $this->membro->id,
        ], $campos));
    }

    // ------------------------------------------------------------------ reuniões recorrentes

    public function test_serie_semanal_cria_reunioes_iguais_com_o_mesmo_series_id_e_avisa_uma_vez(): void
    {
        Notification::fake();
        $this->comoConsultor();

        $reunioes = $this->servico()->scheduleSeries($this->visitante(), ['scheduled_at' => CarbonImmutable::parse('2026-10-22 19:00'), 'agenda' => 'Revisão', 'location' => 'meet.example.com'], 'weekly', 4);

        self::assertSame(['2026-10-22', '2026-10-29', '2026-11-05', '2026-11-12'], $reunioes->map(fn ($m) => $m->scheduled_at->format('Y-m-d'))->all());
        self::assertCount(1, $reunioes->pluck('series_id')->unique());
        self::assertSame(4, EngagementMeeting::query()->where('agenda', 'Revisão')->where('location', 'meet.example.com')->count());
        Notification::assertSentToTimes($this->cliente, EngagementNotice::class, 1);
        Notification::assertSentTo($this->cliente, EngagementNotice::class, fn (EngagementNotice $n) => $n->kind === 'meeting_series' && str_contains($n->message, '4 reuniões'));
    }

    public function test_serie_quinzenal_e_mensal(): void
    {
        $this->comoConsultor();

        $quinzenal = $this->servico()->scheduleSeries($this->visitante(), ['scheduled_at' => CarbonImmutable::parse('2026-10-22 19:00')], 'biweekly', 3);
        self::assertSame(['2026-10-22', '2026-11-05', '2026-11-19'], $quinzenal->map(fn ($m) => $m->scheduled_at->format('Y-m-d'))->all());

        // 31/01 + 1 mês cai em 28/02, e não em 03/03.
        $mensal = $this->servico()->scheduleSeries($this->visitante(), ['scheduled_at' => CarbonImmutable::parse('2027-01-31 10:00')], 'monthly', 3);
        self::assertSame(['2027-01-31', '2027-02-28', '2027-03-31'], $mensal->map(fn ($m) => $m->scheduled_at->format('Y-m-d'))->all());
    }

    public function test_serie_invalida_ou_do_cliente_e_recusada(): void
    {
        $this->comoConsultor();

        foreach ([['weekly', 1], ['weekly', 13], ['diaria', 4]] as [$frequencia, $vezes]) {
            try {
                $this->servico()->scheduleSeries($this->visitante(), ['scheduled_at' => now()->addDay()], $frequencia, $vezes);
                self::fail('deveria recusar');
            } catch (InvalidArgumentException) {
            }
        }

        $this->comoCliente();
        $this->expectException(AuthorizationException::class);
        $this->servico()->scheduleSeries($this->visitante(), ['scheduled_at' => now()->addDay()], 'weekly', 4);
    }

    public function test_cancelar_esta_e_as_proximas_so_atinge_a_serie_dali_para_a_frente(): void
    {
        $this->comoConsultor();
        $serie = $this->servico()->scheduleSeries($this->visitante(), ['scheduled_at' => CarbonImmutable::parse('2026-10-22 19:00')], 'weekly', 4)->values();
        $avulsa = $this->reuniao(['scheduled_at' => CarbonImmutable::parse('2026-11-12 10:00')]);

        $this->servico()->cancelMeeting($this->visitante(), $serie[1], true);

        $estados = EngagementMeeting::query()->where('series_id', $serie[0]->series_id)->orderBy('scheduled_at')->pluck('status')->map->value->all();
        self::assertSame(['scheduled', 'cancelled', 'cancelled', 'cancelled'], $estados);
        self::assertSame(EngagementMeetingStatus::Scheduled, $avulsa->fresh()->status, 'reunião de fora da série não é tocada');

        // Cancelar sem "e as próximas" afeta uma só.
        $outra = $this->servico()->scheduleSeries($this->visitante(), ['scheduled_at' => CarbonImmutable::parse('2026-12-01 19:00')], 'weekly', 3)->values();
        $this->servico()->cancelMeeting($this->visitante(), $outra[0]);
        self::assertSame(['cancelled', 'scheduled', 'scheduled'], EngagementMeeting::query()->where('series_id', $outra[0]->series_id)->orderBy('scheduled_at')->pluck('status')->map->value->all());
    }

    public function test_componente_marca_serie_e_remarcar_nao_repete(): void
    {
        $this->comoConsultor();

        Livewire::test(EngagementMeetings::class)
            ->call('openForm')->set('date', '2026-10-27')->set('time', '18:00')->set('repeat', 'weekly')->set('occurrences', 3)->call('save')->assertHasNoErrors();

        $reunioes = EngagementMeeting::query()->orderBy('scheduled_at')->get();
        self::assertCount(3, $reunioes);

        Livewire::test(EngagementMeetings::class)
            ->call('openForm', $reunioes[0]->id)->set('time', '20:00')->set('repeat', 'weekly')->call('save')->assertHasNoErrors();

        self::assertSame(3, EngagementMeeting::query()->count(), 'remarcar não cria reuniões novas');
        self::assertSame('20:00', $reunioes[0]->fresh()->scheduled_at->format('H:i'));
        self::assertSame('18:00', $reunioes[1]->fresh()->scheduled_at->format('H:i'));

        Livewire::test(EngagementMeetings::class)->call('openForm')->set('date', '2026-11-03')->set('repeat', 'weekly')->set('occurrences', 30)->call('save')->assertHasErrors(['occurrences']);
    }

    public function test_botao_de_cancelar_as_proximas_so_aparece_em_serie(): void
    {
        $this->comoConsultor();
        $this->reuniao();

        Livewire::test(EngagementMeetings::class)->assertDontSee('Cancelar esta e as próximas');

        EngagementMeeting::query()->delete();
        $this->servico()->scheduleSeries($this->visitante(), ['scheduled_at' => CarbonImmutable::parse('2026-10-22 19:00')], 'weekly', 3);

        self::assertSame(3, EngagementMeeting::query()->whereNotNull('series_id')->count());
        Livewire::test(EngagementMeetings::class)->assertSee('Cancelar esta e as próximas');
        $this->comoCliente();
        Livewire::test(EngagementMeetings::class)->assertDontSee('Cancelar esta e as próximas')->assertSee('Adicionar à agenda');
    }

    // ------------------------------------------------------------------ agenda (.ics)

    public function test_ics_da_reuniao_para_cada_lado(): void
    {
        $reuniao = $this->reuniao(['scheduled_at' => CarbonImmutable::parse('2026-10-22 19:00'), 'duration_minutes' => 60, 'location' => 'https://meet.example.com/abc', 'agenda' => "Reserva\nSeguros", 'prep_notes' => 'Extrato']);

        $this->actingAs($this->cliente);
        $resposta = $this->get(route('engagement.meeting.ics', $reuniao->id))->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
        $ics = $resposta->getContent();

        self::assertStringContainsString('SUMMARY:Reunião de acompanhamento financeiro', $ics);
        self::assertStringContainsString('DTSTART:20261022T220000Z', $ics);
        self::assertStringContainsString('DTEND:20261022T230000Z', $ics);
        self::assertStringContainsString('LOCATION:https://meet.example.com/abc', $ics);
        self::assertStringContainsString('Pauta: Reserva\; Seguros', $ics);
        self::assertStringContainsString('O que levar: Extrato', $ics);

        $this->actingAs($this->consultor)->withSession([SetProfileContext::SESSION_KEY => $this->perfil->id]);
        $this->get(route('engagement.meeting.ics', $reuniao->id))->assertOk()->assertSee('Reunião de acompanhamento: Família Ferry', false);
    }

    public function test_ics_de_reuniao_de_outra_relacao_ou_sem_vinculo_da_404(): void
    {
        $outro = User::factory()->consultant()->create();
        ConsultantClient::factory()->create(['consultant_id' => $outro->id, 'client_id' => $this->cliente->id, 'status' => ConsultantClientStatus::Active]);
        $deOutro = $this->reuniao([], $outro);

        // O consultor só abre as reuniões da relação dele.
        $this->actingAs($this->consultor)->withSession([SetProfileContext::SESSION_KEY => $this->perfil->id]);
        $this->get(route('engagement.meeting.ics', $deOutro->id))->assertNotFound();

        $this->actingAs($this->cliente);

        $this->vinculo->update(['status' => ConsultantClientStatus::Inactive]);
        ConsultantClient::query()->where('consultant_id', $outro->id)->update(['status' => ConsultantClientStatus::Inactive]);
        app(EngagementAccess::class)->forget(); // atualização em massa não dispara o evento que limpa o cache do visitante
        $minha = $this->reuniao();
        $this->get(route('engagement.meeting.ics', $minha->id))->assertNotFound();
    }

    public function test_ics_da_tarefa_so_com_prazo(): void
    {
        $comPrazo = $this->tarefa(['title' => 'Enviar apólice', 'due_date' => '2026-10-30']);
        $semPrazo = $this->tarefa(['title' => 'Sem prazo']);

        $this->actingAs($this->cliente);
        $ics = $this->get(route('engagement.task.ics', $comPrazo->id))->assertOk()->getContent();

        self::assertStringContainsString('SUMMARY:Prazo: Enviar apólice', $ics);
        self::assertStringContainsString('DTSTART;VALUE=DATE:20261030', $ics);
        $this->get(route('engagement.task.ics', $semPrazo->id))->assertNotFound();
    }

    // ------------------------------------------------------------------ anexos

    public function test_so_documento_que_o_consultor_pode_ver_e_anexavel(): void
    {
        $apolice = InsurancePolicy::factory()->create(['profile_id' => $this->perfil->id, 'member_id' => $this->membro->id]);
        $daApolice = $this->documento('insurance_policy', 'Apólice de vida', ['insurance_policy_id' => $apolice->id]);
        $outrosVisivel = $this->documento('other', 'Extrato compartilhado', ['visible_to_professional' => true]);
        $this->documento('other', 'Contrato privado', ['visible_to_professional' => false]);
        $this->documento('driver_license', 'CNH');
        $this->documento('health_exam', 'Hemograma', ['visible_to_professional' => true]);

        $this->comoCliente();
        self::assertSame(5, Document::query()->count(), 'o cliente vê todos os dele');

        $podem = EngagementDocuments::shareable($this->perfil)->pluck('title')->sort()->values()->all();
        self::assertSame(['Apólice de vida', 'Extrato compartilhado'], $podem);
        self::assertSame($this->membro->id, app(ProfileContext::class)->memberId(), 'o contexto do cliente volta ao que era');
        self::assertContains($daApolice->id, EngagementDocuments::shareable($this->perfil)->pluck('id')->all());
        self::assertContains($outrosVisivel->id, EngagementDocuments::shareable($this->perfil)->pluck('id')->all());
    }

    public function test_cliente_anexa_na_tarefa_e_o_consultor_abre_o_mesmo_documento(): void
    {
        $doc = $this->documento('other', 'Extrato compartilhado', ['visible_to_professional' => true]);
        $tarefa = $this->tarefa();

        $this->comoCliente();
        $anexo = $this->servico()->attachDocument($this->visitante(), 'task', $tarefa->id, $doc->id);
        self::assertSame($this->cliente->id, $anexo->attached_by_user_id);

        // Anexar de novo é idempotente (índice único).
        $this->servico()->attachDocument($this->visitante(), 'task', $tarefa->id, $doc->id);
        self::assertSame(1, EngagementAttachment::query()->count());

        $this->comoConsultor();
        Livewire::test(EngagementTasks::class)->assertSee('Extrato compartilhado');
        self::assertSame($doc->id, EngagementAttachment::query()->sole()->document->id);
    }

    public function test_documento_pessoal_ou_fechado_nao_e_anexado_nem_pelo_dono(): void
    {
        $cnh = $this->documento('driver_license', 'CNH');
        $fechado = $this->documento('other', 'Contrato privado', ['visible_to_professional' => false]);
        $reuniao = $this->reuniao();

        $this->comoCliente();

        foreach ([$cnh, $fechado] as $doc) {
            try {
                $this->servico()->attachDocument($this->visitante(), 'meeting', $reuniao->id, $doc->id);
                self::fail('não pode anexar');
            } catch (AuthorizationException) {
            }
        }

        self::assertSame(0, EngagementAttachment::withoutGlobalScopes()->count());
    }

    public function test_anexo_some_se_o_documento_deixa_de_ser_visivel_ao_consultor(): void
    {
        $doc = $this->documento('other', 'Extrato compartilhado', ['visible_to_professional' => true]);
        $reuniao = $this->reuniao();

        $this->comoConsultor();
        $this->servico()->attachDocument($this->visitante(), 'meeting', $reuniao->id, $doc->id);
        Livewire::test(EngagementMeetings::class)->assertSee('Extrato compartilhado');

        $doc->update(['visible_to_professional' => false]);

        Livewire::test(EngagementMeetings::class)->assertDontSee('Extrato compartilhado');
        self::assertSame(1, EngagementAttachment::query()->count(), 'a referência continua; só não aparece');
    }

    public function test_anexo_nao_cruza_relacoes_nem_assuntos_inexistentes(): void
    {
        $outro = User::factory()->consultant()->create();
        ConsultantClient::factory()->create(['consultant_id' => $outro->id, 'client_id' => $this->cliente->id, 'status' => ConsultantClientStatus::Active]);
        $deOutro = $this->reuniao([], $outro);
        $doc = $this->documento('other', 'Extrato', ['visible_to_professional' => true]);

        $this->comoConsultor();

        foreach ([['meeting', $deOutro->id], ['meeting', 'nao-existe'], ['task', $deOutro->id], ['qualquer', $deOutro->id]] as [$tipo, $id]) {
            try {
                $this->servico()->attachDocument($this->visitante(), $tipo, $id, $doc->id);
                self::fail("não pode anexar em $tipo $id");
            } catch (AuthorizationException) {
            }
        }

        self::assertSame(0, EngagementAttachment::withoutGlobalScopes()->count());
    }

    public function test_quem_remove_o_anexo(): void
    {
        $doc = $this->documento('other', 'Extrato', ['visible_to_professional' => true]);
        $tarefa = $this->tarefa();

        $this->comoConsultor();
        $doConsultor = $this->servico()->attachDocument($this->visitante(), 'task', $tarefa->id, $doc->id);

        $this->comoCliente();
        try {
            $this->servico()->detachDocument($this->visitante(), $doConsultor);
            self::fail('o cliente não remove o anexo do consultor');
        } catch (AuthorizationException) {
        }

        $this->comoConsultor();
        $this->servico()->detachDocument($this->visitante(), $doConsultor);
        self::assertSame(0, EngagementAttachment::query()->count());
        self::assertSame(1, Document::withoutGlobalScopes()->count(), 'o documento continua na área de Documentos');
    }

    public function test_modal_explica_quando_nao_ha_documento_para_anexar(): void
    {
        $this->documento('driver_license', 'CNH');
        $this->tarefa();

        $this->comoCliente();
        Livewire::test(EngagementTasks::class)->call('openAttach', 'task', EngagementTask::query()->first()->id)
            ->assertSee('Nenhum documento disponível para anexar')->assertSee('Documentos pessoais e de saúde ficam sempre fechados');
        self::assertSame(0, EngagementDocuments::shareable($this->perfil)->count());
    }

    // ------------------------------------------------------------------ plano ao cliente

    public function test_consultor_envia_o_plano_ao_cliente_sem_encerrar_o_vinculo(): void
    {
        Mail::fake();
        $this->tarefa();

        $this->comoConsultor();
        $email = $this->servico()->sendPlanToClient($this->visitante());

        self::assertSame('helen@example.com', $email);
        Mail::assertQueued(EngagementPlanMail::class, fn (EngagementPlanMail $m) => $m->hasTo('helen@example.com') && $m->ended === false);
        self::assertSame(ConsultantClientStatus::Active, $this->vinculo->fresh()->status);

        $mail = new EngagementPlanMail($this->perfil->id, $this->consultor->id, 'Marcelo Consultor', 'Família Ferry', false);
        self::assertStringContainsString('atualizado por Marcelo Consultor', $mail->envelope()->subject);
        self::assertStringContainsString('enviou o resumo do plano', $mail->render());
        self::assertStringNotContainsString('foi encerrado', $mail->render());
    }

    public function test_cliente_nao_envia_nem_baixa_e_plano_vazio_nao_sai(): void
    {
        Mail::fake();

        $this->comoConsultor();
        try {
            $this->servico()->sendPlanToClient($this->visitante());
            self::fail('sem nada no plano');
        } catch (AuthorizationException) {
        }

        $this->tarefa();
        $this->comoCliente();
        try {
            $this->servico()->sendPlanToClient($this->visitante());
            self::fail('cliente não envia');
        } catch (AuthorizationException) {
        }

        Livewire::test(EngagementHome::class)->call('enviarPlano')->assertForbidden();
        Mail::assertNothingQueued();
        self::assertSame(1, EngagementTask::query()->count());
    }

    public function test_botao_enviar_plano_mostra_o_resultado(): void
    {
        Mail::fake();
        $this->comoConsultor();

        Livewire::test(EngagementHome::class)->assertSee('Enviar plano ao cliente')
            ->call('enviarPlano')->assertSee('Ainda não há nada no plano para enviar');

        $this->tarefa();
        Livewire::test(EngagementHome::class)->call('enviarPlano')->assertSee('Plano enviado para helen@example.com.');
        Mail::assertQueuedCount(1);
        self::assertSame(ConsultantClientStatus::Active, $this->vinculo->fresh()->status);
    }
}
