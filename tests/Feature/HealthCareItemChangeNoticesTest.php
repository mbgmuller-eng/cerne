<?php

namespace Tests\Feature;

use App\Enums\CaregiverAccess;
use App\Enums\CaregiverStatus;
use App\Enums\HealthCareCategory;
use App\Enums\HealthCareIntervalUnit;
use App\Enums\MemberRole;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Models\FinancialProfile;
use App\Models\HealthCareItem;
use App\Models\ProfileCaregiver;
use App\Models\ProfileMember;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\HealthCareItemChanged;
use App\Services\CareProfileService;
use App\Services\HealthCareItemService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Aviso a quem acompanha a pessoa quando um item de cuidado muda: cadastrado, alterado, troca registrada, pausado, retomado ou removido.
 * Quem fez a mudança não recebe o próprio aviso, e vale a preferência "troca de itens de cuidado" de cada cuidador.
 */
class HealthCareItemChangeNoticesTest extends TestCase
{
    use RefreshDatabase;

    private User $dono;

    private FinancialProfile $pessoa;

    private ProfileMember $membro;

    private User $editor;

    private User $leitor;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cerne.care.enabled' => true]);

        $this->dono = User::factory()->create(['name' => 'Ana Dona']);
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->dono->id]);
        ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $this->dono->id, 'role' => MemberRole::Primary]);
        Subscription::create([
            'user_id' => $this->dono->id, 'kind' => SubscriptionKind::Direct, 'bundle' => SubscriptionBundle::Completo, 'status' => SubscriptionStatus::Active,
            'current_period_ends_at' => now()->addMonth(), 'started_at' => now(),
        ]);

        $this->pessoa = app(CareProfileService::class)->create($this->dono, 'Maria Souza', null, CareProfileService::BASIS_AUTHORIZED);
        $this->membro = ProfileMember::query()->where('profile_id', $this->pessoa->id)->sole();
        $this->editor = $this->cuidador('Irmão Paulo', CaregiverAccess::Edit);
        $this->leitor = $this->cuidador('Tia Ana', CaregiverAccess::Read);
    }

    public function test_cadastrar_avisa_os_outros_e_nao_quem_cadastrou(): void
    {
        Notification::fake();
        $this->como($this->editor);

        $this->item('Filtro do aparelho');

        Notification::assertSentTo($this->dono, HealthCareItemChanged::class, fn ($n) => $n->event === 'created' && str_contains($n->message, 'Irmão Paulo cadastrou Filtro do aparelho (Phonak) de Maria Souza'));
        Notification::assertSentTo($this->leitor, HealthCareItemChanged::class, fn ($n) => $n->event === 'created');
        Notification::assertNotSentTo($this->editor, HealthCareItemChanged::class);
    }

    public function test_registrar_a_troca_diz_quando_foi_e_a_proxima(): void
    {
        $this->como($this->dono);
        $item = $this->item('Palmilha');
        Notification::fake();

        $this->como($this->editor);
        app(HealthCareItemService::class)->markDone($item->fresh(), CarbonImmutable::parse('2026-10-20'));

        Notification::assertSentTo($this->dono, HealthCareItemChanged::class, fn ($n) => $n->event === 'done'
            && str_contains($n->message, 'Irmão Paulo registrou a troca de Palmilha (Phonak) de Maria Souza em 20/10/2026')
            && str_contains($n->message, 'Próxima em 20/01/2027'));
        Notification::assertNotSentTo($this->editor, HealthCareItemChanged::class);
    }

    public function test_alterar_so_avisa_quando_algo_que_importa_muda(): void
    {
        $this->como($this->dono);
        $item = $this->item('Palmilha');
        Notification::fake();

        $service = app(HealthCareItemService::class);
        $base = [
            'category' => HealthCareCategory::Orthotic, 'name' => 'Palmilha', 'device_name' => 'Phonak',
            'interval_value' => 3, 'interval_unit' => HealthCareIntervalUnit::Month, 'last_done_on' => $item->last_done_on,
        ];

        // Só a anotação muda: não é novidade para quem acompanha.
        $service->update($item->fresh(), $base + ['notes' => 'Comprar na ótica']);
        Notification::assertNothingSent();

        $service->update($item->fresh(), ['interval_value' => 6] + $base);

        Notification::assertSentTo($this->editor, HealthCareItemChanged::class, fn ($n) => $n->event === 'updated'
            && str_contains($n->message, 'Ana Dona alterou Palmilha (Phonak) de Maria Souza')
            && str_contains($n->message, 'frequência'));
        Notification::assertNotSentTo($this->dono, HealthCareItemChanged::class);
    }

    public function test_pausar_retomar_e_remover_avisam(): void
    {
        $this->como($this->dono);
        $item = $this->item('Óculos');
        Notification::fake();

        $service = app(HealthCareItemService::class);
        $service->setActive($item->fresh(), false);
        Notification::assertSentTo($this->editor, HealthCareItemChanged::class, fn ($n) => $n->event === 'paused' && str_contains($n->message, 'Sem lembretes'));

        // Pausar o que já está pausado não é novidade.
        Notification::fake();
        $service->setActive($item->fresh(), false);
        Notification::assertNothingSent();

        $service->setActive($item->fresh(), true);
        Notification::assertSentTo($this->editor, HealthCareItemChanged::class, fn ($n) => $n->event === 'resumed');

        Notification::fake();
        $service->delete($item->fresh());
        Notification::assertSentTo($this->leitor, HealthCareItemChanged::class, fn ($n) => $n->event === 'removed' && str_contains($n->message, 'Ana Dona removeu Óculos'));
        $this->assertDatabaseMissing('health_care_items', ['id' => $item->id]);
    }

    public function test_cuidador_que_desligou_o_aviso_de_itens_nao_recebe_mas_o_de_consultas_nao_interfere(): void
    {
        Notification::fake();
        ProfileCaregiver::query()->where('user_id', $this->leitor->id)->update(['notify_care_items' => false]);
        $this->como($this->dono);

        $this->item('Filtro');

        Notification::assertNotSentTo($this->leitor, HealthCareItemChanged::class);
        Notification::assertSentTo($this->editor, HealthCareItemChanged::class);

        // E quem desligou só as consultas continua sabendo dos itens.
        Notification::fake();
        ProfileCaregiver::query()->where('user_id', $this->leitor->id)->update(['notify_care_items' => true, 'notify_reminders' => false]);
        $this->item('Outro filtro');
        Notification::assertSentTo($this->leitor, HealthCareItemChanged::class);
    }

    public function test_num_casal_avisa_quem_acompanha_a_pessoa_mas_nao_o_conjuge(): void
    {
        Notification::fake();
        $marcelo = User::factory()->create(['name' => 'Marcelo']);
        $helena = User::factory()->create(['name' => 'Helena']);
        $perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $marcelo->id]);
        $membroMarcelo = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $marcelo->id, 'name' => 'Marcelo']);
        ProfileMember::factory()->secondary()->create(['profile_id' => $perfil->id, 'user_id' => $helena->id, 'name' => 'Helena']);
        $paulo = User::factory()->create(['name' => 'Paulo']);
        ProfileCaregiver::query()->create(['profile_id' => $perfil->id, 'member_id' => $membroMarcelo->id, 'user_id' => $paulo->id, 'invited_email' => $paulo->email, 'invited_name' => 'Paulo', 'access_level' => CaregiverAccess::Read, 'status' => CaregiverStatus::Active]);

        app(ProfileContext::class)->set($perfil, $membroMarcelo);
        $this->actingAs($marcelo);
        app(HealthCareItemService::class)->create($membroMarcelo, [
            'category' => HealthCareCategory::Eyewear, 'name' => 'Lente', 'interval_value' => 1, 'interval_unit' => HealthCareIntervalUnit::Year,
        ], $membroMarcelo);

        Notification::assertSentTo($paulo, HealthCareItemChanged::class, fn ($n) => $n->event === 'created');
        Notification::assertNotSentTo($helena, HealthCareItemChanged::class);
        Notification::assertNotSentTo($marcelo, HealthCareItemChanged::class);
    }

    public function test_sem_o_consentimento_de_saude_de_quem_e_acompanhado_ninguem_e_avisado(): void
    {
        $marcelo = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $marcelo->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $marcelo->id]);
        $paulo = User::factory()->create();
        ProfileCaregiver::query()->create(['profile_id' => $perfil->id, 'member_id' => $membro->id, 'user_id' => $paulo->id, 'invited_email' => $paulo->email, 'invited_name' => 'Paulo', 'access_level' => CaregiverAccess::Read, 'status' => CaregiverStatus::Active]);
        $marcelo->revokeHealthConsent();

        Notification::fake();
        $outro = User::factory()->create();
        app(ProfileContext::class)->set($perfil, $membro);
        $this->actingAs($outro);
        app(HealthCareItemService::class)->create($membro, [
            'category' => HealthCareCategory::Other, 'name' => 'X', 'interval_value' => 1, 'interval_unit' => HealthCareIntervalUnit::Month,
        ], $membro);

        Notification::assertNothingSent();
    }

    // ----------------------------------------------------------------------

    private function cuidador(string $nome, CaregiverAccess $nivel): User
    {
        $user = User::factory()->create(['name' => $nome]);
        ProfileCaregiver::query()->create([
            'profile_id' => $this->pessoa->id, 'member_id' => $this->membro->id, 'user_id' => $user->id, 'invited_email' => $user->email,
            'invited_name' => $nome, 'access_level' => $nivel, 'status' => CaregiverStatus::Active, 'accepted_at' => now(),
        ]);

        return $user;
    }

    /** Monta o contexto da pessoa acompanhada como quem está agindo (dono ou cuidador). */
    private function como(User $usuario): void
    {
        $this->actingAs($usuario);
        $cuidador = ProfileCaregiver::query()->active()->where('profile_id', $this->pessoa->id)->where('user_id', $usuario->id)->first();
        app(ProfileContext::class)->set($this->pessoa, null, false, $cuidador);
    }

    private function item(string $nome): HealthCareItem
    {
        return app(HealthCareItemService::class)->create($this->membro, [
            'category' => HealthCareCategory::Orthotic, 'name' => $nome, 'device_name' => 'Phonak',
            'interval_value' => 3, 'interval_unit' => HealthCareIntervalUnit::Month,
        ], null);
    }
}
