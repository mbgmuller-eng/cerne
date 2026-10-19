<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\EngagementMeetingStatus;
use App\Enums\EngagementTaskOwner;
use App\Enums\EngagementTaskStatus;
use App\Enums\MemberRole;
use App\Enums\UserRole;
use App\Livewire\Consultant\ConsultantCalendar;
use App\Models\ConsultantClient;
use App\Models\EngagementMeeting;
use App\Models\EngagementTask;
use App\Models\FinancialProfile;
use App\Models\Lead;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A agenda do profissional: reuniões com clientes, prazos de tarefas e retornos de leads, só do que é dele.
 */
class ConsultantCalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $consultor;

    private User $cliente;

    private FinancialProfile $perfil;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 09:00:00'));

        $this->consultor = User::factory()->consultant()->create(['name' => 'Marcelo Consultor']);
        [$this->cliente, $this->perfil] = $this->novoCliente('Helen Ferry', 'Família Ferry', true);

        $this->actingAs($this->consultor);
        app(ProfileContext::class)->set($this->perfil, null, true);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: FinancialProfile} */
    private function novoCliente(string $nome, string $perfilNome, bool $vinculado): array
    {
        $cliente = User::factory()->create(['name' => $nome]);
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $cliente->id, 'profile_name' => $perfilNome]);
        ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $cliente->id, 'role' => MemberRole::Primary, 'name' => $nome]);

        if ($vinculado) {
            ConsultantClient::factory()->create(['consultant_id' => $this->consultor->id, 'client_id' => $cliente->id, 'status' => ConsultantClientStatus::Active, 'accepted_at' => now()]);
        }

        return [$cliente, $perfil];
    }

    private function reuniao(array $campos = [], ?FinancialProfile $perfil = null, ?User $consultor = null): EngagementMeeting
    {
        return EngagementMeeting::withoutGlobalScopes()->create(array_merge([
            'profile_id' => ($perfil ?? $this->perfil)->id, 'consultant_id' => ($consultor ?? $this->consultor)->id,
            'scheduled_at' => CarbonImmutable::parse('2026-10-22 10:00:00'), 'duration_minutes' => 60, 'status' => EngagementMeetingStatus::Scheduled,
        ], $campos));
    }

    private function tarefa(array $campos = []): EngagementTask
    {
        return EngagementTask::withoutGlobalScopes()->create(array_merge([
            'profile_id' => $this->perfil->id, 'consultant_id' => $this->consultor->id, 'title' => 'Enviar extratos', 'owner' => EngagementTaskOwner::Client,
            'status' => EngagementTaskStatus::Todo, 'due_date' => '2026-10-23', 'created_by_user_id' => $this->consultor->id,
        ], $campos));
    }

    private function lead(array $campos = [], ?User $de = null): Lead
    {
        return Lead::factory()->create(array_merge(['consultant_id' => ($de ?? $this->consultor)->id, 'name' => 'Maria Lead', 'next_action_at' => CarbonImmutable::parse('2026-10-21 15:00:00')], $campos));
    }

    private function eventos(array $consulta = []): array
    {
        $teste = Livewire::test(ConsultantCalendar::class);

        return collect($teste->viewData('calendario')['by_key'])->all();
    }

    // ------------------------------------------------------------------ o que aparece

    public function test_mostra_reuniao_tarefa_e_retorno_de_lead_com_horario_e_nome(): void
    {
        $this->reuniao(['location' => 'Escritório', 'agenda' => "Revisar orçamento\nMetas do ano"]);
        $this->tarefa();
        $this->lead();

        Livewire::test(ConsultantCalendar::class)
            ->assertSee('10:00')->assertSee('Helen Ferry')->assertSee('Maria Lead')->assertSee('Enviar extratos');

        $chaves = array_keys($this->eventos());
        self::assertCount(3, $chaves);
        self::assertSame(['lead', 'reuniao', 'tarefa'], collect($chaves)->map(fn ($k) => explode(':', $k)[0])->sort()->values()->all());
    }

    public function test_reuniao_dura_o_que_foi_marcado_e_leva_a_cor_do_tipo(): void
    {
        $reuniao = $this->reuniao(['duration_minutes' => 90]);

        $e = $this->eventos()['reuniao:'.$reuniao->id];

        self::assertSame('10:00 – 11:30', $e->timeLabel());
        self::assertSame('#0F766E', $e->color);
        self::assertFalse($e->muted);
        self::assertSame('Reunião', $e->tag);
    }

    public function test_reuniao_realizada_aparece_esmaecida_e_a_cancelada_nao_aparece(): void
    {
        $feita = $this->reuniao(['status' => EngagementMeetingStatus::Done, 'summary' => 'Fechamos o plano.']);
        $cancelada = $this->reuniao(['status' => EngagementMeetingStatus::Cancelled, 'scheduled_at' => CarbonImmutable::parse('2026-10-23 10:00:00')]);

        $eventos = $this->eventos();

        self::assertTrue($eventos['reuniao:'.$feita->id]->muted);
        self::assertSame('Reunião realizada', $eventos['reuniao:'.$feita->id]->tag);
        self::assertArrayNotHasKey('reuniao:'.$cancelada->id, $eventos);
    }

    public function test_tarefa_e_dia_inteiro_vazada_e_a_concluida_nao_aparece(): void
    {
        $aberta = $this->tarefa();
        $feita = $this->tarefa(['title' => 'Concluída', 'status' => EngagementTaskStatus::Done]);

        $eventos = $this->eventos();

        self::assertTrue($eventos['tarefa:'.$aberta->id]->allDay);
        self::assertTrue($eventos['tarefa:'.$aberta->id]->hollow);
        self::assertArrayNotHasKey('tarefa:'.$feita->id, $eventos);
    }

    public function test_retorno_de_lead_so_com_data_vira_dia_inteiro(): void
    {
        $comHora = $this->lead();
        $semHora = $this->lead(['name' => 'Sem hora', 'next_action_at' => CarbonImmutable::parse('2026-10-22 00:00:00')]);

        $eventos = $this->eventos();

        self::assertFalse($eventos['lead:'.$comHora->id]->allDay);
        self::assertTrue($eventos['lead:'.$semHora->id]->allDay);
    }

    public function test_lead_convertido_ou_perdido_nao_aparece(): void
    {
        $convertido = $this->lead(['name' => 'Convertido', 'stage' => 'converted']);
        $perdido = $this->lead(['name' => 'Perdido', 'stage' => 'lost']);

        $eventos = $this->eventos();

        self::assertArrayNotHasKey('lead:'.$convertido->id, $eventos);
        self::assertArrayNotHasKey('lead:'.$perdido->id, $eventos);
    }

    // ------------------------------------------------------------------ de quem é

    public function test_so_o_que_e_do_proprio_consultor_e_dos_clientes_vinculados(): void
    {
        $outroConsultor = User::factory()->consultant()->create();
        [, $perfilSemVinculo] = $this->novoCliente('Sem Vínculo', 'Perfil alheio', false);
        [, $perfilDoOutro] = $this->novoCliente('Do Outro', 'Perfil do outro', false);
        ConsultantClient::factory()->create(['consultant_id' => $outroConsultor->id, 'client_id' => $perfilDoOutro->owner_user_id, 'status' => ConsultantClientStatus::Active, 'accepted_at' => now()]);

        $minha = $this->reuniao();
        $semVinculo = $this->reuniao([], $perfilSemVinculo);
        $doOutro = $this->reuniao([], $perfilDoOutro, $outroConsultor);
        $leadAlheio = $this->lead(['name' => 'Lead de outro'], $outroConsultor);

        $eventos = $this->eventos();

        self::assertArrayHasKey('reuniao:'.$minha->id, $eventos);
        self::assertArrayNotHasKey('reuniao:'.$semVinculo->id, $eventos, 'cliente sem vínculo com este consultor');
        self::assertArrayNotHasKey('reuniao:'.$doOutro->id, $eventos);
        self::assertArrayNotHasKey('lead:'.$leadAlheio->id, $eventos);
        Livewire::test(ConsultantCalendar::class)->assertDontSee('Lead de outro');
    }

    public function test_vinculo_encerrado_tira_a_reuniao_da_agenda(): void
    {
        $this->reuniao();
        ConsultantClient::query()->update(['status' => ConsultantClientStatus::Inactive]);

        self::assertSame([], $this->eventos());
    }

    // ------------------------------------------------------------------ filtros e navegação

    public function test_desliga_um_tipo_e_sempre_sobra_pelo_menos_um(): void
    {
        $this->reuniao();
        $this->tarefa();
        $this->lead();

        $teste = Livewire::test(ConsultantCalendar::class)
            ->call('toggleType', 'tasks')->call('toggleType', 'leads');

        self::assertSame(['meetings'], $teste->get('types'));
        self::assertCount(1, $teste->viewData('calendario')['by_key']);

        $teste->call('toggleType', 'meetings');
        self::assertSame(['meetings'], $teste->get('types'), 'o último não desliga');

        $teste->call('toggleType', 'tasks');
        self::assertEqualsCanonicalizing(['meetings', 'tasks'], $teste->get('types'));
    }

    public function test_tipo_desconhecido_na_url_ou_no_clique_e_ignorado(): void
    {
        Livewire::withQueryParams(['ver' => ['lixo', 'tasks']])->test(ConsultantCalendar::class)->assertSet('types', ['tasks']);
        Livewire::withQueryParams(['ver' => ['lixo']])->test(ConsultantCalendar::class)->assertSet('types', ['meetings', 'tasks', 'leads']);
        Livewire::test(ConsultantCalendar::class)->call('toggleType', 'lixo')->assertSet('types', ['meetings', 'tasks', 'leads']);
    }

    public function test_semana_mostra_a_reuniao_na_hora_certa(): void
    {
        $this->reuniao(['scheduled_at' => CarbonImmutable::parse('2026-10-22 14:30:00')]);

        $dados = Livewire::test(ConsultantCalendar::class)->call('setCalView', 'week')->viewData('calendario');
        $quinta = collect($dados['days'])->firstWhere('date', '2026-10-22');

        self::assertSame(22.5, $quinta['timed'][0]['top']);
    }

    public function test_a_visao_escolhida_fica_na_conta_do_consultor(): void
    {
        Livewire::test(ConsultantCalendar::class)->call('setCalView', 'day');

        self::assertSame(\App\Enums\CalendarView::Day, $this->consultor->fresh()->calendar_view);
        Livewire::test(ConsultantCalendar::class)->assertSet('calView', 'day');
    }

    // ------------------------------------------------------------------ detalhe

    public function test_detalhe_da_reuniao_mostra_pauta_e_leva_ao_acompanhamento(): void
    {
        $reuniao = $this->reuniao(['location' => 'Escritório', 'agenda' => "Revisar orçamento\nMetas do ano", 'prep_notes' => 'Trazer extratos']);

        Livewire::test(ConsultantCalendar::class)->call('openCalendarEvent', 'reuniao:'.$reuniao->id)
            ->assertSet('eventVisible', true)
            ->assertSee('Revisar orçamento')->assertSee('Metas do ano')->assertSee('Trazer extratos')->assertSee('Escritório')
            ->assertSee('Abrir o acompanhamento')->assertSee('/clientes/'.$this->perfil->id.'/abrir', false);
    }

    public function test_detalhe_do_lead_leva_ao_quadro_de_leads(): void
    {
        $lead = $this->lead(['phone' => '41 99999-0000']);

        Livewire::test(ConsultantCalendar::class)->call('openCalendarEvent', 'lead:'.$lead->id)
            ->assertSee('41 99999-0000')->assertSee('Abrir os leads');
    }

    public function test_fechar_o_detalhe(): void
    {
        $reuniao = $this->reuniao();

        Livewire::test(ConsultantCalendar::class)->call('openCalendarEvent', 'reuniao:'.$reuniao->id)->call('closeEvent')
            ->assertSet('eventVisible', false)->assertSet('openEvent', null);
    }

    // ------------------------------------------------------------------ quem entra

    public function test_corretor_so_ve_retornos_de_leads(): void
    {
        $corretor = User::factory()->create(['role' => UserRole::Broker]);
        $this->actingAs($corretor);
        $meu = $this->lead(['name' => 'Lead do corretor'], $corretor);
        $this->reuniao();

        $teste = Livewire::test(ConsultantCalendar::class);

        self::assertSame(['leads'], $teste->get('types'));
        self::assertSame(['lead:'.$meu->id], array_keys($teste->viewData('calendario')['by_key']));
    }

    public function test_cliente_comum_nao_entra(): void
    {
        $this->actingAs($this->cliente);

        $this->get(route('consultant.calendar'))->assertForbidden();
    }

    public function test_a_rota_abre_para_o_consultor(): void
    {
        $this->get(route('consultant.calendar'))->assertOk()->assertSee('Agenda');
    }
}
