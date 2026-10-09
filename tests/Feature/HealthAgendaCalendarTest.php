<?php

namespace Tests\Feature;

use App\Enums\AgendaLayout;
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
 * Calendário da Agenda de Saúde: grade do mês com os compromissos e itens de cuidado, a ordem
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

    // ---- a grade do mês

    public function test_mostra_o_mes_corrente_de_domingo_a_sabado(): void
    {
        $semanas = Livewire::test(HealthAppointmentIndex::class)->assertSet('calMonth', '2026-10')->viewData('calendario')['semanas'];

        // Outubro de 2026 começa numa quinta e termina numa sábado: a grade vai de 27/09 a 31/10.
        self::assertCount(5, $semanas);
        self::assertSame('2026-09-27', $semanas[0][0]['data']);
        self::assertSame('2026-10-31', $semanas[4][6]['data']);
        self::assertFalse($semanas[0][0]['doMes']);
        self::assertTrue($semanas[0][4]['doMes'], 'quinta, dia 1º');
        self::assertContains(true, array_column(array_merge(...$semanas), 'hoje'));
    }

    public function test_dias_com_compromisso_ganham_marca_na_cor_de_quem_e(): void
    {
        $this->consulta($this->titular, '2026-10-20 14:00:00');
        $this->consulta($this->conjuge, '2026-10-20 16:00:00');
        $this->cuidado($this->conjuge); // vence em 22/10

        $dias = collect(Livewire::test(HealthAppointmentIndex::class)->viewData('calendario')['semanas'])->flatten(1)->keyBy('data');

        self::assertSame(
            [['cor' => '#2563EB', 'tipo' => 'consulta'], ['cor' => '#DB2777', 'tipo' => 'consulta']],
            $dias['2026-10-20']['marcas'],
        );
        self::assertSame([['cor' => '#DB2777', 'tipo' => 'cuidado']], $dias['2026-10-22']['marcas']);
        self::assertSame([], $dias['2026-10-21']['marcas']);
    }

    public function test_item_pausado_nao_aparece_no_calendario(): void
    {
        $item = $this->cuidado($this->titular);
        $item->update(['is_active' => false]);

        $dias = collect(Livewire::test(HealthAppointmentIndex::class)->viewData('calendario')['semanas'])->flatten(1)->keyBy('data');

        self::assertSame([], $dias['2026-10-22']['marcas']);
    }

    public function test_navega_entre_os_meses_e_volta_para_hoje(): void
    {
        Livewire::test(HealthAppointmentIndex::class)
            ->call('nextMonth')->assertSet('calMonth', '2026-11')
            ->call('nextMonth')->assertSet('calMonth', '2026-12')
            ->call('nextMonth')->assertSet('calMonth', '2027-01')
            ->call('previousMonth')->call('previousMonth')->call('previousMonth')->call('previousMonth')->assertSet('calMonth', '2026-09')
            ->call('goToday')->assertSet('calMonth', '2026-10')->assertSet('selectedDate', '2026-10-07');
    }

    public function test_mes_invalido_na_url_cai_no_mes_corrente(): void
    {
        foreach (['lixo', '2026-13', '1999-05', '2026-1'] as $mes) {
            Livewire::withQueryParams(['mes' => $mes])->test(HealthAppointmentIndex::class)->assertSet('calMonth', '2026-10');
        }

        Livewire::withQueryParams(['mes' => '2026-12'])->test(HealthAppointmentIndex::class)->assertSet('calMonth', '2026-12');
    }

    // ---- o dia tocado

    public function test_tocar_num_dia_mostra_o_que_tem_nele(): void
    {
        $this->consulta($this->conjuge, '2026-10-22 09:30:00', ['title' => 'Dermatologista']);
        $this->cuidado($this->titular);

        Livewire::test(HealthAppointmentIndex::class)
            ->call('selectDay', '2026-10-22')
            ->assertSet('selectedDate', '2026-10-22')
            ->assertSee('22/10/2026')
            ->assertSee('Dermatologista')
            ->assertSee('Helen · 09:30')
            ->assertSee('Trocar ou revisar: Filtro')
            ->assertSee('Abrir em Cuidados e itens');
    }

    public function test_dia_vazio_oferece_agendar_nele(): void
    {
        Livewire::test(HealthAppointmentIndex::class)
            ->call('selectDay', '2026-10-15')
            ->assertSee('Nada neste dia.')
            ->assertSee('+ Agendar neste dia')
            ->call('newAppointmentOn', '2026-10-15')
            ->assertSet('showForm', true)
            ->assertSet('scheduledDate', '2026-10-15');
    }

    public function test_dia_invalido_e_ignorado(): void
    {
        foreach (['lixo', '2026-02-31', '2026-10-1', '../../x'] as $dia) {
            Livewire::test(HealthAppointmentIndex::class)->call('selectDay', $dia)->assertSet('selectedDate', null);
        }

        Livewire::test(HealthAppointmentIndex::class)->call('newAppointmentOn', 'lixo')->assertSet('showForm', true)->assertSet('scheduledDate', '');
    }

    public function test_tocar_num_dia_do_mes_vizinho_leva_para_o_mes_dele(): void
    {
        Livewire::test(HealthAppointmentIndex::class)
            ->call('selectDay', '2026-09-28')
            ->assertSet('calMonth', '2026-09')
            ->assertSet('selectedDate', '2026-09-28');
    }

    public function test_trocar_de_mes_fecha_o_dia(): void
    {
        Livewire::test(HealthAppointmentIndex::class)
            ->call('selectDay', '2026-10-20')
            ->call('nextMonth')
            ->assertSet('selectedDate', null);
    }

    public function test_consulta_do_dia_pode_ser_editada_pelo_calendario(): void
    {
        $consulta = $this->consulta($this->titular, '2026-10-20 14:00:00', ['title' => 'Cardiologista']);

        Livewire::test(HealthAppointmentIndex::class)
            ->call('selectDay', '2026-10-20')
            ->call('editAppointment', $consulta->id)
            ->assertSet('showForm', true)
            ->assertSet('title', 'Cardiologista');
    }

    public function test_dia_da_agenda_oferece_adicionar_a_agenda_so_para_o_que_ainda_vai_acontecer(): void
    {
        $this->consulta($this->titular, '2026-10-20 14:00:00', ['title' => 'Futura']);
        $this->consulta($this->titular, '2026-10-01 14:00:00', ['title' => 'Passada']);

        Livewire::test(HealthAppointmentIndex::class)->call('selectDay', '2026-10-20')->assertSee('Adicionar à agenda:');

        // Só o painel do dia (a lista de próximas tem o seu próprio "Adicionar à agenda"): com o calendário
        // primeiro, o painel é tudo o que vem antes da lista.
        $html = Livewire::test(HealthAppointmentIndex::class)->call('setLayout', 'calendar')->call('selectDay', '2026-10-01')->html();
        $inicio = (int) strpos($html, 'wire:key="dia-consulta-');
        $painel = substr($html, $inicio, (int) strpos($html, '<p class="eyebrow">Próximas</p>') - $inicio);
        self::assertStringContainsString('Passada', $painel);
        self::assertStringNotContainsString('Adicionar à agenda:', $painel);
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
        $dias = collect(Livewire::test(HealthAppointmentIndex::class)->viewData('calendario')['semanas'])->flatten(1)->keyBy('data');
        self::assertSame('#16A34A', $dias['2026-10-20']['marcas'][0]['cor']);
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
