<?php

namespace Tests\Feature;

use App\Livewire\Health\HealthAppointmentIndex;
use App\Models\FinancialProfile;
use App\Models\HealthAppointment;
use App\Models\ProfileMember;
use App\Models\User;
use App\Notifications\HealthAppointmentUpcoming;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Consulta detalhada (sugestão de quem testa o app): profissional, especialidade,
 * estabelecimento, endereço, telefone, quem agendou e com quem.
 */
class HealthAppointmentDetailsTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private FinancialProfile $perfil;

    private ProfileMember $membro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->usuario->id]);
        $this->membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuario->id, 'name' => 'Marcelo']);

        $this->actingAs($this->usuario);
        app(ProfileContext::class)->set($this->perfil, $this->membro);
    }

    private function preencher($tela, array $extra = [])
    {
        return $tela->call('newAppointment')->set($extra + [
            'memberId' => $this->membro->id,
            'kind' => 'consultation',
            'professionalName' => 'Dr. João Silva',
            'specialty' => 'Otorrinolaringologia',
            'location' => 'Clínica X',
            'address' => 'Rua X, 100',
            'phone' => '(41) 3000-0000',
            'bookedByName' => 'Marcelo',
            'bookedWithName' => 'Maria, secretária',
            'scheduledDate' => now()->addDays(10)->toDateString(),
            'scheduledTime' => '14:00',
            'notes' => 'Levar exames anteriores.',
        ]);
    }

    public function test_guarda_todos_os_detalhes_da_consulta(): void
    {
        $this->preencher(Livewire::test(HealthAppointmentIndex::class))->call('save')->assertHasNoErrors();

        $consulta = HealthAppointment::query()->sole();
        self::assertSame('Dr. João Silva', $consulta->professional_name);
        self::assertSame('Otorrinolaringologia', $consulta->specialty);
        self::assertSame('Clínica X', $consulta->location);
        self::assertSame('Rua X, 100', $consulta->address);
        self::assertSame('(41) 3000-0000', $consulta->phone);
        self::assertSame('Marcelo', $consulta->booked_by_name);
        self::assertSame('Maria, secretária', $consulta->booked_with_name);
        self::assertSame('Levar exames anteriores.', $consulta->notes);
    }

    public function test_consulta_sem_titulo_ganha_especialidade_e_profissional(): void
    {
        $this->preencher(Livewire::test(HealthAppointmentIndex::class), ['title' => ''])->call('save')->assertHasNoErrors();

        self::assertSame('Otorrinolaringologia · Dr. João Silva', HealthAppointment::query()->sole()->title);
    }

    public function test_titulo_digitado_continua_valendo(): void
    {
        $this->preencher(Livewire::test(HealthAppointmentIndex::class), ['title' => 'Retorno do aparelho'])->call('save');

        self::assertSame('Retorno do aparelho', HealthAppointment::query()->sole()->title);
    }

    public function test_consulta_sem_titulo_nem_profissional_nem_especialidade_e_barrada(): void
    {
        $this->preencher(Livewire::test(HealthAppointmentIndex::class), ['title' => '', 'professionalName' => '', 'specialty' => ''])
            ->call('save')
            ->assertHasErrors('title');

        self::assertSame(0, HealthAppointment::query()->count());
    }

    public function test_exame_sempre_exige_o_nome_e_nao_guarda_profissional_nem_especialidade(): void
    {
        $this->preencher(Livewire::test(HealthAppointmentIndex::class), ['kind' => 'exam', 'title' => ''])
            ->call('save')
            ->assertHasErrors('title');

        $this->preencher(Livewire::test(HealthAppointmentIndex::class), ['kind' => 'exam', 'title' => 'Hemograma completo'])
            ->call('save')
            ->assertHasNoErrors();

        $exame = HealthAppointment::query()->sole();
        self::assertNull($exame->professional_name);
        self::assertNull($exame->specialty);
        self::assertSame('Clínica X', $exame->location, 'o local vale para exame também');
    }

    public function test_telefone_malformado_e_barrado_e_os_opcionais_vazios_viram_nulo(): void
    {
        $this->preencher(Livewire::test(HealthAppointmentIndex::class), ['phone' => 'ligar depois'])
            ->call('save')
            ->assertHasErrors('phone');

        $this->preencher(Livewire::test(HealthAppointmentIndex::class), ['address' => '  ', 'phone' => '', 'bookedByName' => '', 'bookedWithName' => ''])
            ->call('save')
            ->assertHasNoErrors();

        $consulta = HealthAppointment::query()->sole();
        self::assertNull($consulta->address);
        self::assertNull($consulta->phone);
        self::assertNull($consulta->booked_by_name);
        self::assertNull($consulta->booked_with_name);
    }

    public function test_editar_traz_os_detalhes_e_salvar_atualiza(): void
    {
        $this->preencher(Livewire::test(HealthAppointmentIndex::class))->call('save');
        $consulta = HealthAppointment::query()->sole();

        Livewire::test(HealthAppointmentIndex::class)
            ->call('editAppointment', $consulta->id)
            ->assertSet('professionalName', 'Dr. João Silva')
            ->assertSet('address', 'Rua X, 100')
            ->assertSet('bookedWithName', 'Maria, secretária')
            ->set('phone', '(41) 3999-8888')
            ->set('bookedWithName', '')
            ->call('save')
            ->assertHasNoErrors();

        $consulta->refresh();
        self::assertSame('(41) 3999-8888', $consulta->phone);
        self::assertNull($consulta->booked_with_name);
        self::assertSame('Rua X, 100', $consulta->address, 'o que não foi mexido continua');
    }

    public function test_a_lista_mostra_quem_atende_onde_fica_telefone_e_agendamento(): void
    {
        $this->preencher(Livewire::test(HealthAppointmentIndex::class), ['title' => 'Retorno'])->call('save');

        Livewire::test(HealthAppointmentIndex::class)
            ->assertSee('Otorrinolaringologia · Dr. João Silva')
            ->assertSee('Clínica X · Rua X, 100')
            ->assertSee('(41) 3000-0000')
            ->assertSeeHtml('href="tel:4130000000"')
            ->assertSee('Agendado por Marcelo · com Maria, secretária')
            ->assertSee('Levar exames anteriores.');
    }

    public function test_o_titulo_montado_sozinho_nao_aparece_repetido(): void
    {
        $this->preencher(Livewire::test(HealthAppointmentIndex::class), ['title' => ''])->call('save');

        $html = Livewire::test(HealthAppointmentIndex::class)->html();

        self::assertSame(1, substr_count($html, 'Otorrinolaringologia · Dr. João Silva'));
    }

    public function test_o_formulario_de_exame_nao_pergunta_pelo_profissional(): void
    {
        Livewire::test(HealthAppointmentIndex::class)
            ->call('newAppointment')
            ->assertSee('Profissional')
            ->set('kind', 'exam')
            ->assertDontSee('Especialidade')
            ->assertSee('Nome do exame');
    }

    public function test_o_lembrete_leva_endereco_telefone_e_com_quem_agendou_mas_nao_as_observacoes(): void
    {
        $this->preencher(Livewire::test(HealthAppointmentIndex::class), ['notes' => 'Segredo médico detalhado'])->call('save');
        $consulta = HealthAppointment::query()->with('member')->sole();

        $notificacao = HealthAppointmentUpcoming::forAppointment($consulta);
        $html = (string) $notificacao->toMail($this->usuario)->render();

        self::assertStringContainsString('Clínica X', $html);
        self::assertStringContainsString('Rua X, 100', $html);
        self::assertStringContainsString('(41) 3000-0000', $html);
        self::assertStringContainsString('Maria, secretária', $html);
        self::assertStringNotContainsString('Segredo médico detalhado', $html, 'anotações de saúde não vão por e-mail');
    }
}
