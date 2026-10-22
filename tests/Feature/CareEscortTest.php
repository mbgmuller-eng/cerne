<?php

namespace Tests\Feature;

use App\Enums\CaregiverAccess;
use App\Enums\CaregiverStatus;
use App\Enums\HealthAppointmentKind;
use App\Enums\MemberRole;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Livewire\Care\CareIndex;
use App\Livewire\Health\HealthAppointmentIndex;
use App\Models\FinancialProfile;
use App\Models\HealthAppointment;
use App\Models\HealthCareItem;
use App\Models\ProfileCaregiver;
use App\Models\ProfileMember;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\HealthAppointmentUpcoming;
use App\Notifications\HealthCareItemDue;
use App\Notifications\HealthEscortAssigned;
use App\Services\CareProfileService;
use App\Services\HealthAppointmentService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Quem vai levar?" em cada consulta da pessoa acompanhada e avisos escolhidos por tipo (consultas e exames x troca de itens).
 */
class CareEscortTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cerne.care.enabled' => true]);
    }

    public function test_qualquer_participante_se_oferece_inclusive_o_de_leitura_mas_so_o_de_edicao_escolhe_outro(): void
    {
        Notification::fake();
        [$dono, $pessoa, $consulta] = $this->cenario();
        $leitor = $this->cuidador($pessoa, CaregiverAccess::Read);
        $editor = $this->cuidador($pessoa, CaregiverAccess::Edit);

        // Leitor: "eu levo" funciona; escolher outro, não.
        $this->entrar($leitor, $pessoa);
        Livewire::test(HealthAppointmentIndex::class)->call('takeEscort', $consulta->id);
        $this->assertSame($leitor->id, $consulta->fresh()->escort_user_id);

        // O Livewire de teste devolve a recusa como status 403, não como exceção.
        Livewire::test(HealthAppointmentIndex::class)->call('assignEscort', $consulta->id, $dono->id)->assertStatus(403);
        $this->assertSame($leitor->id, $consulta->fresh()->escort_user_id);

        // Editor escolhe o dono e o escolhido é avisado.
        $this->entrar($editor, $pessoa);
        Livewire::test(HealthAppointmentIndex::class)->call('assignEscort', $consulta->id, $dono->id);
        $this->assertSame($dono->id, $consulta->fresh()->escort_user_id);
        Notification::assertSentTo($dono, HealthEscortAssigned::class);
        Notification::assertNotSentTo($editor, HealthEscortAssigned::class);
    }

    public function test_so_quem_faz_parte_do_cuidado_pode_ser_escolhido(): void
    {
        [$dono, $pessoa, $consulta] = $this->cenario();
        $estranho = User::factory()->create();

        $this->entrar($dono, $pessoa);

        $this->expectException(AuthorizationException::class);
        app(HealthAppointmentService::class)->assignEscort($consulta, $estranho, $dono);
    }

    public function test_quem_esta_escalado_desiste_e_o_editor_libera(): void
    {
        [$dono, $pessoa, $consulta] = $this->cenario();
        $leitor = $this->cuidador($pessoa, CaregiverAccess::Read);
        $outroLeitor = $this->cuidador($pessoa, CaregiverAccess::Read);

        $this->entrar($leitor, $pessoa);
        Livewire::test(HealthAppointmentIndex::class)->call('takeEscort', $consulta->id)->call('releaseEscort', $consulta->id);
        $this->assertNull($consulta->fresh()->escort_user_id);

        Livewire::test(HealthAppointmentIndex::class)->call('takeEscort', $consulta->id);

        // Outro leitor não solta a escolha de quem já se ofereceu; o dono solta.
        $this->entrar($outroLeitor, $pessoa);
        Livewire::test(HealthAppointmentIndex::class)->call('releaseEscort', $consulta->id)->assertStatus(403);

        $this->entrar($dono, $pessoa);
        Livewire::test(HealthAppointmentIndex::class)->call('releaseEscort', $consulta->id);
        $this->assertNull($consulta->fresh()->escort_user_id);
    }

    public function test_o_leitor_continua_sem_alterar_a_consulta_em_si(): void
    {
        [, $pessoa, $consulta] = $this->cenario();
        $leitor = $this->cuidador($pessoa, CaregiverAccess::Read);

        $this->entrar($leitor, $pessoa);
        Livewire::test(HealthAppointmentIndex::class)->call('takeEscort', $consulta->id);

        $this->expectException(AuthorizationException::class);
        HealthAppointment::query()->findOrFail($consulta->id)->update(['location' => 'Outro lugar']);
    }

    public function test_a_tela_mostra_quem_leva_e_o_lembrete_diz_quem_vai(): void
    {
        Notification::fake();
        [$dono, $pessoa, $consulta] = $this->cenario();
        $irmao = $this->cuidador($pessoa, CaregiverAccess::Edit, 'Irmão Paulo');

        $this->entrar($irmao, $pessoa);
        Livewire::test(HealthAppointmentIndex::class)->call('takeEscort', $consulta->id);
        $this->get(route('health.appointments.index'))->assertOk()->assertSee('Quem leva:')->assertSee('você')->assertSee('Não posso mais');

        app(ProfileContext::class)->clear();
        app(HealthAppointmentService::class)->notifyUpcoming(CarbonImmutable::now(), 1);

        Notification::assertSentTo($irmao, HealthAppointmentUpcoming::class, fn ($n) => $n->escortUserId === $irmao->id && $n->escortName === 'Irmão Paulo');
        Notification::assertSentTo($dono, HealthAppointmentUpcoming::class, fn ($n) => $n->escortName === 'Irmão Paulo' && $n->escortUserId !== $dono->id);
    }

    public function test_quem_leva_so_existe_em_pessoa_acompanhada(): void
    {
        $user = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $user->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $user->id, 'role' => MemberRole::Primary]);

        $this->actingAs($user);
        app(ProfileContext::class)->set($perfil, $membro);
        $consulta = app(HealthAppointmentService::class)->create($membro, ['kind' => HealthAppointmentKind::Consultation, 'title' => 'Dentista', 'scheduled_at' => CarbonImmutable::now()->addDay()], $membro);

        $this->get(route('health.appointments.index'))->assertOk()->assertDontSee('Quem leva:');

        Livewire::test(HealthAppointmentIndex::class)->call('takeEscort', $consulta->id)->assertStatus(404);
        $this->assertNull($consulta->fresh()->escort_user_id);
    }

    public function test_cada_cuidador_escolhe_quais_avisos_recebe(): void
    {
        Notification::fake();
        [$dono, $pessoa, $consulta] = $this->cenario();
        $soConsultas = $this->cuidador($pessoa, CaregiverAccess::Read);
        $soItens = $this->cuidador($pessoa, CaregiverAccess::Read);
        ProfileCaregiver::query()->where('user_id', $soConsultas->id)->update(['notify_care_items' => false]);
        ProfileCaregiver::query()->where('user_id', $soItens->id)->update(['notify_reminders' => false]);

        $membro = ProfileMember::query()->where('profile_id', $pessoa->id)->sole();
        app(ProfileContext::class)->set($pessoa, null);
        $this->actingAs($dono);
        HealthCareItem::create([
            'member_id' => $membro->id, 'category' => 'hearing_aid', 'name' => 'Filtro do aparelho', 'interval_value' => 15, 'interval_unit' => 'day',
            'last_done_on' => now()->subDays(14)->toDateString(), 'next_due_on' => now()->addDay()->toDateString(), 'is_active' => true,
        ]);
        app(ProfileContext::class)->clear();

        app(HealthAppointmentService::class)->notifyUpcoming(CarbonImmutable::now(), 1);
        app(\App\Services\HealthCareItemService::class)->notifyDue(CarbonImmutable::today(), 1);

        Notification::assertSentTo($soConsultas, HealthAppointmentUpcoming::class);
        Notification::assertNotSentTo($soConsultas, HealthCareItemDue::class);
        Notification::assertSentTo($soItens, HealthCareItemDue::class);
        Notification::assertNotSentTo($soItens, HealthAppointmentUpcoming::class);
    }

    public function test_o_cuidador_liga_e_desliga_cada_tipo_de_aviso_pela_tela(): void
    {
        [, $pessoa] = $this->cenario();
        $cuidador = $this->cuidador($pessoa, CaregiverAccess::Read);
        $vinculo = ProfileCaregiver::query()->where('user_id', $cuidador->id)->sole();

        $this->actingAs($cuidador);
        Livewire::test(CareIndex::class)->call('toggleNotify', $vinculo->id)->call('toggleNotifyItems', $vinculo->id);

        $this->assertFalse($vinculo->fresh()->notify_reminders);
        $this->assertFalse($vinculo->fresh()->notify_care_items);
    }

    // ----------------------------------------------------------------------

    /** @return array{0: User, 1: FinancialProfile, 2: HealthAppointment} */
    private function cenario(): array
    {
        $dono = User::factory()->create(['name' => 'Ana Dona']);
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $dono->id]);
        ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $dono->id, 'role' => MemberRole::Primary]);
        Subscription::create([
            'user_id' => $dono->id, 'kind' => SubscriptionKind::Direct, 'bundle' => SubscriptionBundle::Completo, 'status' => SubscriptionStatus::Active,
            'current_period_ends_at' => now()->addMonth(), 'started_at' => now(),
        ]);

        $pessoa = app(CareProfileService::class)->create($dono, 'Maria Souza', null, CareProfileService::BASIS_AUTHORIZED);
        $membro = ProfileMember::query()->where('profile_id', $pessoa->id)->sole();

        app(ProfileContext::class)->set($pessoa, null);
        $this->actingAs($dono);
        $consulta = app(HealthAppointmentService::class)->create($membro, ['kind' => HealthAppointmentKind::Consultation, 'title' => 'Cardiologista', 'scheduled_at' => CarbonImmutable::now()->addDay()->setTime(10, 0)], null);
        app(ProfileContext::class)->clear();

        return [$dono, $pessoa, $consulta];
    }

    private function cuidador(FinancialProfile $pessoa, CaregiverAccess $nivel, string $nome = 'Cuidador'): User
    {
        $user = User::factory()->create(['name' => $nome]);
        ProfileCaregiver::query()->create([
            'profile_id' => $pessoa->id, 'user_id' => $user->id, 'invited_email' => $user->email, 'invited_name' => $nome,
            'access_level' => $nivel, 'status' => CaregiverStatus::Active, 'accepted_at' => now(),
        ]);

        return $user;
    }

    /** Monta o contexto como o SetProfileContext faria (dono ou cuidador). */
    private function entrar(User $usuario, FinancialProfile $pessoa): void
    {
        $this->actingAs($usuario);
        $cuidador = ProfileCaregiver::query()->active()->where('profile_id', $pessoa->id)->where('user_id', $usuario->id)->first();
        app(ProfileContext::class)->set($pessoa, null, false, $cuidador);
        session()->put(\App\Http\Middleware\SetProfileContext::SESSION_KEY, $pessoa->id);
    }
}
