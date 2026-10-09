<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Livewire\Health\HealthAppointmentIndex;
use App\Livewire\Health\HealthCareItemIndex;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\HealthAppointment;
use App\Models\HealthCareItem;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\HealthAppointmentService;
use App\Services\HealthCareItemService;
use App\Support\CalendarEvent;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Adicionar à agenda" na Saúde: link do Google Agenda e arquivo .ics para consulta, exame e item de cuidado.
 * O evento leva título, data, hora, local e endereço; profissional e telefone vão na descrição; as
 * observações só vão se a pessoa pedir.
 */
class HealthCalendarExportTest extends TestCase
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
        $this->titular = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuarioTitular->id, 'name' => 'Marcelo']);

        $this->usuarioConjuge = User::factory()->create();
        $this->conjuge = ProfileMember::factory()->secondary()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuarioConjuge->id, 'name' => 'Helen']);

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

    private function consulta(array $extra = []): HealthAppointment
    {
        return app(HealthAppointmentService::class)->create($this->titular, $extra + [
            'kind' => 'consultation',
            'title' => 'Retorno do aparelho',
            'professional_name' => 'Dr. João Silva',
            'specialty' => 'Otorrinolaringologia',
            'location' => 'Clínica Vida',
            'address' => 'Rua das Flores, 100, sala 3',
            'phone' => '(41) 3000-0000',
            'notes' => 'Levar exames anteriores.',
            'scheduled_at' => CarbonImmutable::parse('2026-10-20 14:00:00'),
        ], $this->titular);
    }

    private function cuidado(array $extra = []): HealthCareItem
    {
        return app(HealthCareItemService::class)->create($this->titular, $extra + [
            'category' => 'hearing_aid', 'name' => 'Filtro', 'device_name' => 'Aparelho auditivo direito',
            'interval_value' => 15, 'interval_unit' => 'day', 'last_done_on' => '2026-10-07',
            'notes' => 'Comprar na loja X.',
        ], $this->titular);
    }

    /** O .ics desdobra linhas longas; para conferir o texto, junta de volta. */
    private function desdobrar(string $ics): string
    {
        return str_replace("\r\n ", '', $ics);
    }

    // ---- o arquivo .ics

    public function test_consulta_vira_evento_com_horario_local_e_descricao(): void
    {
        $ics = $this->desdobrar(CalendarEvent::forAppointment($this->consulta())->ics());

        self::assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        self::assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        self::assertStringContainsString('SUMMARY:Retorno do aparelho', $ics);
        // 14:00 em Brasília = 17:00 UTC; a duração presumida é de uma hora.
        self::assertStringContainsString('DTSTART:20261020T170000Z', $ics);
        self::assertStringContainsString('DTEND:20261020T180000Z', $ics);
        // Vírgula é caractere especial no .ics e vai com barra.
        self::assertStringContainsString('LOCATION:Clínica Vida\, Rua das Flores\, 100\, sala 3', $ics);
        self::assertStringContainsString('Profissional: Dr. João Silva (Otorrinolaringologia)', $ics);
        self::assertStringContainsString('Telefone: (41) 3000-0000', $ics);
    }

    public function test_observacoes_ficam_de_fora_por_padrao_e_entram_quando_pedidas(): void
    {
        $consulta = $this->consulta();

        self::assertStringNotContainsString('Levar exames', CalendarEvent::forAppointment($consulta)->ics());
        self::assertStringNotContainsString('Levar exames', rawurldecode(CalendarEvent::forAppointment($consulta)->googleUrl()));

        self::assertStringContainsString('Observações: Levar exames anteriores.', $this->desdobrar(CalendarEvent::forAppointment($consulta, withNotes: true)->ics()));
        self::assertStringContainsString('Observações: Levar exames anteriores.', urldecode(CalendarEvent::forAppointment($consulta, withNotes: true)->googleUrl()));
    }

    public function test_exame_sem_profissional_nem_telefone_nao_inventa_linhas(): void
    {
        $exame = $this->consulta([
            'kind' => 'exam', 'title' => 'Hemograma completo', 'professional_name' => null, 'specialty' => null,
            'phone' => null, 'notes' => null,
        ]);

        $ics = CalendarEvent::forAppointment($exame)->ics();

        self::assertStringContainsString('SUMMARY:Hemograma completo', $ics);
        self::assertStringNotContainsString('Profissional', $ics);
        self::assertStringNotContainsString('Telefone', $ics);
        self::assertStringNotContainsString('DESCRIPTION', $ics, 'sem nada a dizer, sem descrição');
    }

    public function test_so_com_especialidade_a_linha_vira_especialidade(): void
    {
        $consulta = $this->consulta(['professional_name' => null]);

        self::assertStringContainsString('Especialidade: Otorrinolaringologia', $this->desdobrar(CalendarEvent::forAppointment($consulta)->ics()));
    }

    public function test_linhas_do_arquivo_respeitam_o_limite_sem_cortar_acento(): void
    {
        $consulta = $this->consulta(['title' => str_repeat('Avaliação cardiológica ', 5)]);

        $ics = CalendarEvent::forAppointment($consulta)->ics();

        foreach (explode("\r\n", $ics) as $linha) {
            self::assertLessThanOrEqual(75, strlen($linha));
            self::assertTrue(mb_check_encoding($linha, 'UTF-8'), 'um caractere acentuado foi cortado ao meio');
        }
        self::assertStringContainsString(trim(str_repeat('Avaliação cardiológica ', 5)), $this->desdobrar($ics));
        self::assertStringContainsString("\r\n ", $ics, 'o título longo precisa ter sido dobrado');
    }

    public function test_nome_da_pessoa_so_aparece_no_casal(): void
    {
        $consulta = $this->consulta()->load('member');

        self::assertStringNotContainsString('Para:', CalendarEvent::forAppointment($consulta)->ics());
        self::assertStringContainsString('Para: Marcelo', $this->desdobrar(CalendarEvent::forAppointment($consulta, showPerson: true)->ics()));
    }

    public function test_item_de_cuidado_vira_evento_de_dia_inteiro_na_proxima_data(): void
    {
        $item = $this->cuidado();

        $ics = $this->desdobrar(CalendarEvent::forCareItem($item)->ics());

        self::assertStringContainsString('SUMMARY:Trocar ou revisar: Filtro (Aparelho auditivo direito)', $ics);
        self::assertStringContainsString('DTSTART;VALUE=DATE:20261022', $ics);
        self::assertStringContainsString('DTEND;VALUE=DATE:20261023', $ics, 'o fim do dia inteiro é exclusivo');
        self::assertStringContainsString('Frequência: a cada 15 dias', $ics);
        self::assertStringContainsString('Última vez: 07/10/2026', $ics);
        self::assertStringNotContainsString('Comprar na loja X', $ics);
        self::assertStringContainsString('Observações: Comprar na loja X.', $this->desdobrar(CalendarEvent::forCareItem($item, withNotes: true)->ics()));
    }

    public function test_item_sem_proxima_data_nao_gera_evento(): void
    {
        $item = $this->cuidado();
        $item->forceFill(['next_due_on' => null])->save();

        self::assertNull(CalendarEvent::forCareItem($item->fresh()));
    }

    // ---- o link do Google Agenda

    public function test_link_do_google_agenda_leva_os_campos_do_evento(): void
    {
        $url = CalendarEvent::forAppointment($this->consulta())->googleUrl();

        self::assertStringStartsWith('https://calendar.google.com/calendar/render?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        self::assertSame('TEMPLATE', $q['action']);
        self::assertSame('Retorno do aparelho', $q['text']);
        self::assertSame('20261020T170000Z/20261020T180000Z', $q['dates']);
        self::assertSame('Clínica Vida, Rua das Flores, 100, sala 3', $q['location']);
        self::assertStringContainsString('Profissional: Dr. João Silva', $q['details']);
        self::assertStringContainsString('Telefone: (41) 3000-0000', $q['details']);
    }

    public function test_link_do_google_para_item_de_cuidado_usa_dia_inteiro_e_nao_tem_local(): void
    {
        $url = CalendarEvent::forCareItem($this->cuidado())->googleUrl();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        self::assertSame('20261022/20261023', $q['dates']);
        self::assertArrayNotHasKey('location', $q);
    }

    public function test_observacao_enorme_nao_estoura_o_endereco_do_google(): void
    {
        $consulta = $this->consulta(['notes' => str_repeat('palavra ', 600)]);

        self::assertLessThan(2200, strlen(CalendarEvent::forAppointment($consulta, withNotes: true)->googleUrl()));
        self::assertStringContainsString(str_repeat('palavra ', 599).'palavra', $this->desdobrar(CalendarEvent::forAppointment($consulta, withNotes: true)->ics()), 'o arquivo leva o texto inteiro');
    }

    // ---- o download

    public function test_baixa_o_arquivo_da_consulta(): void
    {
        $consulta = $this->consulta();

        $resposta = $this->get(route('health.appointments.ics', $consulta->id))->assertOk();

        self::assertStringStartsWith('text/calendar', $resposta->headers->get('Content-Type'));
        self::assertStringContainsString('attachment; filename="retorno-do-aparelho.ics"', $resposta->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', $resposta->headers->get('Cache-Control'));
        $resposta->assertSee('SUMMARY:Retorno do aparelho', false);
        $resposta->assertDontSee('Levar exames', false);
    }

    public function test_baixa_com_observacoes_so_quando_pedido(): void
    {
        $consulta = $this->consulta();

        $this->get(route('health.appointments.ics', [$consulta->id, 'obs' => 1]))
            ->assertOk()
            ->assertSee('Levar exames anteriores.', false);
    }

    public function test_no_casal_o_arquivo_diz_para_quem_e(): void
    {
        $consulta = $this->consulta();

        $this->get(route('health.appointments.ics', $consulta->id))->assertSee('Para: Marcelo', false);
    }

    public function test_o_conjuge_tambem_baixa(): void
    {
        $consulta = $this->consulta();
        $this->entrarComo($this->conjuge);

        $this->get(route('health.appointments.ics', $consulta->id))->assertOk();
    }

    public function test_baixa_o_arquivo_do_item_de_cuidado(): void
    {
        $item = $this->cuidado();

        $this->get(route('health.care.ics', $item->id))
            ->assertOk()
            ->assertSee('DTSTART;VALUE=DATE:20261022', false);
    }

    public function test_item_sem_proxima_data_leva_404_no_download(): void
    {
        $item = $this->cuidado();
        $item->forceFill(['next_due_on' => null])->save();

        $this->get(route('health.care.ics', $item->id))->assertNotFound();
    }

    public function test_consulta_de_outro_perfil_leva_404(): void
    {
        $outroUsuario = User::factory()->create();
        $outroPerfil = FinancialProfile::factory()->create(['owner_user_id' => $outroUsuario->id]);
        $outroMembro = ProfileMember::factory()->create(['profile_id' => $outroPerfil->id, 'user_id' => $outroUsuario->id]);

        $alheia = HealthAppointment::query()->withoutGlobalScopes()->create([
            'profile_id' => $outroPerfil->id, 'member_id' => $outroMembro->id, 'kind' => 'exam', 'title' => 'Exame alheio',
            'scheduled_at' => CarbonImmutable::parse('2026-10-20 10:00:00'), 'created_by_member_id' => $outroMembro->id,
        ]);

        $this->get(route('health.appointments.ics', $alheia->id))->assertNotFound();
    }

    public function test_consultor_vinculado_leva_403(): void
    {
        $consulta = $this->consulta();

        $consultor = User::factory()->consultant()->create();
        ConsultantClient::factory()->create(['consultant_id' => $consultor->id, 'client_id' => $this->usuarioTitular->id, 'status' => ConsultantClientStatus::Active]);

        $this->actingAs($consultor)
            ->withSession(['cerne.active_profile_id' => $this->perfil->id])
            ->get(route('health.appointments.ics', $consulta->id))
            ->assertForbidden();
    }

    public function test_sem_login_manda_para_a_entrada(): void
    {
        $consulta = $this->consulta();

        auth()->logout();
        app(ProfileContext::class)->clear();

        $this->get(route('health.appointments.ics', $consulta->id))->assertRedirect(route('login'));
    }

    // ---- as telas

    public function test_agenda_oferece_adicionar_a_agenda_nas_proximas_e_a_caixa_de_observacoes(): void
    {
        $consulta = $this->consulta();

        Livewire::test(HealthAppointmentIndex::class)
            ->assertSee('Adicionar à agenda:')
            ->assertSee('Google Agenda')
            ->assertSee('Arquivo .ics')
            ->assertSee('Incluir minhas observações')
            ->assertSeeHtml(route('health.appointments.ics', $consulta->id))
            ->assertSeeHtml(route('health.appointments.ics', [$consulta->id, 'obs' => 1]));
    }

    public function test_sem_observacao_nao_ha_caixa_nem_endereco_com_observacoes(): void
    {
        $consulta = $this->consulta(['notes' => null]);

        Livewire::test(HealthAppointmentIndex::class)
            ->assertSee('Adicionar à agenda:')
            ->assertDontSee('Incluir minhas observações')
            ->assertDontSeeHtml('obs=1');
    }

    public function test_consulta_passada_nao_oferece_adicionar_a_agenda(): void
    {
        $this->consulta(['scheduled_at' => CarbonImmutable::parse('2026-09-01 10:00:00')]);

        Livewire::test(HealthAppointmentIndex::class)->assertDontSee('Adicionar à agenda:');
    }

    public function test_cuidados_oferece_adicionar_a_agenda(): void
    {
        $item = $this->cuidado();

        Livewire::test(HealthCareItemIndex::class)
            ->assertSee('Adicionar à agenda:')
            ->assertSee('Incluir minhas observações')
            ->assertSeeHtml(route('health.care.ics', $item->id));
    }
}
