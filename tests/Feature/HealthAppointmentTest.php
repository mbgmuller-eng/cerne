<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Livewire\Health\HealthAppointmentIndex;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\HealthAppointment;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\HealthAppointmentService;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Agenda de consulta/exame — mesma visibilidade da Ficha de Saúde
 * (CoupleHealthScope): os dois do casal veem tudo, consultor não vê nada.
 */
class HealthAppointmentTest extends TestCase
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

        $this->usuarioTitular = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $this->usuarioTitular->id]);
        $this->titular = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuarioTitular->id, 'name' => 'Marcelo']);

        $this->usuarioConjuge = User::factory()->create();
        $this->conjuge = ProfileMember::factory()->secondary()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuarioConjuge->id, 'name' => 'Helen']);
    }

    public function test_conjuge_ve_consulta_marcada_pelo_titular(): void
    {
        $this->entrarComo($this->titular);
        app(HealthAppointmentService::class)->create($this->titular, [
            'kind' => 'consultation', 'title' => 'Cardiologista', 'scheduled_at' => now()->addDays(5),
        ], $this->titular);

        $this->entrarComo($this->conjuge);
        self::assertSame(['Cardiologista'], HealthAppointment::query()->pluck('title')->all());
    }

    public function test_consultor_vinculado_nao_ve_nada(): void
    {
        $this->entrarComo($this->titular);
        app(HealthAppointmentService::class)->create($this->titular, [
            'kind' => 'consultation', 'title' => 'Cardiologista', 'scheduled_at' => now()->addDays(5),
        ], $this->titular);

        app(ProfileContext::class)->set($this->perfil, member: null, asConsultant: true);

        self::assertSame(0, HealthAppointment::query()->count());
    }

    public function test_marcelo_agenda_exame_da_helen(): void
    {
        $this->entrarComo($this->titular);
        $consulta = app(HealthAppointmentService::class)->create($this->conjuge, [
            'kind' => 'exam', 'title' => 'Hemograma', 'scheduled_at' => now()->addDays(2),
        ], $this->titular);

        self::assertSame($this->conjuge->id, $consulta->member_id);
        self::assertSame($this->titular->id, $consulta->created_by_member_id);
    }

    public function test_passado_e_futuro_ficam_em_listas_separadas(): void
    {
        $this->entrarComo($this->titular);
        $service = app(HealthAppointmentService::class);
        $service->create($this->titular, ['kind' => 'exam', 'title' => 'Já fiz', 'scheduled_at' => now()->subDays(3)], $this->titular);
        $service->create($this->titular, ['kind' => 'exam', 'title' => 'Vou fazer', 'scheduled_at' => now()->addDays(3)], $this->titular);

        self::assertSame(['Já fiz'], HealthAppointment::past()->pluck('title')->all());
        self::assertSame(['Vou fazer'], HealthAppointment::upcoming()->pluck('title')->all());
    }

    public function test_agenda_pela_tela(): void
    {
        $this->entrarComo($this->titular);

        Livewire::test(HealthAppointmentIndex::class)
            ->call('newAppointment')
            ->set('memberId', $this->titular->id)
            ->set('kind', 'consultation')
            ->set('title', 'Dermatologista')
            ->set('scheduledDate', now()->addDays(10)->toDateString())
            ->set('scheduledTime', '14:30')
            ->call('save')
            ->assertHasNoErrors();

        $consulta = HealthAppointment::query()->firstOrFail();
        self::assertSame('Dermatologista', $consulta->title);
        self::assertSame('14:30', $consulta->scheduled_at->format('H:i'));
    }

    public function test_titulo_obrigatorio_pela_tela(): void
    {
        $this->entrarComo($this->titular);

        Livewire::test(HealthAppointmentIndex::class)
            ->call('newAppointment')
            ->set('title', '')
            ->call('save')
            ->assertHasErrors(['title']);
    }

    public function test_consultor_leva_403(): void
    {
        $consultor = User::factory()->consultant()->create();
        ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id, 'client_id' => $this->usuarioTitular->id, 'status' => ConsultantClientStatus::Active,
        ]);

        $this->actingAs($consultor)
            ->withSession(['cerne.active_profile_id' => $this->perfil->id])
            ->get(route('health.appointments.index'))
            ->assertForbidden();
    }

    private function entrarComo(ProfileMember $membro): void
    {
        $usuario = $membro->id === $this->titular->id ? $this->usuarioTitular : $this->usuarioConjuge;
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($this->perfil, $membro);
    }
}
