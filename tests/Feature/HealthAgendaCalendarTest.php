<?php

namespace Tests\Feature;

use App\Enums\AgendaLayout;
use App\Enums\CalendarView;
use App\Enums\ConsultantClientStatus;
use App\Enums\MemberRole;
use App\Livewire\Health\HealthAppointmentIndex;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\HealthAppointmentService;
use App\Services\HealthCareItemService;
use App\Support\MemberPalette;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Calendário da Agenda de Saúde: mês, semana e dia com os compromissos e itens de cuidado, a ordem
 * (calendário ou lista primeiro) guardada na conta, e a cor de cada pessoa do casal.
 */
class HealthAgendaCalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $usuarioTitular;

    private User $usuarioConjuge;

    private FinancialProfile $perfil;

    private ProfileMember $titular;

    private ProfileMember $conjuge;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-07 09:00:00'));

        $this->usuarioTitular = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $this->usuarioTitular->id]);
        // color_hex nulo: o app nunca gravou cor de membro; as padrão (azul/rosa) valem até alguém escolher.
        $this->titular = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuarioTitular->id, 'name' => 'Marcelo', 'color_hex' => null]);

        $this->usuarioConjuge = User::factory()->create();
        $this->conjuge = ProfileMember::factory()->secondary()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuarioConjuge->id, 'name' => 'Helen', 'color_hex' => null]);

        $this->entrarComo($this->titular);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function entrarComo(ProfileMember $membro): void
    {
        $this->actingAs($membro->id === $this->titular->id ? $this->usuarioTitular : $this->usuarioConjuge);
        app(ProfileContext::class)->set($this->perfil, $membro);
    }

    private function consulta(ProfileMember $de, string $quando, array $extra = [])
    {
        return app(HealthAppointmentService::class)->create($de, $extra + [
            'kind' => 'consultation', 'title' => 'Retorno', 'scheduled_at' => CarbonImmutable::parse($quando),
        ], $de);
    }

    private function cuidado(ProfileMember $de, string $ultimaVez = '2026-10-07', array $extra = [])
    {
        return app(HealthCareItemService::class)->create($de, $extra + [
            'category' => 'hearing_aid', 'name' => 'Filtro', 'interval_value' => 15, 'interval_unit' => 'day', 'last_done_on' => $ultimaVez,
        ], $de);
    }

    // ---- o mês

    public function test_abre_no_mes_corrente_de_domingo_a_sabado(): void
    {
        $tela = Livewire::test(HealthAppointmentIndex::class)->assertSet('calView', 'month')->assertSet('calDate', '2026-10-07');
        $semanas = $tela->viewData('calendario')['weeks'];

        // Outubro de 2026 começa numa quinta e termina num sábado: a grade vai de 27/09 a 31/10.
        self::assertCount(5, $semanas);
        self::assertSame('2026-09-27', $semanas[0][0]['date']);
        self::assertSame('2026-10-31', $semanas[4][6]['date']);
        self::assertFalse($semanas[0][0]['in_month']);
        self::assertTrue($semanas[0][4]['in_month'], 'quinta, dia 1º');
        self::assertContains(true, array_column(array_merge(...$semanas), 'today'));
    }

    public function test_o_mes_mostra_horario_e_titulo_do_compromisso_na_propria_grade(): void
    {
        $this->consulta($this->conjuge, '2026-10-22 09:30:00', ['title' => 'Dermatologista', 'location' => 'Clínica Pele']);

        Livewire::test(HealthAppointmentIndex::class)->assertSee('09:30')->assertSee('Dermatologista');
    }

    public function test_compromisso_leva_a_cor_de_quem_e_e_o_item_de_cuidado_e_dia_inteiro_vazado(): void
    {
        $this->consulta($this->titular, '2026-10-20 14:00:00');
        $this->consulta($this->conjuge, '2026-10-20 16:00:00');
        $this->cuidado($this->conjuge); // vence em 22/10

        $eventos = Livewire::test(HealthAppointmentIndex::class)->viewData('calendario')['by_key'];
        $cores = collect($eventos)->map(fn ($e) => $e->color)->values()->all();

        self::assertContains('#2563EB', $cores);
        self::assertContains('#DB2777', $cores);

        $cuidado = collect($eventos)->first(fn ($e) => str_starts_with($e->key, 'cuidado:'));
        self::assertTrue($cuidado->allDay);
        self::assertTrue($cuidado->hollow);
        self::assertSame('#DB2777', $cuidado->color);
        self::assertSame('2026-10-22', $cuidado->date());
    }

    public function test_item_pausado_nao_aparece_no_calendario(): void
    {
        $item = $this->cuidado($this->titular);
        $item->update(['is_active' => false]);

        $eventos = Livewire::test(HealthAppointmentIndex::class)->viewData('calendario')['by_key'];

        self::assertSame([], collect($eventos)->filter(fn ($e) => str_starts_with($e->key, 'cuidado:'))->all());
    }

    public function test_muitos_compromissos_no_dia_viram_mais_n(): void
    {
        foreach (['08:00', '09:00', '10:00', '11:00', '12:00'] as $hora) {
            $this->consulta($this->titular, "2026-10-20 {$hora}:00", ['title' => "Consulta {$hora}"]);
        }

        $dias = collect(Livewire::test(HealthAppointmentIndex::class)->viewData('calendario')['weeks'])->flatten(1)->keyBy('date');

        self::assertCount(3, $dias['2026-10-20']['visible']);
        self::assertSame(2, $dias['2026-10-20']['more']);
        Livewire::test(HealthAppointmentIndex::class)->assertSee('+2 mais');
    }

    // ---- a navegação

    public function test_navega_pelo_mes_e_volta_para_hoje(): void
    {
        Livewire::test(HealthAppointmentIndex::class)
            ->call('nextPeriod')->assertSet('calDate', '2026-11-01')
            ->call('nextPeriod')->assertSet('calDate', '2026-12-01')
            ->call('previousPeriod')->call('previousPeriod')->call('previousPeriod')->assertSet('calDate', '2026-09-01')
            ->call('goToToday')->assertSet('calDate', '2026-10-07');
    }

    public function test_semana_e_dia_andam_de_sete_em_sete_e_de_um_em_um(): void
    {
        Livewire::test(HealthAppointmentIndex::class)
            ->call('setCalView', 'week')->call('nextPeriod')->assertSet('calDate', '2026-10-14')
            ->call('setCalView', 'day')->call('nextPeriod')->assertSet('calDate', '2026-10-15')->call('previousPeriod')->call('previousPeriod')->assertSet('calDate', '2026-10-13');
    }

    public function test_mes_nao_pula_quando_o_dia_nao_existe_no_mes_seguinte(): void
    {
        Livewire::withQueryParams(['data' => '2026-01-31'])->test(HealthAppointmentIndex::class)->call('nextPeriod')->assertSet('calDate', '2026-02-01');
    }

    public function test_visao_e_data_invalidas_na_url_caem_no_padrao(): void
    {
        foreach (['lixo', '2026-13-01', '1999-05-05', '2026-10-1', '2026-02-31'] as $data) {
            Livewire::withQueryParams(['data' => $data, 'visao' => 'ano'])->test(HealthAppointmentIndex::class)->assertSet('calDate', '2026-10-07')->assertSet('calView', 'month');
        }

        Livewire::withQueryParams(['data' => '2026-12-15', 'visao' => 'week'])->test(HealthAppointmentIndex::class)->assertSet('calDate', '2026-12-15')->assertSet('calView', 'week');
    }

    public function test_a_visao_escolhida_fica_na_conta_de_cada_pessoa(): void
    {
        Livewire::test(HealthAppointmentIndex::class)->call('setCalView', 'week');

        self::assertSame(CalendarView::Week, $this->usuarioTitular->fresh()->calendar_view);
        self::assertSame(CalendarView::Month, $this->usuarioConjuge->fresh()->calendar_view);

        Livewire::test(HealthAppointmentIndex::class)->assertSet('calView', 'week');
    }

    public function test_visao_desconhecida_e_ignorada(): void
    {
        Livewire::test(HealthAppointmentIndex::class)->call('setCalView', 'week')->call('setCalView', 'ano')->assertSet('calView', 'week');

        self::assertSame(CalendarView::Week, $this->usuarioTitular->fresh()->calendar_view);
    }

    public function test_tocar_no_dia_abre_o_dia_e_o_dia_oferece_agendar_nele(): void
    {
        Livewire::test(HealthAppointmentIndex::class)
            ->call('goToDay', '2026-10-15')
            ->assertSet('calView', 'day')->assertSet('calDate', '2026-10-15')
            ->assertSee('Nada neste dia.')->assertSee('+ Agendar neste dia')
            ->call('newAppointmentOn', '2026-10-15')
            ->assertSet('showForm', true)->assertSet('scheduledDate', '2026-10-15');
    }

    public function test_dia_invalido_e_ignorado(): void
    {
        foreach (['lixo', '2026-02-31', '2026-10-1', '../../x'] as $dia) {
            Livewire::test(HealthAppointmentIndex::class)->call('goToDay', $dia)->assertSet('calDate', '2026-10-07');
        }

        Livewire::test(HealthAppointmentIndex::class)->call('newAppointmentOn', 'lixo')->assertSet('showForm', true)->assertSet('scheduledDate', '');
    }

    // ---- a semana e o dia (grade de horários)

    public function test_semana_posiciona_o_compromisso_pelo_horario(): void
    {
        $this->consulta($this->titular, '2026-10-07 14:00:00', ['title' => 'Cardiologista']);

        $tela = Livewire::test(HealthAppointmentIndex::class)->call('setCalView', 'week');
        $dados = $tela->viewData('calendario');
        $quarta = collect($dados['days'])->firstWhere('date', '2026-10-07');

        self::assertCount(7, $dados['days']);
        self::assertSame('2026-10-04', $dados['days'][0]['date']);
        self::assertSame(21.0, $quarta['timed'][0]['top'], '14h numa grade que começa às 7h: 7 horas a 3rem');
        self::assertSame(3.0, $quarta['timed'][0]['height'], 'uma hora');
        $tela->assertSee('Cardiologista')->assertSee('14:00');
    }

    public function test_compromissos_que_se_sobrepoem_ficam_lado_a_lado(): void
    {
        $this->consulta($this->titular, '2026-10-07 10:00:00', ['title' => 'Primeira']);
        $this->consulta($this->conjuge, '2026-10-07 10:30:00', ['title' => 'Segunda']);

        $dados = Livewire::test(HealthAppointmentIndex::class)->call('setCalView', 'day')->viewData('calendario');
        $blocos = $dados['days'][0]['timed'];

        self::assertSame(50.0, $blocos[0]['width']);
        self::assertSame(0.0, $blocos[0]['left']);
        self::assertSame(50.0, $blocos[1]['left']);
    }

    public function test_item_de_cuidado_vai_para_a_faixa_de_dia_inteiro(): void
    {
        $this->cuidado($this->titular); // 22/10

        $dados = Livewire::test(HealthAppointmentIndex::class)->call('goToDay', '2026-10-22')->viewData('calendario');

        self::assertTrue($dados['has_all_day']);
        self::assertCount(1, $dados['days'][0]['all_day']);
        self::assertSame([], $dados['days'][0]['timed']);
    }

    // ---- o detalhe do compromisso

    public function test_tocar_no_compromisso_abre_o_detalhe_com_o_que_importa(): void
    {
        $consulta = $this->consulta($this->conjuge, '2026-10-22 09:30:00', [
            'title' => 'Dermatologista', 'location' => 'Clínica Pele', 'address' => 'Rua A, 10', 'phone' => '41 3333-4444', 'notes' => 'Levar exames',
        ]);

        Livewire::test(HealthAppointmentIndex::class)
            ->call('openCalendarEvent', 'consulta:'.$consulta->id)
            ->assertSet('eventVisible', true)
            ->assertSee('Clínica Pele')->assertSee('Rua A, 10')->assertSee('41 3333-4444')->assertSee('Levar exames')
            ->assertSee('Helen')->assertSee('09:30')->assertSee('Adicionar à agenda:')
            ->call('closeEvent')->assertSet('eventVisible', false)->assertSet('openEvent', null);
    }

    public function test_detalhe_de_item_de_cuidado_leva_para_cuidados_e_itens(): void
    {
        $item = $this->cuidado($this->titular);

        Livewire::test(HealthAppointmentIndex::class)
            ->call('openCalendarEvent', 'cuidado:'.$item->id)
            ->assertSee('Trocar ou revisar: Filtro')->assertSee('Abrir em Cuidados e itens');
    }

    public function test_so_oferece_adicionar_a_agenda_para_o_que_ainda_vai_acontecer(): void
    {
        $futura = $this->consulta($this->titular, '2026-10-20 14:00:00', ['title' => 'Futura']);
        $passada = $this->consulta($this->titular, '2026-10-01 14:00:00', ['title' => 'Passada']);

        $tela = Livewire::test(HealthAppointmentIndex::class);

        // O painel de detalhe é a parte que vem depois do "Fechar"; a lista de próximas tem o seu próprio "Adicionar à agenda".
        $html = $tela->call('openCalendarEvent', 'consulta:'.$passada->id)->html();
        $detalhe = substr($html, (int) strpos($html, 'wire:key="detalhe-'));
        self::assertStringContainsString('Passada', $detalhe);
        self::assertStringNotContainsString('wire:key="detalhe-consulta-'.$futura->id, $detalhe);

        $htmlFutura = Livewire::test(HealthAppointmentIndex::class)->call('openCalendarEvent', 'consulta:'.$futura->id)->html();
        $detalheFutura = substr($htmlFutura, (int) strpos($htmlFutura, 'wire:key="detalhe-consulta-'));
        self::assertStringContainsString('Adicionar à agenda:', $detalheFutura);
    }

    public function test_editar_pelo_detalhe_abre_o_formulario_e_fecha_o_detalhe(): void
    {
        $consulta = $this->consulta($this->titular, '2026-10-20 14:00:00', ['title' => 'Cardiologista']);

        Livewire::test(HealthAppointmentIndex::class)
            ->call('openCalendarEvent', 'consulta:'.$consulta->id)
            ->call('editAppointment', $consulta->id)
            ->assertSet('showForm', true)->assertSet('title', 'Cardiologista')->assertSet('eventVisible', false);
    }

    public function test_remover_pelo_detalhe_apaga_e_fecha(): void
    {
        $consulta = $this->consulta($this->titular, '2026-10-20 14:00:00');

        Livewire::test(HealthAppointmentIndex::class)->call('openCalendarEvent', 'consulta:'.$consulta->id)->call('delete', $consulta->id)->assertSet('eventVisible', false);

        self::assertNull(\App\Models\HealthAppointment::query()->find($consulta->id));
    }

    public function test_chave_de_compromisso_inexistente_nao_quebra_a_tela(): void
    {
        Livewire::test(HealthAppointmentIndex::class)->call('openCalendarEvent', 'consulta:nao-existe')->assertSet('eventVisible', true)->assertOk();
    }

    public function test_compromisso_de_outro_perfil_nunca_aparece(): void
    {
        $outroUsuario = User::factory()->create();
        $outroPerfil = FinancialProfile::factory()->create(['owner_user_id' => $outroUsuario->id]);
        $outro = ProfileMember::factory()->create(['profile_id' => $outroPerfil->id, 'user_id' => $outroUsuario->id, 'name' => 'Estranho']);
        // A consulta nasce no perfil de quem está logado: cria como o estranho e volta a ser o titular.
        $this->actingAs($outroUsuario);
        app(ProfileContext::class)->set($outroPerfil, $outro);
        $estranha = app(HealthAppointmentService::class)->create($outro, ['kind' => 'consultation', 'title' => 'Segredo', 'scheduled_at' => CarbonImmutable::parse('2026-10-20 10:00:00')], $outro);
        $this->entrarComo($this->titular);

        Livewire::test(HealthAppointmentIndex::class)->assertDontSee('Segredo')->call('openCalendarEvent', 'consulta:'.$estranha->id)->assertDontSee('Segredo');
    }
    // ---- a ordem na tela

    public function test_por_padrao_a_lista_vem_primeiro(): void
    {
        self::assertSame(AgendaLayout::List, $this->usuarioTitular->fresh()->agenda_layout);

        $html = Livewire::test(HealthAppointmentIndex::class)->html();

        $proximas = strpos($html, '<p class="eyebrow">Próximas</p>');
        $calendario = strpos($html, '<p class="eyebrow">Calendário</p>');

        self::assertNotFalse($proximas);
        self::assertNotFalse($calendario);
        self::assertLessThan($calendario, $proximas, 'padrão = a tela de sempre, lista primeiro');
    }

    public function test_escolher_calendario_primeiro_muda_a_ordem_e_fica_na_conta(): void
    {
        Livewire::test(HealthAppointmentIndex::class)->call('setLayout', 'calendar');

        self::assertSame(AgendaLayout::Calendar, $this->usuarioTitular->fresh()->agenda_layout);

        $html = Livewire::test(HealthAppointmentIndex::class)->html();
        // As duas seções continuam na tela; o HTML já sai na ordem escolhida (foco do teclado e leitor de tela seguem o que se vê).
        $proximas = strpos($html, '<p class="eyebrow">Próximas</p>');
        $calendario = strpos($html, '<p class="eyebrow">Calendário</p>');

        self::assertNotFalse($proximas);
        self::assertNotFalse($calendario);
        self::assertLessThan($proximas, $calendario, 'calendário primeiro');
        self::assertStringContainsString('<p class="eyebrow">Histórico</p>', $html);
    }

    public function test_a_preferencia_e_de_cada_pessoa(): void
    {
        Livewire::test(HealthAppointmentIndex::class)->call('setLayout', 'calendar');

        self::assertSame(AgendaLayout::List, $this->usuarioConjuge->fresh()->agenda_layout);
    }

    public function test_opcao_desconhecida_e_ignorada(): void
    {
        Livewire::test(HealthAppointmentIndex::class)->call('setLayout', 'calendar')->call('setLayout', 'qualquer');

        self::assertSame(AgendaLayout::Calendar, $this->usuarioTitular->fresh()->agenda_layout);
    }

    // ---- as cores

    public function test_cores_padrao_do_casal_sao_azul_e_rosa(): void
    {
        self::assertSame('#2563EB', $this->titular->calendarColor());
        self::assertSame('#DB2777', $this->conjuge->calendarColor());
    }

    public function test_cor_guardada_vale_e_valor_estranho_cai_na_padrao(): void
    {
        $this->titular->update(['color_hex' => '#16a34a']);
        self::assertSame('#16A34A', $this->titular->fresh()->calendarColor());

        // A coluna tem 7 caracteres; um cadastro antigo ou manual poderia ter guardado um valor que não é cor.
        $this->titular->update(['color_hex' => '#GGGGGG']);
        self::assertSame('#2563EB', $this->titular->fresh()->calendarColor(), 'nada fora de #RRGGBB vai para o estilo');
        $this->titular->update(['color_hex' => 'azul']);
        self::assertSame('#2563EB', $this->titular->fresh()->calendarColor());
    }

    public function test_qualquer_um_do_casal_muda_a_cor_dos_dois(): void
    {
        $tela = Livewire::test(HealthAppointmentIndex::class);

        $tela->call('setMemberColor', $this->titular->id, '#16A34A')
            ->call('setMemberColor', $this->conjuge->id, '#7c3aed');

        self::assertSame('#16A34A', $this->titular->fresh()->color_hex);
        self::assertSame('#7C3AED', $this->conjuge->fresh()->color_hex);

        $this->consulta($this->titular, '2026-10-20 14:00:00');
        $eventos = Livewire::test(HealthAppointmentIndex::class)->viewData('calendario')['by_key'];
        self::assertSame('#16A34A', collect($eventos)->first()->color);
    }

    public function test_cor_fora_da_paleta_e_ignorada(): void
    {
        Livewire::test(HealthAppointmentIndex::class)
            ->call('setMemberColor', $this->titular->id, '#123456')
            ->call('setMemberColor', $this->titular->id, 'red')
            ->call('setMemberColor', $this->titular->id, '#2563EB; x:y');

        self::assertNull($this->titular->fresh()->color_hex);
    }

    public function test_nao_muda_a_cor_de_membro_de_outro_perfil(): void
    {
        $outroUsuario = User::factory()->create();
        $outroPerfil = FinancialProfile::factory()->create(['owner_user_id' => $outroUsuario->id]);
        $estranho = ProfileMember::factory()->create(['profile_id' => $outroPerfil->id, 'user_id' => $outroUsuario->id, 'color_hex' => null]);

        try {
            Livewire::test(HealthAppointmentIndex::class)->call('setMemberColor', $estranho->id, '#16A34A');
            self::fail('deveria falhar');
        } catch (ModelNotFoundException) {
            // esperado
        }

        self::assertNull($estranho->fresh()->color_hex);
    }

    public function test_painel_de_cores_so_aparece_no_casal(): void
    {
        Livewire::test(HealthAppointmentIndex::class)
            ->assertSee('Cores do calendário')
            ->call('toggleColors')
            ->assertSet('showColors', true)
            ->assertSee('Azul')
            ->assertSee('Rosa');

        $solo = User::factory()->create();
        $perfilSolo = FinancialProfile::factory()->create(['owner_user_id' => $solo->id]);
        $membroSolo = ProfileMember::factory()->create(['profile_id' => $perfilSolo->id, 'user_id' => $solo->id, 'role' => MemberRole::Primary, 'color_hex' => null]);
        $this->actingAs($solo);
        app(ProfileContext::class)->set($perfilSolo, $membroSolo);

        Livewire::test(HealthAppointmentIndex::class)
            ->assertDontSee('Cores do calendário')
            ->call('toggleColors')
            ->assertDontSee('Azul');
    }

    public function test_legenda_com_o_nome_de_cada_pessoa_so_no_casal(): void
    {
        $html = Livewire::test(HealthAppointmentIndex::class)->html();

        self::assertStringContainsString('Consulta ou exame', $html);
        self::assertStringContainsString('Item de cuidado', $html);
        self::assertMatchesRegularExpression('/#DB2777"><\/span>\s*Helen/', $html);
    }

    public function test_consultor_continua_sem_acesso(): void
    {
        $consultor = User::factory()->consultant()->create();
        ConsultantClient::factory()->create(['consultant_id' => $consultor->id, 'client_id' => $this->usuarioTitular->id, 'status' => ConsultantClientStatus::Active]);

        $this->actingAs($consultor)
            ->withSession(['cerne.active_profile_id' => $this->perfil->id])
            ->get(route('health.appointments.index'))
            ->assertForbidden();
    }

    public function test_paleta_so_aceita_o_que_esta_nela(): void
    {
        self::assertTrue(MemberPalette::isAllowed('#2563eb'));
        self::assertFalse(MemberPalette::isAllowed('#2563EC'));
        self::assertFalse(MemberPalette::isSafe('azul'));
        self::assertFalse(MemberPalette::isSafe(null));
        self::assertTrue(MemberPalette::isSafe('#aBc123'));
    }
}
